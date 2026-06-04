<?php

declare(strict_types=1);

return [

    'user_name' => env('BOT_USERNAME', 'Bot'),

    /*
    |--------------------------------------------------------------------------
    | Platform Adapters
    |--------------------------------------------------------------------------
    |
    | List of platform adapters to enable. Only adapters whose Composer
    | package is installed (class_exists) will be loaded. For multi-tenant
    | setups, omit the platform here and use an AdapterResolver instead.
    |
    */
    'adapters' => [
        // 'slack' => [
        //     'bot_token' => env('SLACK_BOT_TOKEN'),
        //     'signing_secret' => env('SLACK_SIGNING_SECRET'),
        // ],
        // 'telegram' => [
        //     'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        // ],
        // 'whatsapp' => [
        //     'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        //     'app_secret' => env('WHATSAPP_APP_SECRET'),
        //     'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        //     'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        // ],
        // 'discord' => [
        //     'bot_token' => env('DISCORD_BOT_TOKEN'),
        //     'application_id' => env('DISCORD_APPLICATION_ID'),
        //     'public_key' => env('DISCORD_PUBLIC_KEY'),
        // ],
        // 'messenger' => [
        //     'page_access_token' => env('MESSENGER_PAGE_ACCESS_TOKEN'),
        //     'app_secret' => env('MESSENGER_APP_SECRET'),
        //     'verify_token' => env('MESSENGER_VERIFY_TOKEN'),
        // ],
        // 'web' => [
        //     'user_name' => env('BOT_USERNAME', 'Bot'),
        //     'config' => \App\Chat\WebAdapterConfig::class,  // extends BootDesk\ChatSDK\Web\WebAdapterConfig
        //     'broadcaster' => fn () => app(\BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter::class),
        //     'async_mode' => env('CHAT_WEB_ASYNC_MODE', false),
        // ],
        // 'github' => [
        //     'auth_token' => env('GITHUB_TOKEN'),
        //     'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        // ],
        // 'linear' => [
        //     'api_key' => env('LINEAR_API_KEY'),
        //     'webhook_secret' => env('LINEAR_WEBHOOK_SECRET'),
        // ],
        // 'telnyx' => [
        //     'api_key' => env('TELNYX_API_KEY'),
        //     'messaging_profile_id' => env('TELNYX_MESSAGING_PROFILE_ID'),
        //     'public_key' => env('TELNYX_PUBLIC_KEY'),
        //     'from_number' => env('TELNYX_FROM_NUMBER'),
        //     'agent_id' => env('TELNYX_AGENT_ID'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | State Adapter
    |--------------------------------------------------------------------------
    |
    | State persistence backed by the Laravel Cache facade. The cache store
    | is resolved from the facade at runtime — configure it in config/cache.php
    | as usual.
    |
    */
    'state' => [
        'prefix' => env('CHAT_STATE_PREFIX', 'chat:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Handler Classes
    |--------------------------------------------------------------------------
    |
    | Classes that register message handlers on every Chat instance regardless
    | of adapter. Each class must implement a register($chat) method.
    |
    */
    'handlers' => [
        // \App\Chat\GlobalHandlers::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Adapter-specific Handler Groups
    |--------------------------------------------------------------------------
    |
    | Handler classes grouped by adapter name. Only the matching group is
    | registered per webhook request, alongside the global handlers above.
    | Each class must implement a register($chat) method.
    |
    */
    'handler_groups' => [
        // 'slack' => [
        //     \App\Chat\SlackHandler::class,
        // ],
        // 'telegram' => [
        //     \App\Chat\TelegramHandler::class,
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency Strategy
    |--------------------------------------------------------------------------
    |
    | How to handle concurrent messages for the same thread:
    | - drop: Discard new messages while one is being processed
    | - queue: Queue messages and process sequentially
    | - debounce: Reset timer, process only the latest
    | - concurrent: Process all messages simultaneously
    |
    */
    'concurrency' => env('CHAT_CONCURRENCY', 'drop'),

    /*
    |--------------------------------------------------------------------------
    | Lock Scope
    |--------------------------------------------------------------------------
    |
    | Scope for distributed locks: 'thread' (default) or 'channel'.
    | Use 'channel' for platforms like WhatsApp/Telegram where
    | conversations are per-channel (one conversation per phone number).
    |
    */
    'lock_scope' => env('CHAT_LOCK_SCOPE', 'thread'),

    /*
    |--------------------------------------------------------------------------
    | Transcripts
    |--------------------------------------------------------------------------
    |
    | Cross-platform per-user message persistence. Requires an
    | identity resolver bound to 'chat.identity' in a service provider.
    |
    */
    'transcripts' => null,

];
