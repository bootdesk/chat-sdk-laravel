<?php

namespace BootDesk\ChatSDK\Laravel\State;

use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Lock;
use BootDesk\ChatSDK\Core\QueueEntry;
use Illuminate\Contracts\Cache\Lock as LaravelLock;
use Illuminate\Support\Facades\Cache;

class CacheStateAdapter implements StateAdapter
{
    private string $prefix;

    /** @var array<string, LaravelLock> */
    private array $heldLocks = [];

    public function __construct(
        string $prefix = 'chat:',
    ) {
        $this->prefix = $prefix;
    }

    public function connect(): void
    {
        // No-op — Laravel cache is already managed
    }

    public function disconnect(): void
    {
        // No-op
    }

    public function subscribe(string $threadId): void
    {
        $subs = $this->getSubscriptions();
        $subs[$threadId] = true;
        Cache::put($this->prefix.'subscriptions', $subs);
    }

    public function unsubscribe(string $threadId): void
    {
        $subs = $this->getSubscriptions();
        unset($subs[$threadId]);
        Cache::put($this->prefix.'subscriptions', $subs);
    }

    public function isSubscribed(string $threadId): bool
    {
        return isset($this->getSubscriptions()[$threadId]);
    }

    public function acquireLock(string $lockKey, int $ttlMs): ?Lock
    {
        $key = $this->prefix.'lock:'.$lockKey;
        $ttlSeconds = max(1, (int) ceil($ttlMs / 1000));

        $laravelLock = Cache::lock($key, $ttlSeconds);

        if ($laravelLock->get()) {
            $this->heldLocks[$lockKey] = $laravelLock;

            return new Lock($lockKey, bin2hex(random_bytes(16)), $ttlMs);
        }

        return null;
    }

    public function extendLock(Lock $lock, int $ttlMs): bool
    {
        if (! isset($this->heldLocks[$lock->key])) {
            return false;
        }

        $key = $this->prefix.'lock:'.$lock->key;
        $ttlSeconds = max(1, (int) ceil($ttlMs / 1000));
        $laravelLock = $this->heldLocks[$lock->key];

        $laravelLock->release();
        $newLock = Cache::lock($key, $ttlSeconds);
        if ($newLock->get()) {
            $this->heldLocks[$lock->key] = $newLock;

            return true;
        }

        unset($this->heldLocks[$lock->key]);

        return false;
    }

    public function releaseLock(Lock $lock): void
    {
        if (isset($this->heldLocks[$lock->key])) {
            $this->heldLocks[$lock->key]->release();
            unset($this->heldLocks[$lock->key]);
        }
    }

    public function forceReleaseLock(string $lockKey): void
    {
        $key = $this->prefix.'lock:'.$lockKey;
        Cache::lock($key)->forceRelease();
        unset($this->heldLocks[$lockKey]);
    }

    public function get(string $key): mixed
    {
        return Cache::get($this->prefix.$key);
    }

    public function set(string $key, mixed $value, ?int $ttlMs = null): void
    {
        $ttlSeconds = $ttlMs !== null ? max(1, (int) ceil($ttlMs / 1000)) : null;
        Cache::put($this->prefix.$key, $value, $ttlSeconds);
    }

    public function setIfNotExists(string $key, mixed $value, ?int $ttlMs = null): bool
    {
        $ttlSeconds = $ttlMs !== null ? max(1, (int) ceil($ttlMs / 1000)) : null;
        $fullKey = $this->prefix.$key;

        // Use add() which returns true only if key didn't exist
        return Cache::add($fullKey, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        Cache::forget($this->prefix.$key);
    }

    public function appendToList(string $key, mixed $value, array $options = []): void
    {
        $fullKey = $this->prefix.'list:'.$key;
        $list = Cache::get($fullKey, []);
        $list[] = $value;

        $maxLength = $options['maxLength'] ?? null;
        if ($maxLength !== null && count($list) > $maxLength) {
            $list = array_slice($list, -$maxLength);
        }

        $ttlSeconds = isset($options['ttlMs'])
            ? max(1, (int) ceil($options['ttlMs'] / 1000))
            : null;

        Cache::put($fullKey, $list, $ttlSeconds);
    }

    public function getList(string $key): array
    {
        return Cache::get($this->prefix.'list:'.$key, []);
    }

    public function enqueue(string $threadId, QueueEntry $entry, int $maxSize): int
    {
        $fullKey = $this->prefix.'queue:'.$threadId;
        $queue = Cache::get($fullKey, []);
        $queue[] = [
            'messageId' => $entry->messageId,
            'payload' => $entry->payload,
            'enqueuedAt' => $entry->enqueuedAt,
        ];

        if (count($queue) > $maxSize) {
            array_shift($queue);
        }

        Cache::put($fullKey, $queue);

        return count($queue);
    }

    public function dequeue(string $threadId): ?QueueEntry
    {
        $fullKey = $this->prefix.'queue:'.$threadId;
        $queue = Cache::get($fullKey, []);

        if (empty($queue)) {
            return null;
        }

        $item = array_shift($queue);
        Cache::put($fullKey, $queue);

        return new QueueEntry(
            messageId: $item['messageId'],
            payload: $item['payload'],
            enqueuedAt: $item['enqueuedAt'],
        );
    }

    public function queueDepth(string $threadId): int
    {
        $queue = Cache::get($this->prefix.'queue:'.$threadId, []);

        return count($queue);
    }

    public function storeModalContext(string $adapterName, string $contextId, array $data, int $ttlMs): void
    {
        $ttlSeconds = max(1, (int) ceil($ttlMs / 1000));
        Cache::put($this->prefix."modal-context:{$adapterName}:{$contextId}", $data, $ttlSeconds);
    }

    public function getAndDeleteModalContext(string $adapterName, string $contextId): ?array
    {
        $key = $this->prefix."modal-context:{$adapterName}:{$contextId}";
        $data = Cache::get($key);
        if (is_array($data)) {
            Cache::forget($key);
        }

        return is_array($data) ? $data : null;
    }

    private function getSubscriptions(): array
    {
        return Cache::get($this->prefix.'subscriptions', []);
    }
}
