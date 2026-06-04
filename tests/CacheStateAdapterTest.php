<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Lock;
use BootDesk\ChatSDK\Core\QueueEntry;
use BootDesk\ChatSDK\Laravel\State\CacheStateAdapter;
use Orchestra\Testbench\TestCase;

class CacheStateAdapterTest extends TestCase
{
    private CacheStateAdapter $adapter;

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new CacheStateAdapter(
            prefix: 'test:',
        );
    }

    public function test_connect_and_disconnect(): void
    {
        $this->adapter->connect();
        $this->adapter->disconnect();
        $this->assertTrue(true); // No exceptions
    }

    public function test_set_and_get(): void
    {
        $this->adapter->set('key1', 'value1');
        $this->assertSame('value1', $this->adapter->get('key1'));
    }

    public function test_get_returns_null_for_missing(): void
    {
        $this->assertNull($this->adapter->get('nonexistent'));
    }

    public function test_set_with_ttl(): void
    {
        $this->adapter->set('ttl_key', 'value', 1000);
        $this->assertSame('value', $this->adapter->get('ttl_key'));
    }

    public function test_set_if_not_exists(): void
    {
        $this->assertTrue($this->adapter->setIfNotExists('new_key', 'val'));
        $this->assertFalse($this->adapter->setIfNotExists('new_key', 'val2'));
        $this->assertSame('val', $this->adapter->get('new_key'));
    }

    public function test_delete(): void
    {
        $this->adapter->set('del_key', 'val');
        $this->adapter->delete('del_key');
        $this->assertNull($this->adapter->get('del_key'));
    }

    public function test_subscribe_and_is_subscribed(): void
    {
        $this->adapter->subscribe('thread:1');
        $this->assertTrue($this->adapter->isSubscribed('thread:1'));
        $this->assertFalse($this->adapter->isSubscribed('thread:2'));
    }

    public function test_unsubscribe(): void
    {
        $this->adapter->subscribe('thread:1');
        $this->adapter->unsubscribe('thread:1');
        $this->assertFalse($this->adapter->isSubscribed('thread:1'));
    }

    public function test_append_to_list(): void
    {
        $this->adapter->appendToList('list1', 'a');
        $this->adapter->appendToList('list1', 'b');
        $this->assertSame(['a', 'b'], $this->adapter->getList('list1'));
    }

    public function test_get_list_returns_empty_for_missing(): void
    {
        $this->assertSame([], $this->adapter->getList('missing'));
    }

    public function test_append_to_list_max_length(): void
    {
        $this->adapter->appendToList('capped', 'a', ['maxLength' => 2]);
        $this->adapter->appendToList('capped', 'b', ['maxLength' => 2]);
        $this->adapter->appendToList('capped', 'c', ['maxLength' => 2]);
        $this->assertSame(['b', 'c'], $this->adapter->getList('capped'));
    }

    public function test_enqueue_and_dequeue(): void
    {
        $entry1 = new QueueEntry('m1', '{"data":1}', 1000.0);
        $entry2 = new QueueEntry('m2', '{"data":2}', 2000.0);

        $this->adapter->enqueue('t1', $entry1, 10);
        $depth = $this->adapter->enqueue('t1', $entry2, 10);

        $this->assertSame(2, $depth);
        $this->assertSame(2, $this->adapter->queueDepth('t1'));

        $dequeued = $this->adapter->dequeue('t1');
        $this->assertSame('m1', $dequeued->messageId);
        $this->assertSame(1, $this->adapter->queueDepth('t1'));
    }

    public function test_dequeue_returns_null_for_empty(): void
    {
        $this->assertNull($this->adapter->dequeue('empty'));
    }

    public function test_acquire_and_release_lock(): void
    {
        $lock = $this->adapter->acquireLock('resource:1', 5000);
        $this->assertNotNull($lock);
        $this->assertSame('resource:1', $lock->key);

        // Second acquire should fail
        $lock2 = $this->adapter->acquireLock('resource:1', 5000);
        $this->assertNull($lock2);

        // Release and reacquire
        $this->adapter->releaseLock($lock);
        $lock3 = $this->adapter->acquireLock('resource:1', 5000);
        $this->assertNotNull($lock3);
    }

    public function test_force_release_lock(): void
    {
        $lock = $this->adapter->acquireLock('force:1', 30000);
        $this->assertNotNull($lock);

        $this->adapter->forceReleaseLock('force:1');

        $lock2 = $this->adapter->acquireLock('force:1', 30000);
        $this->assertNotNull($lock2);
    }

    public function test_extend_lock(): void
    {
        $lock = $this->adapter->acquireLock('extend:1', 30000);
        $this->assertNotNull($lock);

        // Extend the lock
        $extended = $this->adapter->extendLock($lock, 60000);
        $this->assertTrue($extended);

        // Lock should still be held (not expired)
        $lock2 = $this->adapter->acquireLock('extend:1', 100);
        $this->assertNull($lock2);

        $this->adapter->releaseLock($lock);
    }

    public function test_extend_lock_returns_false_for_unknown_lock(): void
    {
        $lock = new Lock('unknown', 'token', 5000);
        $result = $this->adapter->extendLock($lock, 10000);
        $this->assertFalse($result);
    }
}
