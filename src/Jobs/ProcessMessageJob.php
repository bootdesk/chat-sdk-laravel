<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Jobs;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class ProcessMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $adapterName,
        private readonly string $threadId,
        private readonly Message $message,
    ) {}

    public function handle(Chat $chat): void
    {
        $adapter = $chat->resolveAdapter($this->adapterName);

        if (! $adapter instanceof Adapter) {
            return;
        }

        $chat->processMessage($adapter, $this->threadId, $this->message);
    }
}
