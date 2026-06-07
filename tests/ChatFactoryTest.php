<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandler as ChatHandlerContract;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandlerWithRequest;
use BootDesk\ChatSDK\Laravel\HandlerRegistry;
use Nyholm\Psr7\ServerRequest;
use Orchestra\Testbench\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class ChatFactoryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    public function test_for_groups_merges_handlers_from_multiple_groups(): void
    {
        $groupAHandler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $groupBHandler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $this->app->instance(get_class($groupAHandler), $groupAHandler);
        $this->app->instance(get_class($groupBHandler), $groupBHandler);

        $this->app['config']->set('chat.handler_groups', [
            'group_a' => [get_class($groupAHandler)],
            'group_b' => [get_class($groupBHandler)],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $chat = $this->app->make(ChatFactory::class)->forGroups(['group_a', 'group_b']);

        $this->assertTrue($groupAHandler->registered);
        $this->assertTrue($groupBHandler->registered);
    }

    public function test_for_groups_single_group_equals_for_group(): void
    {
        $handler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $this->app->instance(get_class($handler), $handler);

        $this->app['config']->set('chat.handler_groups', [
            'test' => [get_class($handler)],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $chat = $this->app->make(ChatFactory::class)->forGroups(['test']);

        $this->assertTrue($handler->registered);
    }

    public function test_for_groups_empty_array_registers_only_globals(): void
    {
        $globalHandler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $groupHandler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $this->app->instance(get_class($globalHandler), $globalHandler);
        $this->app->instance(get_class($groupHandler), $groupHandler);

        $this->app['config']->set('chat.handlers', [get_class($globalHandler)]);
        $this->app['config']->set('chat.handler_groups', [
            'some_group' => [get_class($groupHandler)],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $chat = $this->app->make(ChatFactory::class)->forGroups([]);

        $this->assertTrue($globalHandler->registered);
        $this->assertFalse($groupHandler->registered);
    }

    public function test_for_group_still_works(): void
    {
        $handler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $this->app->instance(get_class($handler), $handler);

        $this->app['config']->set('chat.handler_groups', [
            'legacy' => [get_class($handler)],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $chat = $this->app->make(ChatFactory::class)->forGroup('legacy');

        $this->assertTrue($handler->registered);
    }

    public function test_chat_handler_with_request_receives_request(): void
    {
        $handler = new class implements ChatHandlerWithRequest
        {
            public ?ServerRequestInterface $receivedRequest = null;

            public function register(Chat $chat, ?ServerRequestInterface $request = null): void
            {
                $this->receivedRequest = $request;
            }
        };

        $this->app->instance(get_class($handler), $handler);

        $this->app['config']->set('chat.handler_groups', [
            'test' => [get_class($handler)],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $request = new ServerRequest('POST', '/hook', ['X-Tenant' => ['acme']], '{"event":"test"}');
        $chat = $this->app->make(ChatFactory::class)->forGroups(['test'], $request);

        $this->assertSame($request, $handler->receivedRequest);
    }

    public function test_chat_handler_without_request_does_not_break(): void
    {
        $handler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $this->app->instance(get_class($handler), $handler);

        $this->app['config']->set('chat.handler_groups', [
            'test' => [get_class($handler)],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $request = new ServerRequest('POST', '/hook');
        $chat = $this->app->make(ChatFactory::class)->forGroups(['test'], $request);

        $this->assertTrue($handler->registered);
    }

    public function test_for_group_passes_request_to_handler_with_request(): void
    {
        $handler = new class implements ChatHandlerWithRequest
        {
            public ?ServerRequestInterface $receivedRequest = null;

            public function register(Chat $chat, ?ServerRequestInterface $request = null): void
            {
                $this->receivedRequest = $request;
            }
        };

        $this->app->instance(get_class($handler), $handler);

        $this->app['config']->set('chat.handler_groups', [
            'legacy' => [get_class($handler)],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $request = new ServerRequest('POST', '/hook');
        $chat = $this->app->make(ChatFactory::class)->forGroup('legacy', $request);

        $this->assertSame($request, $handler->receivedRequest);
    }

    public function test_default_passes_request_to_handler_with_request(): void
    {
        $handler = new class implements ChatHandlerWithRequest
        {
            public ?ServerRequestInterface $receivedRequest = null;

            public function register(Chat $chat, ?ServerRequestInterface $request = null): void
            {
                $this->receivedRequest = $request;
            }
        };

        $this->app->instance(get_class($handler), $handler);

        $this->app['config']->set('chat.handlers', [get_class($handler)]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $request = new ServerRequest('POST', '/hook');
        $chat = $this->app->make(ChatFactory::class)->default($request);

        $this->assertSame($request, $handler->receivedRequest);
    }
}
