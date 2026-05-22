<?php

namespace BootDesk\ChatSDK\Laravel\Middleware;

use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\AdapterHasMessagingWindow;
use BootDesk\ChatSDK\Core\Contracts\ReceivingMiddleware;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\Message;

class TrackMessagingWindow implements ReceivingMiddleware
{
    public function __construct(
        protected StateAdapter $state,
    ) {}

    public function handle(Message $message, Adapter $adapter, callable $next): ?Message
    {
        if ($adapter instanceof AdapterHasMessagingWindow) {
            $key = "msg_window:{$adapter->getTrackingKey($message->threadId)}";
            $this->state->set($key, time(), (int) ($adapter->getMessagingWindowSeconds() * 1.5));
        }

        return $next($message, $adapter);
    }
}
