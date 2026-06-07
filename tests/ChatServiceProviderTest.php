<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\IdentityResolver;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandler as ChatHandlerContract;
use BootDesk\ChatSDK\Laravel\HandlerRegistry;
use BootDesk\ChatSDK\Laravel\State\CacheStateAdapter;
use Orchestra\Testbench\TestCase;

class ChatServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('chat.user_name', 'TestBot');
    }

    public function test_chat_factory_is_registered(): void
    {
        $factory = $this->app->make(ChatFactory::class);
        $this->assertInstanceOf(ChatFactory::class, $factory);
    }

    public function test_handler_registry_is_registered(): void
    {
        $registry = $this->app->make(HandlerRegistry::class);
        $this->assertInstanceOf(HandlerRegistry::class, $registry);
    }

    public function test_state_adapter_is_cache_state_adapter(): void
    {
        $state = $this->app->make(StateAdapter::class);
        $this->assertInstanceOf(CacheStateAdapter::class, $state);
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
        $chat = $this->app->make(ChatFactory::class)->forGroup('slack');
        $this->assertNull($chat->resolveAdapter('slack'));
    }

    public function test_identity_binding(): void
    {
        $this->app->bind(IdentityResolver::class, fn () => new class implements IdentityResolver
        {
            public function resolve(Author $author): ?string
            {
                return $author->id;
            }
        });
        $chat = $this->app->make(ChatFactory::class)->forGroup('slack');
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

    public function test_global_handler_registration(): void
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

        $provider = $this->app->getProvider(ChatServiceProvider::class);
        $provider->boot();

        $chat = $this->app->make(ChatFactory::class)->default();
        $this->assertTrue($handler->registered);
    }

    public function test_group_handler_registration(): void
    {
        $slackHandler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $globalHandler = new class implements ChatHandlerContract
        {
            public bool $registered = false;

            public function register(Chat $chat): void
            {
                $this->registered = true;
            }
        };

        $slackClass = get_class($slackHandler);
        $globalClass = get_class($globalHandler);

        $this->app->instance($slackClass, $slackHandler);
        $this->app->instance($globalClass, $globalHandler);

        $this->app['config']->set('chat.handlers', [$globalClass]);
        $this->app['config']->set('chat.handler_groups', ['slack' => [$slackClass]]);
        $this->app->register(ChatServiceProvider::class);

        $provider = $this->app->getProvider(ChatServiceProvider::class);
        $provider->boot();

        $slackChat = $this->app->make(ChatFactory::class)->forGroup('slack');
        $this->assertTrue($slackHandler->registered);
        $this->assertTrue($globalHandler->registered);

        // Reset flags
        $slackHandler->registered = false;
        $globalHandler->registered = false;

        // Telegram group should only get global handlers
        $telegramChat = $this->app->make(ChatFactory::class)->forGroup('telegram');
        $this->assertFalse($slackHandler->registered);
        $this->assertTrue($globalHandler->registered);
    }
}
