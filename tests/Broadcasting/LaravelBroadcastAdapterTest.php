<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Tests\Broadcasting;

use BootDesk\ChatSDK\Core\Broadcasting\MessagePostedEvent;
use BootDesk\ChatSDK\Core\Broadcasting\TypingStartedEvent;
use BootDesk\ChatSDK\Laravel\Broadcasting\LaravelBroadcastAdapter;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Facades\Broadcast;
use Orchestra\Testbench\TestCase;

class LaravelBroadcastAdapterTest extends TestCase
{
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        $broadcaster = $this->createMock(Broadcaster::class);
        $broadcaster->method('broadcast')
            ->willReturnCallback(function (array $channels, $event, array $payload = []): void {
                $this->captured[] = ['channels' => $channels, 'event' => $event, 'payload' => $payload];
            });

        Broadcast::shouldReceive('connection')
            ->with('test')
            ->andReturn($broadcaster);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->captured = [];
    }

    public function test_connect_and_disconnect(): void
    {
        $adapter = new LaravelBroadcastAdapter(broadcasterType: 'test');
        $adapter->connect();
        $adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_is_broadcasting_available(): void
    {
        $adapter = new LaravelBroadcastAdapter(broadcasterType: 'test');
        $this->assertTrue($adapter->isBroadcastingAvailable('web:u1:c1'));
    }

    public function test_broadcast_sends_to_correct_channel_and_event(): void
    {
        $adapter = new LaravelBroadcastAdapter(
            broadcasterType: 'test',
            channelPrefix: 'chat',
        );

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot', 'name' => 'Bot'],
        );

        $adapter->broadcast('web:u1:c1', $event);

        $this->assertCount(1, $this->captured);
        $this->assertCount(1, $this->captured[0]['channels']);
        $this->assertInstanceOf(Channel::class, $this->captured[0]['channels'][0]);
        $this->assertSame('chat.web:u1:c1', $this->captured[0]['channels'][0]->name);
        $this->assertSame('chat.message.posted', $this->captured[0]['event']);
        $this->assertSame('message.posted', $this->captured[0]['payload']['type']);
    }

    public function test_broadcast_to_user_sends_to_private_channel(): void
    {
        $adapter = new LaravelBroadcastAdapter(
            broadcasterType: 'test',
            channelPrefix: 'chat',
        );

        $event = new TypingStartedEvent(threadId: 'web:u1:c1', userId: 'u1');

        $adapter->broadcastToUser('web:u1:c1', 'u1', $event);

        $this->assertCount(1, $this->captured);
        $this->assertCount(1, $this->captured[0]['channels']);
        $this->assertInstanceOf(PrivateChannel::class, $this->captured[0]['channels'][0]);
        $this->assertSame('private-chat.web:u1:c1.u1', $this->captured[0]['channels'][0]->name);
        $this->assertSame('chat.typing.started', $this->captured[0]['event']);
    }

    public function test_broadcast_to_multiple_users(): void
    {
        $adapter = new LaravelBroadcastAdapter(
            broadcasterType: 'test',
            channelPrefix: 'chat',
        );

        $event = new TypingStartedEvent(threadId: 'web:u1:c1', userId: 'u1');

        $adapter->broadcastToUser('web:u1:c1', ['u1', 'u2'], $event);

        $this->assertCount(2, $this->captured);
        $this->assertSame('chat.typing.started', $this->captured[1]['event']);
    }

    public function test_custom_channel_prefix(): void
    {
        $adapter = new LaravelBroadcastAdapter(
            broadcasterType: 'test',
            channelPrefix: 'myapp',
        );

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot'],
        );

        $adapter->broadcast('web:u1:c1', $event);

        $this->assertCount(1, $this->captured);
        $this->assertSame('myapp.web:u1:c1', $this->captured[0]['channels'][0]->name);
        $this->assertSame('myapp.message.posted', $this->captured[0]['event']);
    }

    public function test_broadcast_to_user_with_presence_channel(): void
    {
        $adapter = new LaravelBroadcastAdapter(
            broadcasterType: 'test',
            channelPrefix: 'chat',
            userChannelType: 'presence',
        );

        $event = new TypingStartedEvent(threadId: 'web:u1:c1', userId: 'u1');

        $adapter->broadcastToUser('web:u1:c1', 'u1', $event);

        $this->assertCount(1, $this->captured);
        $this->assertInstanceOf(PresenceChannel::class, $this->captured[0]['channels'][0]);
        $this->assertSame('presence-chat.web:u1:c1.u1', $this->captured[0]['channels'][0]->name);
    }

    public function test_broadcast_to_multiple_users_with_presence_channel(): void
    {
        $adapter = new LaravelBroadcastAdapter(
            broadcasterType: 'test',
            channelPrefix: 'chat',
            userChannelType: 'presence',
        );

        $event = new TypingStartedEvent(threadId: 'web:u1:c1', userId: 'u1');

        $adapter->broadcastToUser('web:u1:c1', ['u1', 'u2'], $event);

        $this->assertInstanceOf(PresenceChannel::class, $this->captured[1]['channels'][0]);
        $this->assertSame('chat.typing.started', $this->captured[1]['event']);
    }

    public function test_broadcast_thread_with_private_channel(): void
    {
        $adapter = new LaravelBroadcastAdapter(
            broadcasterType: 'test',
            channelPrefix: 'chat',
            threadChannelType: 'private',
        );

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot', 'name' => 'Bot'],
        );

        $adapter->broadcast('web:u1:c1', $event);

        $this->assertCount(1, $this->captured);
        $this->assertInstanceOf(PrivateChannel::class, $this->captured[0]['channels'][0]);
        $this->assertSame('private-chat.web:u1:c1', $this->captured[0]['channels'][0]->name);
    }

    public function test_broadcast_thread_with_presence_channel(): void
    {
        $adapter = new LaravelBroadcastAdapter(
            broadcasterType: 'test',
            channelPrefix: 'chat',
            threadChannelType: 'presence',
        );

        $event = new MessagePostedEvent(
            threadId: 'web:u1:c1',
            messageId: 'msg-1',
            text: 'Hello',
            author: ['id' => 'bot', 'name' => 'Bot'],
        );

        $adapter->broadcast('web:u1:c1', $event);

        $this->assertCount(1, $this->captured);
        $this->assertInstanceOf(PresenceChannel::class, $this->captured[0]['channels'][0]);
        $this->assertSame('presence-chat.web:u1:c1', $this->captured[0]['channels'][0]->name);
    }
}
