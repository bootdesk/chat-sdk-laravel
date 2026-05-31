# bootdesk/chat-sdk-laravel

Laravel integration for laravel-bootdesk.

## Install

```bash
composer require bootdesk/chat-sdk-laravel
```

## Setup

```bash
php artisan chat:install
```

This publishes `config/chat.php` to your application.

## Configuration

The published `config/chat.php` file contains the following sections:

```php
return [

    // The display name your bot uses when posting messages.
    'user_name' => env('BOT_USERNAME', 'Bot'),

    // Platform adapters to enable. Only adapters whose Composer package
    // is installed (class_exists) will be loaded. For multi-tenant
    // setups, omit the platform here and use an AdapterResolver instead.
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
        // ],
        // 'github' => [
        //     'auth_token' => env('GITHUB_TOKEN'),
        //     'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        // ],
        // 'linear' => [
        //     'api_key' => env('LINEAR_API_KEY'),
        //     'webhook_secret' => env('LINEAR_WEBHOOK_SECRET'),
        // ],
    ],

    // Cache store used for state persistence. Any Laravel cache store
    // works: file, redis, database, memcached, array. Configure the
    // store in config/cache.php as usual.
    'state' => [
        'store' => env('CHAT_STATE_STORE', 'file'),
        'prefix' => env('CHAT_STATE_PREFIX', 'chat:'),
    ],

    // Global handler classes registered on every Chat instance regardless
    // of adapter. Each class must implement a register($chat) method.
    'handlers' => [
        // \App\Chat\GlobalHandlers::class,
    ],

    // Adapter-specific handler groups. Only the matching group is
    // registered per webhook request, alongside global handlers above.
    'handler_groups' => [
        // 'slack' => [
        //     \App\Chat\SlackHandler::class,
        // ],
        // 'telegram' => [
        //     \App\Chat\TelegramHandler::class,
        // ],
    ],

    // How to handle concurrent messages for the same thread.
    // Core strategies: drop (default), queue, debounce, concurrent.
    // Laravel uses QueueConcurrencyHandler to dispatch jobs for async processing.
    'concurrency' => env('CHAT_CONCURRENCY', 'drop'),

    // Scope for distributed locks: 'thread' (default) or 'channel'.
    // Use 'channel' for platforms like WhatsApp/Telegram where
    // conversations are per-channel (one conversation per phone number).
    'lock_scope' => env('CHAT_LOCK_SCOPE', 'thread'),

    // Cross-platform per-user message persistence. Requires an
    // identity resolver bound to 'chat.identity' in a service provider.
    'transcripts' => null,

];
```

## Webhook Routes

Register a webhook route for incoming platform events:

```php
// routes/web.php or routes/api.php
use BootDesk\ChatSDK\Laravel\Http\Controllers\WebhookController;

Route::match(['get', 'post'], '/api/webhooks/{adapter}', WebhookController::class);
```

The `{adapter}` segment matches the keys in your `config/chat.php` adapters array (e.g. `slack`, `telegram`, `discord`).

## Handlers

Create a handler class to respond to messages:

```php
// app/Chat/ChatHandlers.php
namespace App\Chat;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\MessageContext;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandler as ChatHandlerContract;

class ChatHandlers implements ChatHandlerContract
{
    public function register(Chat $chat): void
    {
        $chat->onNewMessage('/^hello$/i', function (MessageContext $ctx) {
            $ctx->thread->post('Hey!');
        });

        $chat->fallback(function (MessageContext $ctx) {
            $ctx->thread->post("I don't understand that.");
        });
    }
}
```

Register it in `config/chat.php`:

```php
// Global — fires for every adapter
'handlers' => [\App\Chat\ChatHandlers::class],
```

Or scoped to a specific adapter group:

```php
'handler_groups' => [
    'slack' => [\App\Chat\SlackHandlers::class],
    'telegram' => [\App\Chat\TelegramHandlers::class],
],
```

When a webhook arrives for `slack`, both `global` and `slack` group handlers are registered. `telegram` group handlers are skipped.

## Middleware

Middleware intercept messages at different stages. Register in your handler class:

```php
use BootDesk\ChatSDK\Core\Contracts\WebhookMiddleware;
use BootDesk\ChatSDK\Core\Contracts\ReceivingMiddleware;
use BootDesk\ChatSDK\Core\Contracts\SendingMiddleware;

class ChatHandlers
{
    public function register(Chat $chat): void
    {
        // Intercept raw webhook before parsing
        $chat->addWebhookMiddleware(new class implements WebhookMiddleware {
            public function handle(ServerRequestInterface $request, callable $next): ResponseInterface {
                logger()->info('Webhook received', ['path' => $request->getUri()->getPath()]);
                return $next($request);
            }
        });

        // Transform inbound messages before handlers
        $chat->addReceivingMiddleware(new class implements ReceivingMiddleware {
            public function handle(Message $message, Adapter $adapter, callable $next): ?Message {
                // Return null to drop the message
                if (str_contains($message->text, 'blocked')) {
                    return null;
                }
                return $next($message);
            }
        });

        // Transform outbound messages before delivery
        $chat->addSendingMiddleware(new class implements SendingMiddleware {
            public function handle(string $threadId, PostableMessage $message, Adapter $adapter, string $operation, callable $next): ?SentMessage {
                logger()->info('Sending message', ['thread' => $threadId, 'operation' => $operation]);
                return $next($message);
            }
        });
    }
}
```

**Operations:** `post`, `edit`, `postEphemeral`

## Multi-Tenant Adapter Resolution

For multi-tenant applications where each tenant has their own bot credentials, use an `AdapterResolver`:

