<?php

namespace BootDesk\ChatSDK\Laravel\Notifications;

use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use Illuminate\Notifications\Notification;

class ChatChannel
{
    public function __construct(
        protected ChatFactory $chatFactory,
    ) {}

    public function send(object $notifiable, Notification $notification): ?SentMessage
    {
        if (! method_exists($notification, 'toChat')) {
            return null;
        }

        $message = $notification->toChat($notifiable);

        if (! $message instanceof PostableMessage) {
            $message = PostableMessage::text((string) $message);
        }

        $route = $notifiable->routeNotificationFor('chat', $notification);

        if (! $route instanceof ChatRoute) {
            return null;
        }

        $chat = $this->chatFactory->default();

        if ($route->threadId !== null) {
            return $chat->thread($route->threadId)->post($message);
        }

        if ($route->channelId !== null) {
            return $chat->channel($route->channelId)->post($message);
        }

        if ($route->userId !== null) {
            $adapter = $chat->resolveAdapter($route->adapter);
            if (! $adapter instanceof Adapter) {
                return null;
            }

            $channelId = $adapter->openDM($route->userId);

            if ($channelId === null) {
                return null;
            }

            return $adapter->postMessage("{$route->adapter}:{$channelId}", $message);
        }

        return null;
    }
}
