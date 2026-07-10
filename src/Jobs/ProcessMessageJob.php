<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Jobs;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Lock;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

    public function handle(ChatFactory $chatFactory): void
    {
        $logger = $this->resolveLogger();
        $logger->debug('Processing queued message', [
            'adapter' => $this->adapterName,
            'thread' => $this->threadId,
            'message_id' => $this->message->id,
            'skipped' => count($this->skippedMessages),
            'total_since_last' => $this->totalSinceLastHandler,
        ]);

        $request = $this->requestContext?->toPsrRequest();
        $groups = $request?->getAttribute('chat_groups') ?? [$this->adapterName];
        $chat = $chatFactory->forGroups($groups, $request);
        $adapter = $chat->resolveAdapter($this->adapterName, $request);

        if (! $adapter instanceof Adapter) {
            $logger->warning('Adapter not resolvable, releasing lock', [
                'adapter' => $this->adapterName,
            ]);
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
            $logger->debug('Queued message processed successfully', [
                'adapter' => $this->adapterName,
                'thread' => $this->threadId,
            ]);
        } finally {
            $this->releaseLock($chat);
            $logger->debug('Lock released', [
                'adapter' => $this->adapterName,
                'thread' => $this->threadId,
                'lock_key' => $this->lockKey,
            ]);
        }
    }

    private function resolveLogger(): LoggerInterface
    {
        try {
            $enabled = config('chat.logging.enabled', false);

            if (! $enabled) {
                return new NullLogger;
            }

            $channel = config('chat.logging.channel');

            if ($channel !== null) {
                return Log::channel($channel);
            }

            return Log::channel();
        } catch (\Throwable) {
            return new NullLogger;
        }
    }

    private function releaseLock(Chat $chat): void
    {
        if ($this->lockKey !== null && $this->lockToken !== null) {
            $chat->state->releaseLock(new Lock($this->lockKey, $this->lockToken, 0));
        }
    }
}
