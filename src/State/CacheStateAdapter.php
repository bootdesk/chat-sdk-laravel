<?php

namespace BootDesk\ChatSDK\Laravel\State;

use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Lock;
use BootDesk\ChatSDK\Core\QueueEntry;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock as LaravelLock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheStateAdapter implements StateAdapter
{
    /**
     * @var Repository&LockProvider
     */
    private Repository $cache;

    private string $prefix;

    /** @var array<string, LaravelLock> */
    private array $heldLocks = [];

    public function __construct(
        CacheFactory $cacheFactory,
        ?string $store = 'file',
        string $prefix = 'chat:',
    ) {
        /** @var Repository&LockProvider $cache */
        $cache = $cacheFactory->store($store);

        $this->cache = $cache;
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
        $this->cache->put($this->prefix.'subscriptions', $subs);
    }

    public function unsubscribe(string $threadId): void
    {
        $subs = $this->getSubscriptions();
        unset($subs[$threadId]);
        $this->cache->put($this->prefix.'subscriptions', $subs);
    }

    public function isSubscribed(string $threadId): bool
    {
        return isset($this->getSubscriptions()[$threadId]);
    }

    public function acquireLock(string $lockKey, int $ttlMs): ?Lock
    {
        $key = $this->prefix.'lock:'.$lockKey;
        $ttlSeconds = max(1, (int) ceil($ttlMs / 1000));

        $laravelLock = $this->cache->lock($key, $ttlSeconds);

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
        $newLock = $this->cache->lock($key, $ttlSeconds);
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
        $this->cache->lock($key)->forceRelease();
        unset($this->heldLocks[$lockKey]);
    }

    public function get(string $key): mixed
    {
        return $this->cache->get($this->prefix.$key);
    }

    public function set(string $key, mixed $value, ?int $ttlMs = null): void
    {
        Log::debug('Set', ['m' => serialize($value)]);
        $ttlSeconds = $ttlMs !== null ? max(1, (int) ceil($ttlMs / 1000)) : null;
        $this->cache->put($this->prefix.$key, $value, $ttlSeconds);
    }

    public function setIfNotExists(string $key, mixed $value, ?int $ttlMs = null): bool
    {
        $ttlSeconds = $ttlMs !== null ? max(1, (int) ceil($ttlMs / 1000)) : null;
        $fullKey = $this->prefix.$key;

        // Use add() which returns true only if key didn't exist
        return $this->cache->add($fullKey, $value, $ttlSeconds);
    }

    public function delete(string $key): void
    {
        $this->cache->forget($this->prefix.$key);
    }

    public function appendToList(string $key, mixed $value, array $options = []): void
    {
        $fullKey = $this->prefix.'list:'.$key;
        $list = $this->cache->get($fullKey, []);
        $list[] = $value;

        $maxLength = $options['maxLength'] ?? null;
        if ($maxLength !== null && count($list) > $maxLength) {
            $list = array_slice($list, -$maxLength);
        }

        $ttlSeconds = isset($options['ttlMs'])
            ? max(1, (int) ceil($options['ttlMs'] / 1000))
            : null;

        $this->cache->put($fullKey, $list, $ttlSeconds);
    }

    public function getList(string $key): array
    {
        return $this->cache->get($this->prefix.'list:'.$key, []);
    }

    public function enqueue(string $threadId, QueueEntry $entry, int $maxSize): int
    {
        $fullKey = $this->prefix.'queue:'.$threadId;
        $queue = $this->cache->get($fullKey, []);
        $queue[] = [
            'messageId' => $entry->messageId,
            'payload' => $entry->payload,
            'enqueuedAt' => $entry->enqueuedAt,
        ];

        if (count($queue) > $maxSize) {
            array_shift($queue);
        }

        $this->cache->put($fullKey, $queue);

        return count($queue);
    }

    public function dequeue(string $threadId): ?QueueEntry
    {
        $fullKey = $this->prefix.'queue:'.$threadId;
        $queue = $this->cache->get($fullKey, []);

        if (empty($queue)) {
            return null;
        }

        $item = array_shift($queue);
        $this->cache->put($fullKey, $queue);

        return new QueueEntry(
            messageId: $item['messageId'],
            payload: $item['payload'],
            enqueuedAt: $item['enqueuedAt'],
        );
    }

    public function queueDepth(string $threadId): int
    {
        $queue = $this->cache->get($this->prefix.'queue:'.$threadId, []);

        return count($queue);
    }

    public function storeModalContext(string $adapterName, string $contextId, array $data, int $ttlMs): void
    {
        $ttlSeconds = max(1, (int) ceil($ttlMs / 1000));
        $this->cache->put($this->prefix."modal-context:{$adapterName}:{$contextId}", $data, $ttlSeconds);
    }

    public function getAndDeleteModalContext(string $adapterName, string $contextId): ?array
    {
        $key = $this->prefix."modal-context:{$adapterName}:{$contextId}";
        $data = $this->cache->get($key);
        if (is_array($data)) {
            $this->cache->forget($key);
        }

        return is_array($data) ? $data : null;
    }

    private function getSubscriptions(): array
    {
        return $this->cache->get($this->prefix.'subscriptions', []);
    }
}
