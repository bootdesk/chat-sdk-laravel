<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Tests\Helpers\MemoryStateAdapter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use BootDesk\ChatSDK\Laravel\Notifications\ChatChannel;
use BootDesk\ChatSDK\Laravel\Notifications\ChatRoute;
use Illuminate\Notifications\Notification;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ChatChannelTest extends TestCase
{
    private Chat $chat;

    private ChatChannel $channel;

    protected function setUp(): void
    {
        $this->chat = new Chat(
            state: new MemoryStateAdapter,
            adapters: ['mock' => new class implements Adapter
            {
                public function getName(): string
                {
                    return 'mock';
                }

                public function getBotUserId(): ?string
                {
                    return 'BOT';
                }

                public function verifyWebhook(ServerRequestInterface $r): ?ResponseInterface
                {
                    return null;
                }

                public function parseWebhook(ServerRequestInterface $r): Message
                {
                    return new Message('', '', new Author(''), '');
                }

                public function encodeThreadId(mixed $d): string
                {
                    return 'mock:DM:U1';
                }

                public function decodeThreadId(string $id): mixed
                {
                    return ['channel' => 'DM', 'thread_ts' => ''];
                }

                public function channelIdFromThreadId(string $id): string
                {
                    return 'DM';
                }

                public function postMessage(string $t, PostableMessage $m): SentMessage
                {
                    return new SentMessage('s1', $t);
                }

                public function editMessage(string $t, string $i, PostableMessage $m): SentMessage
                {
                    return new SentMessage($i, $t);
                }

                public function deleteMessage(string $t, string $i): void {}

                public function addReaction(string $t, string $i, string $e): void {}

                public function removeReaction(string $t, string $i, string $e): void {}

                public function startTyping(string $t): void {}

                public function fetchMessages(string $t, ?FetchOptions $o = null): FetchResult
                {
                    return new FetchResult([]);
                }

                public function fetchThread(string $t): ThreadInfo
                {
                    return new ThreadInfo($t, '');
                }

                public function fetchChannelInfo(string $c): ?ChannelInfo
                {
                    return null;
                }

                public function getUser(string $u): ?UserInfo
                {
                    return null;
                }

                public function openDM(string $u): ?string
                {
                    return 'mock:DM:'.$u;
                }

                public function getFormatConverter(): ?FormatConverter
                {
                    return null;
                }

                public function initialize(Chat $chat): void {}

                public function disconnect(): void {}

                public function stream(string $t, iterable $s, array $o = []): ?SentMessage
                {
                    return null;
                }

                public function createResponse(): ?ResponseInterface
                {
                    return null;
                }
            }],
            responseFactory: new Psr17Factory,
        );

        $chatFactory = $this->createMock(ChatFactory::class);
        $chatFactory->method('default')->willReturn($this->chat);

        $this->channel = new ChatChannel($chatFactory);
    }

    public function test_send_without_to_chat_method(): void
    {
        $notification = new Notification;
        $result = $this->channel->send($this->createNotifiable(ChatRoute::thread('mock:DM:U1')), $notification);
        $this->assertNull($result);
    }

    public function test_send_without_route(): void
    {
        $notification = new class extends Notification
        {
            public function toChat($notifiable): PostableMessage
            {
                return PostableMessage::text('test');
            }
        };

        $result = $this->channel->send($this->createNotifiable(null), $notification);
        $this->assertNull($result);
    }

    public function test_send_thread_route(): void
    {
        $notification = new class extends Notification
        {
            public function toChat($notifiable): PostableMessage
            {
                return PostableMessage::text('hello');
            }
        };

        $result = $this->channel->send(
            $this->createNotifiable(ChatRoute::thread('mock:DM:U1')),
            $notification,
        );

        $this->assertNotNull($result);
        $this->assertSame('s1', $result->id);
    }

    public function test_send_channel_route(): void
    {
        $notification = new class extends Notification
        {
            public function toChat($notifiable): PostableMessage
            {
                return PostableMessage::text('hello');
            }
        };

        $result = $this->channel->send(
            $this->createNotifiable(ChatRoute::channel('mock:C123')),
            $notification,
        );

        $this->assertNotNull($result);
        $this->assertSame('s1', $result->id);
    }

    public function test_send_dm_route(): void
    {
        $notification = new class extends Notification
        {
            public function toChat($notifiable): PostableMessage
            {
                return PostableMessage::text('hello');
            }
        };

        $result = $this->channel->send(
            $this->createNotifiable(ChatRoute::dm('mock:U1')),
            $notification,
        );

        $this->assertNotNull($result);
        $this->assertSame('s1', $result->id);
    }

    public function test_send_string_to_chat_converts_to_postable(): void
    {
        $notification = new class extends Notification
        {
            public function toChat($notifiable): string
            {
                return 'plain string';
            }
        };

        $result = $this->channel->send(
            $this->createNotifiable(ChatRoute::thread('mock:DM:U1')),
            $notification,
        );

        $this->assertNotNull($result);
    }

    private function createNotifiable(?ChatRoute $route): object
    {
        return new class($route)
        {
            public function __construct(private readonly ?ChatRoute $route) {}

            public function routeNotificationFor(string $driver, $notification = null): mixed
            {
                return $this->route;
            }
        };
    }
}
