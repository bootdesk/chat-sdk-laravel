<?php

namespace BootDesk\ChatSDK\Laravel\Notifications;

class ChatRoute
{
    private function __construct(
        public readonly ?string $threadId = null,
        public readonly ?string $channelId = null,
        public readonly ?string $userId = null,
    ) {}

    public static function thread(string $threadId): self
    {
        return new self(threadId: $threadId);
    }

    public static function channel(string $channelId): self
    {
        return new self(channelId: $channelId);
    }

    public static function dm(string $userId): self
    {
        return new self(userId: $userId);
    }
}
