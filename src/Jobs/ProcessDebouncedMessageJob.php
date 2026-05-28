<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Jobs;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;

class ProcessDebouncedMessageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $adapterName,
        private readonly string $threadId,
        private readonly string $debounceKey,
        private readonly int $debounceMs,
    ) {}

    public function uniqueId(): string
    {
        return $this->debounceKey;
    }

    public function handle(Chat $chat): void
    {
        $adapter = $chat->resolveAdapter($this->adapterName);

        if (! $adapter instanceof Adapter) {
            return;
        }

        $message = $chat->state->get("{$this->debounceKey}:latest");
        $skipped = $chat->state->get("{$this->debounceKey}:skipped");
        $lastTimestamp = $chat->state->get("{$this->debounceKey}:last");

        $chat->state->delete("{$this->debounceKey}:latest");
        $chat->state->delete("{$this->debounceKey}:skipped");
        $chat->state->delete("{$this->debounceKey}:last");

        if (! $message instanceof Message) {
            return;
        }

        $windowEnd = $lastTimestamp !== null
            ? (float) $lastTimestamp + ($this->debounceMs / 1000)
            : 0.0;

        if (microtime(true) < $windowEnd) {
            $remainingMs = (int) ceil(($windowEnd - microtime(true)) * 1000);

            $ttl = $remainingMs + 5000;
            $chat->state->set("{$this->debounceKey}:latest", $message, $ttl);
            if (is_array($skipped)) {
                $chat->state->set("{$this->debounceKey}:skipped", $skipped, $ttl);
            }

            Bus::dispatch(tap(
                new self($this->adapterName, $this->threadId, $this->debounceKey, $this->debounceMs),
                fn (self $job) => $job->delay(now()->addMilliseconds(max(1, $remainingMs))),
            ));

            return;
        }

        $chat->processMessageInJob(
            adapter: $adapter,
            threadId: $this->threadId,
            message: $message,
            skippedMessages: is_array($skipped) ? $skipped : [],
            totalSinceLastHandler: (is_array($skipped) ? count($skipped) : 0) + 1,
        );
    }
}
