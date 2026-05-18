<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Laravel\ChatFacade;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandler as ChatHandlerContract;
use BootDesk\ChatSDK\Laravel\State\CacheStateAdapter;
use Orchestra\Testbench\TestCase;

class ChatServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Chat' => ChatFacade::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('chat.user_name', 'TestBot');
    }

    public function test_chat_singleton_is_registered(): void
    {
        $chat = $this->app->make(Chat::class);
        $this->assertInstanceOf(Chat::class, $chat);

        $chat2 = $this->app->make(Chat::class);
        $this->assertSame($chat, $chat2);
    }

    public function test_chat_alias_is_registered(): void
    {
        $this->assertTrue($this->app->isAlias('chat'));
    }

    public function test_state_adapter_is_cache_state_adapter(): void
    {
        $state = $this->app->make(StateAdapter::class);
        $this->assertInstanceOf(CacheStateAdapter::class, $state);
    }

    public function test_facade_resolves_chat(): void
    {
        $chat = ChatFacade::getFacadeRoot();
        $this->assertInstanceOf(Chat::class, $chat);
    }

    public function test_config_is_merged(): void
    {
        $this->assertSame('TestBot', config('chat.user_name'));
        $this->assertSame('drop', config('chat.concurrency'));
        $this->assertSame('thread', config('chat.lock_scope'));
    }

    public function test_adapters_empty_by_default(): void
    {
        $this->assertSame([], config('chat.adapters'));
        $this->assertNull($this->app->make(Chat::class)->resolveAdapter('slack'));
    }

    public function test_identity_binding(): void
    {
        $this->app->forgetInstance(Chat::class);
        $this->app->bind('chat.identity', fn () => fn (Author $a) => $a->id);
        $chat = $this->app->make(Chat::class);
        $this->assertNotNull($chat->resolveIdentity(new Author(id: 'U1')));
    }

    public function test_install_command_is_registered(): void
    {
        $this->artisan('chat:install')->assertSuccessful();
    }

    public function test_list_command_without_adapters(): void
    {
        $this->artisan('chat:list')->assertSuccessful();
    }

    public function test_list_command_with_adapters(): void
    {
        $this->app['config']->set('chat.adapters', [
            'slack' => ['token' => 'xoxb-test'],
        ]);

        $result = $this->artisan('chat:list');
        $result->assertSuccessful();

        $result->expectsOutputToContain('Registered globally');
    }

    public function test_handler_registration(): void
    {
        $handler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);
        $this->app['config']->set('chat.handlers', [$handlerClass]);
        $this->app->register(ChatServiceProvider::class);

        /**
         * @var ChatServiceProvider
         */
        $provider = $this->app->getProvider(ChatServiceProvider::class);
        $provider->boot();

        $this->assertTrue($handler->registered);
    }
}
