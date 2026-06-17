<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Broadcasting Enabled
    |--------------------------------------------------------------------------
    |
    | Enable or disable event broadcasting. When disabled, the BroadcastAdapter
    | will not be injected into adapters even if configured.
    |
    */

    'enabled' => env('CHAT_BROADCASTING_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default Broadcaster
    |--------------------------------------------------------------------------
    |
    | This option defines the default broadcaster that should be used
    | by the Chat SDK for broadcasting events.
    |
    | Supported: "pusher", "redis", "log", "null"
    |
    */

    'default' => env('CHAT_BROADCASTING_DEFAULT', 'pusher'),

    /*
    |--------------------------------------------------------------------------
    | Channel Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be applied to all broadcast channel names.
    |
    */

    'channel_prefix' => env('CHAT_BROADCASTING_CHANNEL_PREFIX', 'chat'),

    /*
    |--------------------------------------------------------------------------
    | Thread Channel Type
    |--------------------------------------------------------------------------
    |
    | The type of channel to use when broadcasting to threads/conversations.
    | Presence channels enable presence features (who's online, typing status).
    |
    | Supported: "public", "private", "presence"
    |
    */

    'thread_channel_type' => env('CHAT_BROADCASTING_THREAD_CHANNEL_TYPE', 'public'),

    /*
    |--------------------------------------------------------------------------
    | User Channel Type
    |--------------------------------------------------------------------------
    |
    | The type of channel to use when broadcasting to specific users.
    | Presence channels enable presence features (who's online, typing status).
    |
    | Supported: "private", "presence"
    |
    */

    'user_channel_type' => env('CHAT_BROADCASTING_USER_CHANNEL_TYPE', 'private'),

    /*
    |--------------------------------------------------------------------------
    | Use Hashed Channel Names
    |--------------------------------------------------------------------------
    |
    | When enabled, channel names will use a SHA-256 hash of the threadId
    | instead of the raw threadId. This is useful when threadIds contain
    | characters incompatible with the broadcaster (e.g., Pusher only allows
    | [-_\.a-zA-Z0-9]).
    |
    */

    'use_hash_channel' => env('CHAT_BROADCASTING_USE_HASH_CHANNEL', false),
];
