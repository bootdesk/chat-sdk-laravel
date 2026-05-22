<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\AdapterHasMessagingWindow;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Tests\Helpers\MemoryStateAdapter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use BootDesk\ChatSDK\Laravel\Middleware\EnforceMessagingWindow;
use BootDesk\ChatSDK\Laravel\Middleware\TrackMessagingWindow;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MiddlewareTest extends TestCase
{
    private MemoryStateAdapter $state;

    protected function setUp(): void
    {
        $this->state = new MemoryStateAdapter;
    }

    private function windowedAdapter(string $trackingKey = 'whatsapp:user123'): AdapterHasMessagingWindow&Adapter
    {
        return new class($trackingKey) implements Adapter, AdapterHasMessagingWindow
        {
            public function __construct(private readonly string $trackingKey) {}

            public function getName(): string
            {
                return 'whatsapp';
            }

            public function getBotUserId(): ?string
            {
                return null;
            }

            public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
            {
                return null;
            }

            public function parseWebhook(ServerRequestInterface $request): Message
            {
                return new Message('', '', new Author(''), '');
            }

            public function encodeThreadId(mixed $platformData): string
            {
                return 'whatsapp:123:user123';
            }

            public function decodeThreadId(string $threadId): mixed
            {
                return ['channel' => '', 'thread_ts' => ''];
            }

            public function channelIdFromThreadId(string $threadId): string
            {
                return '';
            }

            public function postMessage(string $threadId, PostableMessage $message): SentMessage
            {
                return new SentMessage('', $threadId);
            }

            public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
            {
                return new SentMessage($messageId, $threadId);
            }

            public function deleteMessage(string $threadId, string $messageId): void {}

            public function addReaction(string $threadId, string $messageId, string $emoji): void {}

            public function removeReaction(string $threadId, string $messageId, string $emoji): void {}

            public function startTyping(string $threadId): void {}

            public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
            {
                return new FetchResult([]);
            }

            public function fetchThread(string $threadId): ThreadInfo
            {
                return new ThreadInfo($threadId, '');
            }

            public function fetchChannelInfo(string $channelId): ?ChannelInfo
            {
                return null;
            }

            public function getUser(string $userId): ?UserInfo
            {
                return null;
            }

            public function openDM(string $userId): ?string
            {
                return null;
            }

            public function getFormatConverter(): ?FormatConverter
            {
                return null;
            }

            public function initialize(Chat $chat): void {}

            public function disconnect(): void {}

            public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
            {
                return null;
            }

            public function createResponse(): ?ResponseInterface
            {
                return null;
            }

            public function getMessagingWindowSeconds(): ?int
            {
                return 86400;
            }

            public function getTrackingKey(string $threadId): string
            {
                return $this->trackingKey;
            }
        };
    }

    public function test_track_records_timestamp(): void
    {
        $middleware = new TrackMessagingWindow($this->state);

        $message = new Message(
            id: 'm1',
            threadId: 'whatsapp:123:user123',
            author: new Author(id: 'user123'),
            text: 'hello',
        );

        $called = false;
        $result = $middleware->handle($message, $this->windowedAdapter(), function ($msg) use (&$called) {
            $called = true;

            return $msg;
        });

        $this->assertNotNull($result);
        $this->assertTrue($called);
        $this->assertNotNull($this->state->get('msg_window:whatsapp:user123'));
    }

    public function test_track_skips_non_windowed_adapters(): void
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('getName')->willReturn('slack');

        $middleware = new TrackMessagingWindow($this->state);

        $message = new Message(id: 'm1', threadId: 'slack:C123', author: new Author(id: 'U1'), text: 'hi');

        $result = $middleware->handle($message, $adapter, fn ($m) => $m);

        $this->assertNotNull($result);
    }

    public function test_enforce_allows_within_window(): void
    {
        $this->state->set('msg_window:whatsapp:user123', time(), 86400);

        $middleware = new EnforceMessagingWindow($this->state);

        $nextCalled = false;
        $middleware->handle(
            'whatsapp:123:user123',
            PostableMessage::text('test'),
            $this->windowedAdapter(),
            'post',
            function () use (&$nextCalled) {
                $nextCalled = true;

                return null;
            },
        );

        $this->assertTrue($nextCalled);
    }

    public function test_enforce_blocks_outside_window(): void
    {
        $this->state->set('msg_window:whatsapp:user123', 0, 86400);

        $middleware = new EnforceMessagingWindow($this->state);

        $nextCalled = false;
        $result = $middleware->handle(
            'whatsapp:123:user123',
            PostableMessage::text('test'),
            $this->windowedAdapter(),
            'post',
            function () use (&$nextCalled) {
                $nextCalled = true;

                return null;
            },
        );

        $this->assertFalse($nextCalled);
        $this->assertNull($result);
    }

    public function test_enforce_fallback_converts_message(): void
    {
        $this->state->set('msg_window:whatsapp:user123', 0, 86400);

        $middleware = new EnforceMessagingWindow(
            $this->state,
            templateFallback: fn (PostableMessage $msg) => PostableMessage::text('[Template] '.$msg->getTextContent()),
        );

        $captured = null;
        $result = $middleware->handle(
            'whatsapp:123:user123',
            PostableMessage::text('original'),
            $this->windowedAdapter(),
            'post',
            function ($t, PostableMessage $m) use (&$captured) {
                $captured = $m;

                return $m;
            },
        );

        $this->assertNotNull($captured);
        $this->assertSame('[Template] original', $captured->getTextContent());
        $this->assertSame('[Template] original', $result->getTextContent());
    }

    public function test_enforce_skips_non_windowed(): void
    {
        $adapter = $this->createMock(Adapter::class);

        $middleware = new EnforceMessagingWindow($this->state);

        $nextCalled = false;
        $middleware->handle(
            'slack:C123',
            PostableMessage::text('test'),
            $adapter,
            'post',
            function () use (&$nextCalled) {
                $nextCalled = true;

                return null;
            },
        );

        $this->assertTrue($nextCalled);
    }

    public function test_enforce_allows_when_no_timestamp(): void
    {
        $middleware = new EnforceMessagingWindow($this->state);

        $nextCalled = false;
        $middleware->handle(
            'whatsapp:123:user456',
            PostableMessage::text('test'),
            $this->windowedAdapter('whatsapp:user456'),
            'post',
            function () use (&$nextCalled) {
                $nextCalled = true;

                return null;
            },
        );

        $this->assertTrue($nextCalled);
    }
}
