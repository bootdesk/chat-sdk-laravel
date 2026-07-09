<?php

namespace BootDesk\ChatSDK\Laravel;

use BootDesk\ChatSDK\Core\Contracts\ConcurrencyHandler;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Contracts\TranscriptsApi;
use BootDesk\ChatSDK\Core\Transcript\DefaultTranscriptsApi;
use BootDesk\ChatSDK\Laravel\Commands\ChatInstallCommand;
use BootDesk\ChatSDK\Laravel\Commands\ChatListCommand;
use BootDesk\ChatSDK\Laravel\Commands\ChatMakeAdapterCommand;
use BootDesk\ChatSDK\Laravel\Concurrency\QueueConcurrencyHandler;
use BootDesk\ChatSDK\Laravel\Notifications\ChatChannel;
use BootDesk\ChatSDK\Laravel\State\CacheStateAdapter;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Log\LoggerInterface;

class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/chat.php', 'chat');

        $this->bindPsr17();
        $this->bindHttpClient();

        $this->app->singleton(StateAdapter::class, function (Application $app): CacheStateAdapter {
            $logger = $this->resolveLogger($app);

            return new CacheStateAdapter(
                prefix: config('chat.state.prefix', 'chat:'),
                logger: $logger,
            );
        });

        $this->app->singleton(ConcurrencyHandler::class, function (Application $app): QueueConcurrencyHandler {
            $logger = $this->resolveLogger($app);

            return new QueueConcurrencyHandler(
                state: $app->make(StateAdapter::class),
                config: config('chat', []),
                logger: $logger,
            );
        });

        $this->app->singleton(TranscriptsApi::class, function (Application $app): DefaultTranscriptsApi {
            return new DefaultTranscriptsApi(
                state: $app->make(StateAdapter::class),
            );
        });

        $this->app->singleton(HandlerRegistry::class, function (Application $app): HandlerRegistry {
            $registry = new HandlerRegistry;

            foreach (config('chat.handlers', []) as $handlerClass) {
                $registry->addGlobal($handlerClass);
            }

            foreach (config('chat.handler_groups', []) as $group => $handlerClasses) {
                foreach ($handlerClasses as $handlerClass) {
                    $registry->add($group, $handlerClass);
                }
            }

            return $registry;
        });

        $this->app->singleton(ChatFactory::class, function (Application $app): ChatFactory {
            return new ChatFactory(
                app: $app,
                handlerRegistry: $app->make(HandlerRegistry::class),
            );
        });
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

        // Register notification channel
        $this->app->bind(ChatChannel::class, function (Application $app): ChatChannel {
            return new ChatChannel($app->make(ChatFactory::class));
        });

        // Shutdown lifecycle: disconnect adapters, broadcaster, and state
        $this->app->terminating(function (): void {
            $this->app->make(StateAdapter::class)->disconnect();
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

    private function resolveLogger(Application $app): ?LoggerInterface
    {
        if (! config('chat.logging.enabled', false)) {
            return null;
        }

        $channel = config('chat.logging.channel');
        if ($channel !== null) {
            return $app->make('log')->channel($channel);
        }

        return $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null;
    }
}
