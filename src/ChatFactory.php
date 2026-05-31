<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\AdapterResolver;
use BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter;
use BootDesk\ChatSDK\Core\Contracts\ConcurrencyHandler;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandler as ChatHandlerContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseFactoryInterface;

class ChatFactory
{
    private ?array $cachedAdapters = null;

    public function __construct(
        private readonly Application $app,
        private readonly HandlerRegistry $handlerRegistry,
        private readonly StateAdapter $state,
        private readonly ConcurrencyHandler $concurrency,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {}

    public function forGroup(string $group): Chat
    {
        $chat = $this->baseChat();

        foreach ($this->handlerRegistry->forGroup($group) as $handlerClass) {
            $this->registerHandler($chat, $handlerClass);
        }

        return $chat;
    }

    public function default(): Chat
    {
        $chat = $this->baseChat();

        foreach ($this->handlerRegistry->forGroup(null) as $handlerClass) {
            $this->registerHandler($chat, $handlerClass);
        }

        return $chat;
    }

    private function baseChat(): Chat
    {
        $identity = null;
        if ($this->app->bound('chat.identity')) {
            $identity = $this->app->make('chat.identity');
        }

        $broadcaster = null;
        if (config('chat-broadcasting.enabled', false) && $this->app->bound(BroadcastAdapter::class)) {
            $broadcaster = $this->app->make(BroadcastAdapter::class);
        }

        return new Chat(
            state: $this->state,
            adapters: $this->getAdapters(),
            config: [
                'logger' => $this->app->bound('log') ? $this->app->make('log') : null,
            ],
            adapterResolver: $this->app->bound(AdapterResolver::class) ?
                $this->app->make(AdapterResolver::class) : null,
            responseFactory: $this->responseFactory,
            identity: $identity,
            transcripts: config('chat.transcripts'),
            broadcaster: $broadcaster,
            concurrencyHandler: $this->concurrency,
        );
    }

    private function getAdapters(): array
    {
        if ($this->cachedAdapters !== null) {
            return $this->cachedAdapters;
        }

        $adapters = [];
        $configured = config('chat.adapters', []);
        $fileUploadConverter = $this->app->bound(FileUploadConverter::class)
            ? $this->app->make(FileUploadConverter::class)
            : new NullFileUploadConverter;

        foreach ($configured as $name => $adapterConfig) {
            $class = AdapterRegistry::get($name);

            if ($class !== null && class_exists($class)) {
                $normalized = [];
                foreach (($adapterConfig ?? []) as $key => $value) {
                    $normalized[Str::camel($key)] = $value;
                }
                $normalized['fileUploadConverter'] = $fileUploadConverter;
                $adapters[$name] = $this->app->make($class, $normalized);
            }
        }

        $this->cachedAdapters = $adapters;

        return $adapters;
    }

    private function registerHandler(Chat $chat, string $handlerClass): void
    {
        if (! class_exists($handlerClass)) {
            return;
        }

        $handler = $this->app->make($handlerClass);

        if ($handler instanceof ChatHandlerContract) {
            $handler->register($chat);
        }
    }
}
