<?php

namespace BootDesk\ChatSDK\Laravel\Notifications;

class ChatRoute
{
    private function __construct(
        public readonly ?string $threadId = null,
        public readonly ?string $channelId = null,
        public readonly ?string $adapter = null,
        public readonly ?string $userId = null,
    ) {}

    public static function thread(string $threadId): self
    {
        return new self(threadId: $threadId);
    }

    public static function channel(string $adapter, string $channelId): self
    {
        return new self(channelId: $channelId, adapter: $adapter);
    }

    public static function dm(string $adapter, string $userId): self
    {
        return new self(adapter: $adapter, userId: $userId);
    }
}
