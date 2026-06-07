<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\AdapterResolver;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\Tests\Helpers\MemoryStateAdapter;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessMessageJob;
use BootDesk\ChatSDK\Laravel\Jobs\RequestContext;
use BootDesk\ChatSDK\Laravel\Tests\Helpers\TestSyncAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class TestResolverSpy implements AdapterResolver
{
    public mixed $resolvedRequest = 'not_called';

    public function resolve(string $name, ?ServerRequestInterface $request): ?Adapter
    {
        $this->resolvedRequest = $request;

        return new TestSyncAdapter;
    }
}

class ProcessMessageJobTest extends TestCase
{
    private function makeChat(?AdapterResolver $resolver = null): Chat
    {
        return new Chat(
            state: new MemoryStateAdapter,
            responseFactory: new Psr17Factory,
            adapterResolver: $resolver,
        );
    }

    private function mockChatFactory(Chat $chat): ChatFactory
    {
        $factory = $this->createMock(ChatFactory::class);
        $factory->method('forGroups')->willReturn($chat);

        return $factory;
    }

    public function test_job_processes_message(): void
    {
        $chat = $this->makeChat();
        $called = false;
        $chat->onNewMessage('/.*/', function () use (&$called) {
            $called = true;
        });

        $message = new Message(
            id: 'job_msg',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('unknown', 'unknown:channel', $message);
        $job->handle($this->mockChatFactory($chat));

        $this->assertFalse($called);
    }

    public function test_job_handles_unknown_adapter(): void
    {
        $chat = $this->makeChat();
        $called = false;
        $chat->onNewMessage('/.*/', function () use (&$called) {
            $called = true;
        });

        $message = new Message(
            id: 'job_msg_2',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('nonexistent', 'unknown:channel', $message);
        $job->handle($this->mockChatFactory($chat));

        $this->assertFalse($called);
    }

    public function test_job_calls_process_message_in_job(): void
    {
        $chat = $this->makeChat();
        $message = new Message(
            id: 'job_inline',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('unknown', 'unknown:channel', $message);
        $job->handle($this->mockChatFactory($chat));

        $this->expectNotToPerformAssertions();
    }

    public function test_job_passes_skipped_messages_to_handler(): void
    {
        $chat = $this->makeChat();
        $skipped = [
            new Message(
                id: 'skipped_1',
                threadId: 'unknown:channel',
                author: new Author(id: 'U1', name: 'Test'),
                text: 'first',
            ),
        ];

        $message = new Message(
            id: 'final_msg',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'final',
        );

        $job = new ProcessMessageJob('unknown', 'unknown:channel', $message, $skipped, 2);
        $job->handle($this->mockChatFactory($chat));

        $this->expectNotToPerformAssertions();
    }

    public function test_job_passes_request_context_to_adapter_resolver(): void
    {
        $resolver = new TestResolverSpy;

        $chat = $this->makeChat($resolver);
        $chat->registerAdapter('test-foo', new TestSyncAdapter);

        $context = RequestContext::fromServerRequest(
            new ServerRequest('POST', '/hook', ['X-Tenant' => ['acme']], '{"event":"test"}'),
        );

        $message = new Message(
            id: 'ctx_test',
            threadId: 'test:ch:th',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('test-foo', 'test:ch:th', $message, requestContext: $context);
        $job->handle($this->mockChatFactory($chat));

        $this->assertNotNull($resolver->resolvedRequest);
        $this->assertSame('POST', $resolver->resolvedRequest->getMethod());
        $this->assertSame('/hook', (string) $resolver->resolvedRequest->getUri());
        $this->assertSame(['acme'], $resolver->resolvedRequest->getHeader('X-Tenant'));
        $this->assertSame('{"event":"test"}', (string) $resolver->resolvedRequest->getBody());
    }

    public function test_job_passes_null_request_when_no_context(): void
    {
        $resolver = new TestResolverSpy;

        $chat = $this->makeChat($resolver);
        $chat->registerAdapter('test-bar', new TestSyncAdapter);

        $message = new Message(
            id: 'no_ctx',
            threadId: 'test:ch:th',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('test-bar', 'test:ch:th', $message);
        $job->handle($this->mockChatFactory($chat));

        $this->assertNull($resolver->resolvedRequest);
    }

    public function test_job_uses_groups_from_request_attribute(): void
    {
        $chat = $this->makeChat();

        $factory = $this->createMock(ChatFactory::class);
        $factory->expects($this->once())
            ->method('forGroups')
            ->with(['custom-group', 'another-group'])
            ->willReturn($chat);

        $request = (new ServerRequest('POST', '/hook'))
            ->withAttribute('chat_groups', ['custom-group', 'another-group']);
        $context = RequestContext::fromServerRequest($request);

        $message = new Message(
            id: 'attr_groups',
            threadId: 'test:ch:th',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('test-adapter', 'test:ch:th', $message, requestContext: $context);
        $job->handle($factory);
    }

    public function test_job_falls_back_to_adapter_name_when_no_request_attribute(): void
    {
        $chat = $this->makeChat();

        $factory = $this->createMock(ChatFactory::class);
        $factory->expects($this->once())
            ->method('forGroups')
            ->with(['test-fallback'])
            ->willReturn($chat);

        $message = new Message(
            id: 'fallback_attr',
            threadId: 'test:ch:th',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('test-fallback', 'test:ch:th', $message);
        $job->handle($factory);
    }
}
