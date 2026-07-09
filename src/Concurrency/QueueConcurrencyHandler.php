<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Concurrency;

use BootDesk\ChatSDK\Core\Concurrency\Strategy;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\ConcurrencyHandler;
use BootDesk\ChatSDK\Core\Contracts\HasDynamicSyncPreference;
use BootDesk\ChatSDK\Core\Contracts\RequiresAsyncResponse;
use BootDesk\ChatSDK\Core\Contracts\RequiresSyncResponse;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Lock;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessDebouncedMessageJob;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessMessageJob;
use BootDesk\ChatSDK\Laravel\Jobs\RequestContext;
use Illuminate\Support\Facades\Bus;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class QueueConcurrencyHandler implements ConcurrencyHandler
{
    private ?RequestContext $requestContext = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StateAdapter $state,
        private readonly array $config = [],
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    public function process(
        Adapter $adapter,
        string $threadId,
        Message $message,
        callable $processCallback,
        ?ServerRequestInterface $request = null,
    ): void {
        $this->requestContext = $request instanceof ServerRequestInterface
            ? RequestContext::fromServerRequest($request)
            : null;

        $strategy = Strategy::tryFrom($this->config['concurrency'] ?? 'drop') ?? Strategy::Drop;
        $debounceMs = (int) ($this->config['debounceMs'] ?? 1500);
        $lockScope = $this->config['lockScope'] ?? 'thread';

        $lockKey = $lockScope === 'channel'
            ? $adapter->getName().':'.$adapter->channelIdFromThreadId($threadId)
            : $threadId;

        if ($adapter instanceof HasDynamicSyncPreference) {
            if ($adapter->requiresSyncResponse()) {
                $this->processSync($lockKey, $adapter, $threadId, $message, $processCallback);

                return;
            }

            if ($strategy === Strategy::Drop) {
                $this->processDropAsync($lockKey, $adapter, $threadId, $message);
            } else {
                $this->dispatchAsync($strategy, $adapter, $threadId, $message, $debounceMs);
            }

            return;
        }

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
            $this->logger->debug('[QueueConcurrency] Lock acquired, processing inline', [
                'lockKey' => $lockKey,
                'threadId' => $threadId,
            ]);

            try {
                $processCallback($adapter, $threadId, $message, [], 1);
            } finally {
                $this->state->releaseLock($lock);
            }

            return;
        }

        $this->logger->debug('[QueueConcurrency] No lock, dispatching async', [
            'lockKey' => $lockKey,
            'strategy' => $strategy->value,
            'threadId' => $threadId,
        ]);

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
            $this->logger->warning('[QueueConcurrency] processSync: lock not acquired', [
                'lockKey' => $lockKey,
                'threadId' => $threadId,
            ]);

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
            $this->logger->debug('[QueueConcurrency] dropAsync: dropped (lock held)', [
                'lockKey' => $lockKey,
                'threadId' => $threadId,
            ]);

            return;
        }

        $this->logger->debug('[QueueConcurrency] dropAsync: dispatching job with lock', [
            'lockKey' => $lockKey,
            'threadId' => $threadId,
        ]);

        Bus::dispatch(new ProcessMessageJob(
            adapterName: $adapter->getName(),
            threadId: $threadId,
            message: $message,
            lockKey: "process:{$lockKey}",
            lockToken: $lock->token,
            requestContext: $this->requestContext,
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
        $this->logger->debug('[QueueConcurrency] Dispatching job', [
            'adapter' => $adapter->getName(),
            'threadId' => $threadId,
            'messageId' => $message->id,
        ]);

        Bus::dispatch(new ProcessMessageJob(
            adapterName: $adapter->getName(),
            threadId: $threadId,
            message: $message,
            requestContext: $this->requestContext,
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
            $this->logger->debug('[QueueConcurrency] Debounce: previous message skipped', [
                'threadId' => $threadId,
                'skippedCount' => count($skipped),
            ]);
        }

        $this->state->set("{$debounceKey}:latest", $message, $ttl);
        $this->state->set("{$debounceKey}:last", microtime(true), $ttl);

        $this->logger->debug('[QueueConcurrency] Debounce job dispatched', [
            'threadId' => $threadId,
            'debounceMs' => $debounceMs,
        ]);

        Bus::dispatch(tap(
            new ProcessDebouncedMessageJob(
                adapterName: $adapter->getName(),
                threadId: $threadId,
                debounceKey: $debounceKey,
                debounceMs: $debounceMs,
                requestContext: $this->requestContext,
            ),
            fn (ProcessDebouncedMessageJob $job) => $job->delay(now()->addMilliseconds($debounceMs)),
        ));
    }
}