```php
// app/Chat/MultiTenantAdapterResolver.php
namespace App\Chat;

use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\AdapterResolver;
use BootDesk\ChatSDK\Slack\SlackAdapter;
use BootDesk\ChatSDK\Telegram\TelegramAdapter;
use Illuminate\Support\Facades\DB;
use Psr\Http\Message\ServerRequestInterface;

class MultiTenantAdapterResolver implements AdapterResolver
{
    public function resolve(string $name, ?ServerRequestInterface $request): ?Adapter
    {
        // Extract tenant from request (header, subdomain, route param, etc.)
        // When called from a job, $request is null - use other context (job payload, auth, etc.)
        $tenantId = $request?->getHeaderLine('X-Tenant-ID')
            ?? $this->getTenantFromContext();

        if ($tenantId === null || $tenantId === '') {
            return null;
        }

        // Load tenant-specific credentials from database
        $config = DB::table('tenant_chat_configs')
            ->where('tenant_id', $tenantId)
            ->where('adapter', $name)
            ->first();

        if (! $config) {
            return null;
        }

        // Instantiate adapter with tenant credentials
        return match ($name) {
            'slack' => new SlackAdapter(
                botToken: $config->credentials['bot_token'],
                httpClient: app(\Psr\Http\Client\ClientInterface::class),
                signingSecret: $config->credentials['signing_secret'] ?? null,
            ),
            'telegram' => new TelegramAdapter(
                botToken: $config->credentials['bot_token'],
                httpClient: app(\Psr\Http\Client\ClientInterface::class),
                secretToken: $config->credentials['secret_token'] ?? null,
            ),
            default => null,
        };
    }
}
```

Register the resolver in a service provider:

```php
// app/Providers/AppServiceProvider.php
use BootDesk\ChatSDK\Core\Contracts\AdapterResolver;

public function register(): void
{
    $this->app->bind(
        AdapterResolver::class,
        \App\Chat\MultiTenantAdapterResolver::class
    );
}
```

**Resolution order:** Tenant-specific (resolver) → Global (config). Tenants can override specific adapters while falling back to global defaults for others.

## Injecting ChatFactory

To send messages programmatically, inject `ChatFactory` and get a Chat instance:

```php
use BootDesk\ChatSDK\Laravel\ChatFactory;

class MessageController
{
    public function __construct(
        private ChatFactory $chatFactory,
    ) {}

    public function send()
    {
        $chat = $this->chatFactory->default(); // global handlers only
        $chat->thread('slack:C123')->post('Hello!');
    }
}
```

Or for adapter-specific handlers:

```php
$chat = $this->chatFactory->forGroup('slack'); // global + slack handlers
$chat->handleWebhook('slack', $psrRequest);
```

## Artisan Commands

| Command                    | Description              |
| -------------------------- | ------------------------ |
| `php artisan chat:list`    | List configured adapters |
| `php artisan chat:install` | Publish config file      |

## Queue Processing

The package binds `QueueConcurrencyHandler` as the default `ConcurrencyHandler`. It dispatches jobs as follows: `drop` acquires a lock during the webhook (dispatches `ProcessMessageJob` if acquired, drops silently if held — lock released when job finishes); `queue` and `concurrent` dispatch `ProcessMessageJob`; `debounce` dispatches `ProcessDebouncedMessageJob` (unique delayed job). The debounce job caches the latest message and a `:last` timestamp; on re-dispatch it does **not** restore `:last` — preventing infinite re-dispatch loops. `:latest` and `:skipped` restoration is guarded against overwriting concurrent webhook data. `RequiresSyncResponse` adapters always process inline (within the HTTP request) regardless of strategy.

When the original PSR-7 webhook request is available, `QueueConcurrencyHandler` serializes it into a `RequestContext` value object (method, URI, headers, body, query/parsed/server params, cookies, version, **requestAttributes**) and passes it to every dispatched job. Both `ProcessMessageJob` and `ProcessDebouncedMessageJob` reconstruct the PSR-7 request and pass it to `Chat::resolveAdapter()` — so `AdapterResolver::resolve($name, $request)` receives the original request in both sync and queued contexts. The `requestAttributes` field captures PSR-7 `getAttributes()` — extend `WebhookController` to add tenant/context attributes that survive into jobs.

Make sure your Laravel queue worker is running:

```bash
php artisan queue:work
```

No manual setup is needed beyond configuring your queue driver in `config/queue.php`.

## State

State persistence uses Laravel's cache system. Set `CHAT_STATE_STORE` to any Laravel cache driver (`file`, `redis`, `database`, `memcached`, `array`). The cache store is configured in `config/cache.php` as usual.

## Error Handling

Adapter exceptions bubble up to Laravel's exception handler. Register custom handlers in `app/Exceptions/Handler.php`:

```php
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\Exceptions\RateLimitException;
use Illuminate\Http\Request;

public function register(): void
{
    $this->renderable(function (AuthenticationException $e, Request $request) {
        return response()->json(['error' => 'Unauthorized'], 401);
    });

    $this->renderable(function (RateLimitException $e, Request $request) {
        return response()->json(['error' => 'Rate limited'], 429);
    });

    $this->renderable(function (AdapterException $e, Request $request) {
        Log::error('Chat adapter error', [
            'message' => $e->getMessage(),
            'adapter' => $request->route('adapter'),
        ]);

        return response()->json(['error' => 'Adapter failed'], 500);
    });
}
```

**Exception types:**

- `AuthenticationException` — Invalid credentials/tokens
- `RateLimitException` — Platform rate limit exceeded
- `AdapterException` — Generic adapter errors
- `ResourceNotFoundException` — Adapter/thread not found
- `ValidationException` — Invalid input data

## Documentationn

Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
