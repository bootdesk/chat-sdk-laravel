<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Jobs;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Lock;
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
        private readonly array $skippedMessages = [],
        private readonly int $totalSinceLastHandler = 1,
        private readonly ?string $lockKey = null,
        private readonly ?string $lockToken = null,
        private readonly ?RequestContext $requestContext = null,
    ) {}

    public function handle(Chat $chat): void
    {
        $request = $this->requestContext?->toPsrRequest();
        $adapter = $chat->resolveAdapter($this->adapterName, $request);

        if (! $adapter instanceof Adapter) {
            $this->releaseLock($chat);

            return;
        }

        try {
            $chat->processMessageInJob(
                $adapter,
                $this->threadId,
                $this->message,
                $this->skippedMessages,
                $this->totalSinceLastHandler,
            );
        } finally {
            $this->releaseLock($chat);
        }
    }

    private function releaseLock(Chat $chat): void
    {
        if ($this->lockKey !== null && $this->lockToken !== null) {
            $chat->state->releaseLock(new Lock($this->lockKey, $this->lockToken, 0));
        }
    }
}
