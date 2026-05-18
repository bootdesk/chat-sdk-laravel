<?php

namespace BootDesk\ChatSDK\Laravel;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\AdapterResolver;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Laravel\Commands\ChatInstallCommand;
use BootDesk\ChatSDK\Laravel\Commands\ChatListCommand;
use BootDesk\ChatSDK\Laravel\Commands\ChatMakeAdapterCommand;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandler as ChatHandlerContract;
use BootDesk\ChatSDK\Laravel\Notifications\ChatChannel;
use BootDesk\ChatSDK\Laravel\State\CacheStateAdapter;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chat.php', 'chat');

        $this->bindPsr17();
        $this->bindHttpClient();

        $this->app->singleton(StateAdapter::class, function (Application $app): CacheStateAdapter {
            return new CacheStateAdapter(
                cacheFactory: $app->make(CacheFactory::class),
                store: config('chat.state.store', 'file'),
                prefix: config('chat.state.prefix', 'chat:'),
            );
        });

        $this->app->singleton(Chat::class, function (Application $app): Chat {
            $identity = null;
            if ($app->bound('chat.identity')) {
                $identity = $app->make('chat.identity');
            }

            return new Chat(
                state: $app->make(StateAdapter::class),
                adapters: $this->resolveAdapters($app),
                config: [
                    'concurrency' => config('chat.concurrency', 'drop'),
                    'lock_scope' => config('chat.lock_scope', 'thread'),
                    'logger' => $app->bound('log') ? $app->make('log') : null,
                ],
                adapterResolver: $app->bound(AdapterResolver::class) ?
                    $app->make(AdapterResolver::class) : null,
                responseFactory: $app->make(ResponseFactoryInterface::class),
                identity: $identity,
                transcripts: config('chat.transcripts'),
            );
        });

        $this->app->alias(Chat::class, 'chat');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/chat.php' => config_path('chat.php'),
            ], 'chat-config');

            $this->commands([
                ChatListCommand::class,
                ChatInstallCommand::class,
                ChatMakeAdapterCommand::class,
            ]);
        }

        // Register handler classes from config
        $this->registerHandlers();

        // Register notification channel
        $this->app->bind(ChatChannel::class, function (Application $app): ChatChannel {
            return new ChatChannel($app->make(Chat::class));
        });
    }

    private function bindPsr17(): void
    {
        $this->app->bind(ResponseFactoryInterface::class, Psr17Factory::class);
        $this->app->bind(ServerRequestFactoryInterface::class, Psr17Factory::class);
        $this->app->bind(StreamFactoryInterface::class, Psr17Factory::class);
        $this->app->bind(UploadedFileFactoryInterface::class, Psr17Factory::class);
    }

    private function bindHttpClient(): void
    {
        $this->app->bind(ClientInterface::class, GuzzleClient::class);
    }

    private function resolveAdapters(Application $app): array
    {
        $adapters = [];
        $configured = config('chat.adapters', []);
        $fileUploadConverter = $app->bound(FileUploadConverter::class)
            ? $app->make(FileUploadConverter::class)
            : new NullFileUploadConverter;

        foreach ($configured as $name => $adapterConfig) {
            $class = $this->adapterClass($name);

            if ($class !== null && class_exists($class)) {
                $normalized = [];
                foreach (($adapterConfig ?? []) as $key => $value) {
                    $normalized[Str::camel($key)] = $value;
                }
                $normalized['fileUploadConverter'] = $fileUploadConverter;
                $adapters[$name] = $app->make($class, $normalized);
            }
        }

        return $adapters;
    }

    private function adapterClass(string $name): ?string
    {
        return AdapterRegistry::get($name);
    }

    private function registerHandlers(): void
    {
        $chat = $this->app->make(Chat::class);

        foreach (config('chat.handlers', []) as $handlerClass) {
            if (class_exists($handlerClass)) {
                $handler = $this->app->make($handlerClass);
                if ($handler instanceof ChatHandlerContract) {
                    $handler->register($chat);
                }
            }
        }
    }
}
