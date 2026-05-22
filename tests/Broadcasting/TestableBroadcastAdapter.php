<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Tests\Broadcasting;

use BootDesk\ChatSDK\Laravel\Broadcasting\LaravelBroadcastAdapter;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;

/**
 * Testable subclass that allows direct injection of mock broadcaster
 */
class TestableBroadcastAdapter extends LaravelBroadcastAdapter
{
    private Broadcaster $injectedBroadcaster;

    public function __construct(
        Broadcaster $mockBroadcaster,
        BroadcastManager $broadcastManager,
        string $channelPrefix = 'chat',
        string $threadChannelType = 'public',
        string $userChannelType = 'private',
        string $broadcasterType = 'test',
    ) {
        parent::__construct($broadcastManager, $broadcasterType, $channelPrefix, $threadChannelType, $userChannelType);

        $this->injectedBroadcaster = $mockBroadcaster;
    }

    public function connect(): void
    {
        $this->broadcaster = $this->injectedBroadcaster;
        $this->connected = true;
    }
}
