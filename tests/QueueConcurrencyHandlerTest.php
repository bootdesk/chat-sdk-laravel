<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Concurrency\QueueConcurrencyHandler;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessDebouncedMessageJob;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessMessageJob;
use BootDesk\ChatSDK\Laravel\Tests\Helpers\TestAdaptiveAdapter;
use BootDesk\ChatSDK\Laravel\Tests\Helpers\TestAsyncAdapter;
use BootDesk\ChatSDK\Laravel\Tests\Helpers\TestSyncAdapter;
use Illuminate\Support\Facades\Bus;
use Orchestra\Testbench\TestCase;

class QueueConcurrencyHandlerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('chat.state.store', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(StateAdapter::class);
        $this->app->forgetInstance(QueueConcurrencyHandler::class);
        $cache = $this->app->make('cache');
        $cache->store('array')->flush();
    }

    private function makeMessage(string $id = 'msg_1'): Message
    {
        return new Message(
            id: $id,
            threadId: 'test:ch:th',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );
    }

    public function test_sync_adapter_processes_inline(): void
    {
        Bus::fake();
        $handler = new QueueConcurrencyHandler(
            $this->app->make(StateAdapter::class),
            ['concurrency' => 'drop'],
        );
        $adapter = new TestSyncAdapter;
        $message = $this->makeMessage();
        $called = false;

        $handler->process($adapter, 'sync:ch1:th1', $message, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        Bus::assertNothingDispatched();
    }

    public function test_sync_adapter_skips_when_locked(): void
    {
        Bus::fake();
        $state = $this->app->make(StateAdapter::class);
        $handler = new QueueConcurrencyHandler($state, ['concurrency' => 'drop']);
        $adapter = new TestSyncAdapter;
        $message = $this->makeMessage();
        $called = false;

        $state->acquireLock('process:sync:ch1:th1', 30_000);

        $handler->process($adapter, 'sync:ch1:th1', $message, function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        Bus::assertNothingDispatched();
    }

    public function test_async_adapter_drop_dispatches_job_when_lock_available(): void
    {
        Bus::fake();
        $handler = new QueueConcurrencyHandler(
            $this->app->make(StateAdapter::class),
            ['concurrency' => 'drop'],
        );
        $adapter = new TestAsyncAdapter;
        $message = $this->makeMessage();

        $handler->process($adapter, 'async:ch1:th1', $message, fn () => null);

        Bus::assertDispatched(ProcessMessageJob::class);
    }

    public function test_async_adapter_drop_skips_when_locked(): void
    {
        Bus::fake();
        $state = $this->app->make(StateAdapter::class);
        $handler = new QueueConcurrencyHandler(
            $state,
            ['concurrency' => 'drop'],
        );
        $adapter = new TestAsyncAdapter;
        $message = $this->makeMessage();

        $state->acquireLock('process:async:ch1:th1', 30_000);

        $handler->process($adapter, 'async:ch1:th1', $message, fn () => null);

        Bus::assertNothingDispatched();
    }

    public function test_async_adapter_queue_dispatches_job(): void
    {
        Bus::fake();
        $handler = new QueueConcurrencyHandler(
            $this->app->make(StateAdapter::class),
            ['concurrency' => 'queue'],
        );
        $adapter = new TestAsyncAdapter;
        $message = $this->makeMessage();

        $handler->process($adapter, 'async:ch1:th1', $message, fn () => null);

        Bus::assertDispatched(ProcessMessageJob::class);
    }

    public function test_async_adapter_concurrent_dispatches_job(): void
    {
        Bus::fake();
        $handler = new QueueConcurrencyHandler(
            $this->app->make(StateAdapter::class),
            ['concurrency' => 'concurrent'],
        );
        $adapter = new TestAsyncAdapter;
        $message = $this->makeMessage();

        $handler->process($adapter, 'async:ch1:th1', $message, fn () => null);

        Bus::assertDispatched(ProcessMessageJob::class);
    }

    public function test_async_adapter_debounce_dispatches_debounced_job(): void
    {
        Bus::fake();
        $handler = new QueueConcurrencyHandler(
            $this->app->make(StateAdapter::class),
            ['concurrency' => 'debounce', 'debounceMs' => 100],
        );
        $adapter = new TestAsyncAdapter;
        $message = $this->makeMessage();

        $handler->process($adapter, 'async:ch1:th1', $message, fn () => null);

        Bus::assertDispatched(ProcessDebouncedMessageJob::class);
    }

    public function test_adaptive_processes_inline_when_lock_available(): void
    {
        Bus::fake();
        $handler = new QueueConcurrencyHandler(
            $this->app->make(StateAdapter::class),
            ['concurrency' => 'queue'],
        );
        $adapter = new TestAdaptiveAdapter;
        $message = $this->makeMessage();
        $called = false;

        $handler->process($adapter, 'adaptive:ch1:th1', $message, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
        Bus::assertNothingDispatched();
    }

    public function test_adaptive_dispatches_on_lock_contention(): void
    {
        Bus::fake();
        $state = $this->app->make(StateAdapter::class);
        $handler = new QueueConcurrencyHandler(
            $state,
            ['concurrency' => 'queue'],
        );
        $adapter = new TestAdaptiveAdapter;
        $message = $this->makeMessage();
        $called = false;

        $state->acquireLock('process:adaptive:ch1:th1', 30_000);

        $handler->process($adapter, 'adaptive:ch1:th1', $message, function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        Bus::assertDispatched(ProcessMessageJob::class);
    }

    public function test_adaptive_drops_on_lock_contention_when_strategy_is_drop(): void
    {
        Bus::fake();
        $state = $this->app->make(StateAdapter::class);
        $handler = new QueueConcurrencyHandler(
            $state,
            ['concurrency' => 'drop'],
        );
        $adapter = new TestAdaptiveAdapter;
        $message = $this->makeMessage();
        $called = false;

        $state->acquireLock('process:adaptive:ch1:th1', 30_000);

        $handler->process($adapter, 'adaptive:ch1:th1', $message, function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
        Bus::assertNothingDispatched();
    }

    public function test_debounce_stores_latest_and_dispatches(): void
    {
        Bus::fake();
        $state = $this->app->make(StateAdapter::class);
        $handler = new QueueConcurrencyHandler(
            $state,
            ['concurrency' => 'debounce', 'debounceMs' => 100],
        );
        $adapter = new TestAsyncAdapter;
        $message = $this->makeMessage(id: 'first');

        $handler->process($adapter, 'async:ch1:th1', $message, fn () => null);

        $stored = $state->get('chat:debounce:async:ch1:th1:latest');
        $this->assertInstanceOf(Message::class, $stored);
        $this->assertSame('first', $stored->id);
        $this->assertNotNull($state->get('chat:debounce:async:ch1:th1:last'));
        Bus::assertDispatched(ProcessDebouncedMessageJob::class);
    }

    public function test_debounce_tracks_skipped_on_subsequent_messages(): void
    {
        Bus::fake();
        $state = $this->app->make(StateAdapter::class);
        $handler = new QueueConcurrencyHandler(
            $state,
            ['concurrency' => 'debounce', 'debounceMs' => 100],
        );
        $adapter = new TestAsyncAdapter;

        $handler->process($adapter, 'async:ch1:th1', $this->makeMessage(id: 'first'), fn () => null);
        $handler->process($adapter, 'async:ch1:th1', $this->makeMessage(id: 'second'), fn () => null);

        $this->assertSame('second', $state->get('chat:debounce:async:ch1:th1:latest')?->id);
        $this->assertNotNull($state->get('chat:debounce:async:ch1:th1:last'));

        $skipped = $state->get('chat:debounce:async:ch1:th1:skipped');
        $this->assertIsArray($skipped);
        $this->assertCount(1, $skipped);
        $this->assertSame('first', $skipped[0]->id);
    }

    public function test_channel_scope_uses_channel_id_for_lock(): void
    {
        Bus::fake();
        $state = $this->app->make(StateAdapter::class);
        $handler = new QueueConcurrencyHandler(
            $state,
            ['concurrency' => 'drop', 'lockScope' => 'channel'],
        );
        $adapter = new TestSyncAdapter;
        $message = $this->makeMessage();
        $called = false;

        $state->acquireLock('process:test-sync:ch1', 30_000);

        $handler->process($adapter, 'sync:ch1:th1', $message, function () use (&$called) {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function test_channel_scope_allows_different_channels(): void
    {
        Bus::fake();
        $state = $this->app->make(StateAdapter::class);
        $handler = new QueueConcurrencyHandler(
            $state,
            ['concurrency' => 'drop', 'lockScope' => 'channel'],
        );
        $adapter = new TestSyncAdapter;
        $message = $this->makeMessage();
        $called = false;

        $state->acquireLock('process:test-sync:ch1', 30_000);

        $handler->process($adapter, 'sync:ch2:th1', $message, function () use (&$called) {
            $called = true;
        });

        $this->assertTrue($called);
    }

    public function test_default_strategy_is_drop_but_dispatches_for_async(): void
    {
        Bus::fake();
        $handler = new QueueConcurrencyHandler(
            $this->app->make(StateAdapter::class),
            [],
        );
        $adapter = new TestAsyncAdapter;
        $message = $this->makeMessage();

        $handler->process($adapter, 'async:ch1:th1', $message, fn () => null);

        Bus::assertDispatched(ProcessMessageJob::class);
    }
}
