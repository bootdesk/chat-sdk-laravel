<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Laravel\Notifications\ChatRoute;
use PHPUnit\Framework\TestCase;

class ChatRouteTest extends TestCase
{
    public function test_thread_route(): void
    {
        $route = ChatRoute::thread('slack:C123:123.456');

        $this->assertSame('slack:C123:123.456', $route->threadId);
        $this->assertNull($route->channelId);
        $this->assertNull($route->userId);
    }

    public function test_channel_route(): void
    {
        $route = ChatRoute::channel('slack:C123');

        $this->assertSame('slack:C123', $route->channelId);
        $this->assertNull($route->threadId);
        $this->assertNull($route->userId);
    }

    public function test_dm_route(): void
    {
        $route = ChatRoute::dm('slack:U123');

        $this->assertSame('slack:U123', $route->userId);
        $this->assertNull($route->threadId);
        $this->assertNull($route->channelId);
    }
}
