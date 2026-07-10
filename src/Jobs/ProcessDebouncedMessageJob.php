<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Jobs;

use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class ProcessDebouncedMessageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $adapterName,
        private readonly string $threadId,
        private readonly string $debounceKey,
        private readonly int $debounceMs,
        private readonly ?RequestContext $requestContext = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->debounceKey;
    }

    public function handle(ChatFactory $chatFactory): void
    {
        $logger = $this->resolveLogger();
        $logger->debug('Processing debounced message', [
            'adapter' => $this->adapterName,
            'thread' => $this->threadId,
            'debounce_key' => $this->debounceKey,
        ]);

        $request = $this->requestContext?->toPsrRequest();
        $groups = $request?->getAttribute('chat_groups') ?? [$this->adapterName];
        $chat = $chatFactory->forGroups($groups, $request);
        $adapter = $chat->resolveAdapter($this->adapterName, $request);

        if (! $adapter instanceof Adapter) {
            $logger->warning('Adapter not resolvable for debounced message', [
                'adapter' => $this->adapterName,
            ]);

            return;
        }

        $message = $chat->state->get("{$this->debounceKey}:latest");
        $skipped = $chat->state->get("{$this->debounceKey}:skipped");
        $lastTimestamp = $chat->state->get("{$this->debounceKey}:last");

        $chat->state->delete("{$this->debounceKey}:latest");
        $chat->state->delete("{$this->debounceKey}:skipped");
        $chat->state->delete("{$this->debounceKey}:last");

        if (! $message instanceof Message) {
            $logger->debug('No latest message found, skipping', [
                'debounce_key' => $this->debounceKey,
            ]);

            return;
        }

        $windowEnd = $lastTimestamp !== null
            ? (float) $lastTimestamp + ($this->debounceMs / 1000)
            : 0.0;

        if (microtime(true) < $windowEnd) {
            $remainingMs = (int) ceil(($windowEnd - microtime(true)) * 1000);

            $logger->debug('Debounce window still open, re-dispatching', [
                'debounce_key' => $this->debounceKey,
                'remaining_ms' => $remainingMs,
            ]);

            $ttl = $remainingMs + 5000;
            $chat->state->set("{$this->debounceKey}:latest", $message, $ttl);
            if (is_array($skipped)) {
                $chat->state->set("{$this->debounceKey}:skipped", $skipped, $ttl);
            }

            Bus::dispatch(tap(
                new self($this->adapterName, $this->threadId, $this->debounceKey, $this->debounceMs, $this->requestContext),
                fn (self $job) => $job->delay(now()->addMilliseconds(max(1, $remainingMs))),
            ));

            return;
        }

        $logger->debug('Processing debounced message now', [
            'debounce_key' => $this->debounceKey,
            'message_id' => $message->id,
        ]);

        $chat->processMessageInJob(
            adapter: $adapter,
            threadId: $this->threadId,
            message: $message,
            skippedMessages: is_array($skipped) ? $skipped : [],
            totalSinceLastHandler: (is_array($skipped) ? count($skipped) : 0) + 1,
        );

        $logger->debug('Debounced message processed', [
            'debounce_key' => $this->debounceKey,
        ]);
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
}
