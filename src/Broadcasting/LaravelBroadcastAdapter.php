<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Broadcasting;

use BootDesk\ChatSDK\Core\Broadcasting\BroadcastEvent;
use BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;

class LaravelBroadcastAdapter implements BroadcastAdapter
{
    protected ?Broadcaster $broadcaster = null;

    protected bool $connected = false;

    public function __construct(
        protected readonly BroadcastManager $broadcastManager,
        protected string $broadcasterType = 'pusher',
        protected string $channelPrefix = 'chat',
        protected string $threadChannelType = 'public',
        protected string $userChannelType = 'private',
    ) {}

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->broadcaster = $this->broadcastManager->connection($this->broadcasterType);
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
        $this->broadcaster = null;
    }

    public function broadcast(string $threadId, BroadcastEvent $event, array $options = []): void
    {
        $this->ensureConnected();

        $channel = match ($this->threadChannelType) {
            'presence' => $this->buildPresenceChannelForThread($threadId),
            'private' => $this->buildPrivateChannelForThread($threadId),
            default => $this->buildChannel($threadId),
        };
        $eventName = $this->buildEventName($event->type);

        $this->broadcaster->broadcast(
            [$channel],
            $eventName,
            $event->toArray()
        );
    }

    public function broadcastToUser(string $threadId, string|array $userIds, BroadcastEvent $event, array $options = []): void
    {
        $this->ensureConnected();

        $userIds = is_array($userIds) ? $userIds : [$userIds];

        foreach ($userIds as $userId) {
            $channel = match ($this->userChannelType) {
                'presence' => $this->buildPresenceChannel($threadId, $userId),
                default => $this->buildPrivateChannel($threadId, $userId),
            };
            $eventName = $this->buildEventName($event->type);

            $this->broadcaster->broadcast(
                [$channel],
                $eventName,
                $event->toArray()
            );
        }
    }

    public function isBroadcastingAvailable(string $threadId): bool
    {
        return $this->connected && $this->broadcaster instanceof Broadcaster;
    }

    protected function ensureConnected(): void
    {
        if (! $this->connected) {
            throw new \RuntimeException('LaravelBroadcastAdapter is not connected. Call connect() first.');
        }
    }

    protected function buildChannelName(string $threadId): string
    {
        return "{$this->channelPrefix}.{$threadId}";
    }

    protected function buildChannel(string $threadId): Channel
    {
        return new Channel($this->buildChannelName($threadId));
    }

    protected function buildPrivateChannelForThread(string $threadId): PrivateChannel
    {
        return new PrivateChannel($this->buildChannelName($threadId));
    }

    protected function buildPresenceChannelForThread(string $threadId): PresenceChannel
    {
        return new PresenceChannel($this->buildChannelName($threadId));
    }

    protected function buildPrivateChannelName(string $threadId, string $userId): string
    {
        return "{$this->channelPrefix}.{$threadId}.{$userId}";
    }

    protected function buildPrivateChannel(string $threadId, string $userId): PrivateChannel
    {
        return new PrivateChannel($this->buildPrivateChannelName($threadId, $userId));
    }

    protected function buildPresenceChannel(string $threadId, string $userId): PresenceChannel
    {
        return new PresenceChannel($this->buildPrivateChannelName($threadId, $userId));
    }

    protected function buildEventName(string $eventType): string
    {
        return "{$this->channelPrefix}.{$eventType}";
    }
}
