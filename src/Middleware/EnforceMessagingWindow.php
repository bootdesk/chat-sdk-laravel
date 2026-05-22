<?php

namespace BootDesk\ChatSDK\Laravel\Middleware;

use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\AdapterHasMessagingWindow;
use BootDesk\ChatSDK\Core\Contracts\SendingMiddleware;
use BootDesk\ChatSDK\Core\Contracts\StateAdapter;
use BootDesk\ChatSDK\Core\PostableMessage;

class EnforceMessagingWindow implements SendingMiddleware
{
    /**
     * @param  (callable(PostableMessage): ?PostableMessage)|null  $templateFallback
     */
    public function __construct(
        protected StateAdapter $state,
        protected readonly mixed $templateFallback = null,
    ) {}

    public function handle(string $threadId, PostableMessage $message, Adapter $adapter, string $operation, callable $next): ?PostableMessage
    {
        if (! $adapter instanceof AdapterHasMessagingWindow) {
            return $next($threadId, $message, $adapter, $operation);
        }

        $key = "msg_window:{$adapter->getTrackingKey($threadId)}";
        $lastSeen = $this->state->get($key);

        if ($lastSeen === null || ! is_int($lastSeen)) {
            return $next($threadId, $message, $adapter, $operation);
        }

        $windowSeconds = $adapter->getMessagingWindowSeconds();
        $elapsed = time() - $lastSeen;

        if ($elapsed <= $windowSeconds) {
            return $next($threadId, $message, $adapter, $operation);
        }

        if ($this->templateFallback !== null) {
            $template = ($this->templateFallback)($message);

            if ($template instanceof PostableMessage) {
                return $next($threadId, $template, $adapter, $operation);
            }
        }

        return null;
    }
}
