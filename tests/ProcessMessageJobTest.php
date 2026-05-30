<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\AdapterResolver;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessMessageJob;
use BootDesk\ChatSDK\Laravel\Jobs\RequestContext;
use BootDesk\ChatSDK\Laravel\Tests\Helpers\TestSyncAdapter;
use Nyholm\Psr7\ServerRequest;
use Orchestra\Testbench\TestCase;
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
    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    public function test_job_processes_message(): void
    {
        $chat = $this->app->make(Chat::class);
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
        $job->handle($chat);

        $this->assertFalse($called);
    }

    public function test_job_handles_unknown_adapter(): void
    {
        $chat = $this->app->make(Chat::class);
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
        // Should not throw — just returns when adapter not found
        $job->handle($chat);

        $this->assertFalse($called);
    }

    public function test_job_calls_process_message_in_job(): void
    {
        $chat = $this->app->make(Chat::class);
        $message = new Message(
            id: 'job_inline',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('unknown', 'unknown:channel', $message);
        // Should not throw — processMessageInJob resolves adapter, returns early if not found
        $job->handle($chat);

        $this->expectNotToPerformAssertions();
    }

    public function test_job_passes_skipped_messages_to_handler(): void
    {
        $chat = $this->app->make(Chat::class);
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
        $job->handle($chat);

        $this->expectNotToPerformAssertions();
    }

    public function test_job_passes_request_context_to_adapter_resolver(): void
    {
        $resolver = new TestResolverSpy;

        // Chat singleton is already resolved during provider boot. Forget so
        // our resolver is injected on re-resolution.
        $this->app->instance(AdapterResolver::class, $resolver);
        $this->app->forgetInstance(Chat::class);

        $chat = $this->app->make(Chat::class);

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
        $job->handle($chat);

        $this->assertNotNull($resolver->resolvedRequest);
        $this->assertSame('POST', $resolver->resolvedRequest->getMethod());
        $this->assertSame('/hook', (string) $resolver->resolvedRequest->getUri());
        $this->assertSame(['acme'], $resolver->resolvedRequest->getHeader('X-Tenant'));
        $this->assertSame('{"event":"test"}', (string) $resolver->resolvedRequest->getBody());
    }

    public function test_job_passes_null_request_when_no_context(): void
    {
        $resolver = new TestResolverSpy;

        $this->app->instance(AdapterResolver::class, $resolver);
        $this->app->forgetInstance(Chat::class);

        $chat = $this->app->make(Chat::class);

        $message = new Message(
            id: 'no_ctx',
            threadId: 'test:ch:th',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('test-bar', 'test:ch:th', $message);
        $job->handle($chat);

        $this->assertNull($resolver->resolvedRequest);
    }
}
