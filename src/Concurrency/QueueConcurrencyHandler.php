<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Concurrency;

use BootDesk\ChatSDK\Core\Concurrency\Strategy;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\ConcurrencyHandler;
use BootDesk\ChatSDK\Core\Contracts\RequiresAsyncResponse;
use BootDesk\ChatSDK\Core\Contracts\RequiresSyncResponse;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Lock;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessDebouncedMessageJob;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessMessageJob;
use Illuminate\Support\Facades\Bus;

class QueueConcurrencyHandler implements ConcurrencyHandler
{
    public function __construct(
        private readonly StateAdapter $state,
        private readonly array $config = [],
    ) {}

    public function process(
        Adapter $adapter,
        string $threadId,
        Message $message,
        callable $processCallback,
    ): void {
        $strategy = Strategy::tryFrom($this->config['concurrency'] ?? 'drop') ?? Strategy::Drop;
        $debounceMs = (int) ($this->config['debounceMs'] ?? 1500);
        $lockScope = $this->config['lockScope'] ?? 'thread';

        $lockKey = $lockScope === 'channel'
            ? $adapter->getName().':'.$adapter->channelIdFromThreadId($threadId)
            : $threadId;

        if ($adapter instanceof RequiresSyncResponse) {
            $this->processSync($lockKey, $adapter, $threadId, $message, $processCallback);

            return;
        }

        if ($adapter instanceof RequiresAsyncResponse) {
            if ($strategy === Strategy::Drop) {
                $this->processDropAsync($lockKey, $adapter, $threadId, $message);
            } else {
                $this->dispatchAsync($strategy, $adapter, $threadId, $message, $debounceMs);
            }

            return;
        }

        $lock = $this->state->acquireLock("process:{$lockKey}", 30_000);
        if ($lock instanceof Lock) {
            try {
                $processCallback($adapter, $threadId, $message, [], 1);
            } finally {
                $this->state->releaseLock($lock);
            }

            return;
        }

        $this->dispatchAsync($strategy, $adapter, $threadId, $message, $debounceMs);
    }

    private function processSync(
        string $lockKey,
        Adapter $adapter,
        string $threadId,
        Message $message,
        callable $processCallback,
    ): void {
        $lock = $this->state->acquireLock("process:{$lockKey}", 30_000);
        if (! $lock instanceof Lock) {
            return;
        }

        try {
            $processCallback($adapter, $threadId, $message, [], 1);
        } finally {
            $this->state->releaseLock($lock);
        }
    }

    private function processDropAsync(
        string $lockKey,
        Adapter $adapter,
        string $threadId,
        Message $message,
    ): void {
        $lock = $this->state->acquireLock("process:{$lockKey}", 30_000);
        if (! $lock instanceof Lock) {
            return;
        }

        Bus::dispatch(new ProcessMessageJob(
            adapterName: $adapter->getName(),
            threadId: $threadId,
            message: $message,
            lockKey: "process:{$lockKey}",
            lockToken: $lock->token,
        ));
    }

    private function dispatchAsync(
        Strategy $strategy,
        Adapter $adapter,
        string $threadId,
        Message $message,
        int $debounceMs,
    ): void {
        match ($strategy) {
            Strategy::Drop => null,
            Strategy::Queue => $this->dispatchJob($adapter, $threadId, $message),
            Strategy::Debounce => $this->dispatchDebounced($adapter, $threadId, $message, $debounceMs),
            Strategy::Concurrent => $this->dispatchJob($adapter, $threadId, $message),
        };
    }

    private function dispatchJob(Adapter $adapter, string $threadId, Message $message): void
    {
        Bus::dispatch(new ProcessMessageJob(
            adapterName: $adapter->getName(),
            threadId: $threadId,
            message: $message,
        ));
    }

    private function dispatchDebounced(
        Adapter $adapter,
        string $threadId,
        Message $message,
        int $debounceMs,
    ): void {
        $debounceKey = "chat:debounce:{$threadId}";
        $ttl = $debounceMs + 5000;

        $previous = $this->state->get("{$debounceKey}:latest");
        if ($previous instanceof Message) {
            $skipped = $this->state->get("{$debounceKey}:skipped");
            $skipped = is_array($skipped) ? $skipped : [];
            $skipped[] = $previous;
            $this->state->set("{$debounceKey}:skipped", $skipped, $ttl);
        }

        $this->state->set("{$debounceKey}:latest", $message, $ttl);
        $this->state->set("{$debounceKey}:last", microtime(true), $ttl);

        Bus::dispatch(tap(
            new ProcessDebouncedMessageJob(
                adapterName: $adapter->getName(),
                threadId: $threadId,
                debounceKey: $debounceKey,
                debounceMs: $debounceMs,
            ),
            fn (ProcessDebouncedMessageJob $job) => $job->delay(now()->addMilliseconds($debounceMs)),
        ));
    }
}
