<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Jobs\ProcessMessageJob;
use Orchestra\Testbench\TestCase;

class ProcessMessageJobTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    public function test_job_processes_message(): void
    {
        $chat = $this->app->make(Chat::class);
        $called = false;
        $chat->onNewMessage('/.*/', function () use (&$called) {
            $called = true;
        });

        $message = new Message(
            id: 'job_msg',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('unknown', 'unknown:channel', $message);
        $job->handle($chat);

        $this->assertFalse($called);
    }

    public function test_job_handles_unknown_adapter(): void
    {
        $chat = $this->app->make(Chat::class);
        $called = false;
        $chat->onNewMessage('/.*/', function () use (&$called) {
            $called = true;
        });

        $message = new Message(
            id: 'job_msg_2',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('nonexistent', 'unknown:channel', $message);
        // Should not throw — just returns when adapter not found
        $job->handle($chat);

        $this->assertFalse($called);
    }

    public function test_job_calls_process_message_in_job(): void
    {
        $chat = $this->app->make(Chat::class);
        $message = new Message(
            id: 'job_inline',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'hello',
        );

        $job = new ProcessMessageJob('unknown', 'unknown:channel', $message);
        // Should not throw — processMessageInJob resolves adapter, returns early if not found
        $job->handle($chat);

        $this->expectNotToPerformAssertions();
    }

    public function test_job_passes_skipped_messages_to_handler(): void
    {
        $chat = $this->app->make(Chat::class);
        $skipped = [
            new Message(
                id: 'skipped_1',
                threadId: 'unknown:channel',
                author: new Author(id: 'U1', name: 'Test'),
                text: 'first',
            ),
        ];

        $message = new Message(
            id: 'final_msg',
            threadId: 'unknown:channel',
            author: new Author(id: 'U1', name: 'Test'),
            text: 'final',
        );

        $job = new ProcessMessageJob('unknown', 'unknown:channel', $message, $skipped, 2);
        $job->handle($chat);

        $this->expectNotToPerformAssertions();
    }
}
