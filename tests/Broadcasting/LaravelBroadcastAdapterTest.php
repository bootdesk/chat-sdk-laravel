<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Tests\Broadcasting;

use BootDesk\ChatSDK\Core\Broadcasting\MessagePostedEvent;
use BootDesk\ChatSDK\Core\Broadcasting\TypingStartedEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Orchestra\Testbench\TestCase;

class LaravelBroadcastAdapterTest extends TestCase
{
    private Broadcaster $mockBroadcaster;

    private BroadcastManager $broadcastManager;

    private TestableBroadcastAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->broadcastManager = $this->app->make(BroadcastManager::class);

        $this->mockBroadcaster = new class implements Broadcaster
        {
            public ?array $lastBroadcast = null;

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                $this->lastBroadcast = ['channels' => $channels, 'event' => $event, 'payload' => $payload];
            }

            public function isBroadcasting(): void {}

            public function auth($request)
            {
                return null;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return null;
            }
        };

        $this->adapter = new TestableBroadcastAdapter(
            mockBroadcaster: $this->mockBroadcaster,
            broadcastManager: $this->broadcastManager,
            channelPrefix: 'chat',
        );
    }

    public function test_connect_sets_connection(): void
    {
        $this->adapter->connect();
        $this->assertTrue($this->adapter->isBroadcastingAvailable('web:u1:c1'));
    }

    public function test_disconnect_clears_connection(): void
    {
        $this->adapter->connect();
        $this->adapter->disconnect();
        $this->assertFalse($this->adapter->isBroadcastingAvailable('web:u1:c1'));
    }

    public function test_broadcast_sends_to_correct_channel_and_event(): void
    {
        $this->adapter->connect();

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot', 'name' => 'Bot'],
        );

        $this->adapter->broadcast('web:u1:c1', $event);

        $this->assertCount(1, $this->mockBroadcaster->lastBroadcast['channels']);
        $this->assertInstanceOf(Channel::class, $this->mockBroadcaster->lastBroadcast['channels'][0]);
        $this->assertSame('chat.web:u1:c1', $this->mockBroadcaster->lastBroadcast['channels'][0]->name);
        $this->assertSame('chat.message.posted', $this->mockBroadcaster->lastBroadcast['event']);
        $this->assertSame('message.posted', $this->mockBroadcaster->lastBroadcast['payload']['type']);
    }

    public function test_broadcast_to_user_sends_to_private_channel(): void
    {
        $this->adapter->connect();

        $event = new TypingStartedEvent(
            threadId: 'web:u1:c1',
            userId: 'u1',
        );

        $this->adapter->broadcastToUser('web:u1:c1', 'u1', $event);

        $this->assertCount(1, $this->mockBroadcaster->lastBroadcast['channels']);
        $this->assertInstanceOf(PrivateChannel::class, $this->mockBroadcaster->lastBroadcast['channels'][0]);
        $this->assertSame('private-chat.web:u1:c1.u1', $this->mockBroadcaster->lastBroadcast['channels'][0]->name);
        $this->assertSame('chat.typing.started', $this->mockBroadcaster->lastBroadcast['event']);
    }

    public function test_broadcast_to_multiple_users(): void
    {
        $this->adapter->connect();

        $event = new TypingStartedEvent(
            threadId: 'web:u1:c1',
            userId: 'u1',
        );

        $this->adapter->broadcastToUser('web:u1:c1', ['u1', 'u2'], $event);

        // Should broadcast twice, once for each user
        $this->assertSame('chat.typing.started', $this->mockBroadcaster->lastBroadcast['event']);
    }

    public function test_broadcast_without_connecting_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not connected');

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot'],
        );

        $this->adapter->broadcast('web:u1:c1', $event);
    }

    public function test_is_broadcasting_available_returns_false_when_disconnected(): void
    {
        $this->assertFalse($this->adapter->isBroadcastingAvailable('web:u1:c1'));
    }

    public function test_custom_channel_prefix(): void
    {
        $adapter = new TestableBroadcastAdapter(
            mockBroadcaster: $this->mockBroadcaster,
            broadcastManager: $this->broadcastManager,
            channelPrefix: 'myapp',
        );

        $adapter->connect();

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot'],
        );

        $adapter->broadcast('web:u1:c1', $event);

        $this->assertCount(1, $this->mockBroadcaster->lastBroadcast['channels']);
        $this->assertInstanceOf(Channel::class, $this->mockBroadcaster->lastBroadcast['channels'][0]);
        $this->assertSame('myapp.web:u1:c1', $this->mockBroadcaster->lastBroadcast['channels'][0]->name);
        $this->assertSame('myapp.message.posted', $this->mockBroadcaster->lastBroadcast['event']);
    }

    public function test_connect_is_idempotent(): void
    {
        $this->adapter->connect();
        $this->adapter->connect();
        $this->assertTrue($this->adapter->isBroadcastingAvailable('web:u1:c1'));
    }

    public function test_broadcast_to_user_with_presence_channel(): void
    {
        $adapter = new TestableBroadcastAdapter(
            mockBroadcaster: $this->mockBroadcaster,
            broadcastManager: $this->broadcastManager,
            channelPrefix: 'chat',
            userChannelType: 'presence',
        );
        $adapter->connect();

        $event = new TypingStartedEvent(
            threadId: 'web:u1:c1',
            userId: 'u1',
        );

        $adapter->broadcastToUser('web:u1:c1', 'u1', $event);

        $this->assertCount(1, $this->mockBroadcaster->lastBroadcast['channels']);
        $this->assertInstanceOf(PresenceChannel::class, $this->mockBroadcaster->lastBroadcast['channels'][0]);
        $this->assertSame('presence-chat.web:u1:c1.u1', $this->mockBroadcaster->lastBroadcast['channels'][0]->name);
        $this->assertSame('chat.typing.started', $this->mockBroadcaster->lastBroadcast['event']);
    }

    public function test_broadcast_to_multiple_users_with_presence_channel(): void
    {
        $adapter = new TestableBroadcastAdapter(
            mockBroadcaster: $this->mockBroadcaster,
            broadcastManager: $this->broadcastManager,
            channelPrefix: 'chat',
            userChannelType: 'presence',
        );
        $adapter->connect();

        $event = new TypingStartedEvent(
            threadId: 'web:u1:c1',
            userId: 'u1',
        );

        $adapter->broadcastToUser('web:u1:c1', ['u1', 'u2'], $event);

        $this->assertInstanceOf(PresenceChannel::class, $this->mockBroadcaster->lastBroadcast['channels'][0]);
        $this->assertSame('chat.typing.started', $this->mockBroadcaster->lastBroadcast['event']);
    }

    public function test_broadcast_thread_with_private_channel(): void
    {
        $adapter = new TestableBroadcastAdapter(
            mockBroadcaster: $this->mockBroadcaster,
            broadcastManager: $this->broadcastManager,
            channelPrefix: 'chat',
            threadChannelType: 'private',
        );
        $adapter->connect();

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot', 'name' => 'Bot'],
        );

        $adapter->broadcast('web:u1:c1', $event);

        $this->assertCount(1, $this->mockBroadcaster->lastBroadcast['channels']);
        $this->assertInstanceOf(PrivateChannel::class, $this->mockBroadcaster->lastBroadcast['channels'][0]);
        $this->assertSame('private-chat.web:u1:c1', $this->mockBroadcaster->lastBroadcast['channels'][0]->name);
        $this->assertSame('chat.message.posted', $this->mockBroadcaster->lastBroadcast['event']);
    }

    public function test_broadcast_thread_with_presence_channel(): void
    {
        $adapter = new TestableBroadcastAdapter(
            mockBroadcaster: $this->mockBroadcaster,
            broadcastManager: $this->broadcastManager,
            channelPrefix: 'chat',
            threadChannelType: 'presence',
        );
        $adapter->connect();

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot', 'name' => 'Bot'],
        );

        $adapter->broadcast('web:u1:c1', $event);

        $this->assertCount(1, $this->mockBroadcaster->lastBroadcast['channels']);
        $this->assertInstanceOf(PresenceChannel::class, $this->mockBroadcaster->lastBroadcast['channels'][0]);
        $this->assertSame('presence-chat.web:u1:c1', $this->mockBroadcaster->lastBroadcast['channels'][0]->name);
        $this->assertSame('chat.message.posted', $this->mockBroadcaster->lastBroadcast['event']);
    }
}
