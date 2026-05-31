# laravel

Laravel integration for the PHP Chat SDK. Namespace: `BootDesk\ChatSDK\Laravel`

## entrypoints
- `ChatServiceProvider` — registers `HandlerRegistry`, `ChatFactory` singletons; binds `ConcurrencyHandler::class` → `QueueConcurrencyHandler`
- `ChatFactory` — composes `Chat` instances scoped to a handler group (`forGroup()`) or global-only (`default()`)
- `HandlerRegistry` — stores handler class names by group (global + per-adapter)
- `Http/Controllers/WebhookController` — single `handle` method, injects `ChatFactory`, calls `forGroup($adapter)`
- `State/CacheStateAdapter` — StateAdapter impl backed by Laravel cache (file, redis, etc.)
- `Concurrency/QueueConcurrencyHandler` — Laravel-specific `ConcurrencyHandler` that dispatches jobs for async processing. `drop` strategy acquires a lock during the webhook: if acquired, dispatches `ProcessMessageJob` (lock released when job finishes); if not acquired, drops silently. Works uniformly across all adapter types (sync, async, unmarked).

## route example (routes/webhooks.php)
```php
Route::match(['get', 'post'], '/api/webhooks/{adapter}/{tenant?}', [WebhookController::class, 'handle'])->name('chat.webhook');
```
Routes are NOT auto-registered — copy into app's routes file.

## config (config/chat.php)
- `adapters` — map of name → config (bot_token, signing_secret, etc.)
- `state.store` — cache store name (default: `file`)
- `state.prefix` — cache key prefix (default: `chat:`)
- `handlers` — global handler classes (always registered)
- `handler_groups` — adapter-scoped handler groups (e.g. `slack => [Handler::class]`)
- `concurrency` — drop/queue/debounce/concurrent (applied via `QueueConcurrencyHandler`)
- `lock_scope` — thread/channel
- `transcripts` — per-user message persistence config (requires `chat.identity` binding)

## adapter resolution
- Reads `config('chat.adapters')` → looks up class via `AdapterRegistry::get($name)` → instantiates with camelCased config keys
- Adaptér auto-discovery works via each adapter's `register.php` autoloaded file
- Injects `FileUploadConverter` from container if bound (for adapters without native file uploads)

## file upload converter
Binary file uploads on platforms without native support (WhatsApp, Messenger, GitHub, Linear, Telnyx, Web) require a `FileUploadConverter`. Register an implementation in any service provider:

```php
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;

public function register(): void
{
    $this->app->bind(FileUploadConverter::class, App\Services\S3FileUploader::class);
}
```

Without a binding, these adapters throw `AdapterException` when `FileUpload` objects are passed.

## notification channel
- `Notifications/ChatChannel` — Laravel notification channel for sending `PostableMessage` via the Chat SDK
- `Notifications/ChatRoute` — DTO for routing notifications (thread, channel, or DM)
- Auto-registered via `ChatServiceProvider::boot()`
- Notifiable model defines `routeNotificationForChat(): ?ChatRoute`
- Notification defines `toChat($notifiable): PostableMessage`

### example
```php
use BootDesk\ChatSDK\Laravel\Notifications\ChatRoute;

class User extends Authenticatable
{
    public function routeNotificationForChat(): ?ChatRoute
    {
        return ChatRoute::dm('slack', $this->slack_id);
        // or ChatRoute::channel('slack', 'C123');
        // or ChatRoute::thread('slack:C123:123.456');
    }
}
```

## messaging window
- `AdapterHasMessagingWindow` — contract for platforms with limited messaging windows (e.g., WhatsApp 24h)
- `TrackMessagingWindow` — receiving middleware; records last message timestamp per user
- `EnforceMessagingWindow` — sending middleware; blocks or converts to template when window expired

### usage
```php
$chat
    ->addReceivingMiddleware(new TrackMessagingWindow($state))
    ->addSendingMiddleware(new EnforceMessagingWindow($state,
        templateFallback: fn (PostableMessage $msg) => PostableMessage::text('A new message is waiting for you.'),
    ));
```

## artisan commands
- `chat:install` — publish config
- `chat:list` — list registered adapters
- `chat:make-adapter` — stub generator for new adapters

## jobs
- `Jobs/ProcessMessageJob` — queueable message processing (dispatched by `QueueConcurrencyHandler` for `queue`/`concurrent` strategies, and by `drop` with lock acquired). `handle(ChatFactory)` resolves a Chat scoped to `$this->adapterName`. Releases the `process:` lock after handling.
- `Jobs/ProcessDebouncedMessageJob` — unique delayed job for `debounce` strategy; fetches latest cached message when run. `handle(ChatFactory)` resolves a Chat scoped to `$this->adapterName`. Does NOT restore `:last` cache key on re-dispatch (prevents infinite loops). `:latest`/`:skipped` restoration guarded against concurrent webhook races.
- `Jobs/RequestContext` — serializable value object capturing PSR-7 request data (method, uri, headers, body, query/parsed/server params, cookies, version, **requestAttributes**). Created by `QueueConcurrencyHandler::process()` from the original webhook request, passed to both job types. `toPsrRequest()` reconstructs a `ServerRequestInterface` in `handle()` — enables `AdapterResolver` to receive the original request even in queued context. The `requestAttributes` field captures PSR-7 `getAttributes()` — developers extending `WebhookController` can add tenant/context attributes to the request that survive serialization into jobs.

## adapter resolution (job context)
Both `ProcessMessageJob` and `ProcessDebouncedMessageJob` pass the reconstructed PSR-7 request to `Chat::resolveAdapter()`. When `AdapterResolver` is registered, `resolve(name, request)` now receives the original request (method, URI, headers, body, query params, parsed body, cookies, server params) in sync AND job contexts. Previously `$request` was always `null` in jobs.

## middleware
- `Http/Middleware/VerifyWebhookSignature` — optional PSR-15 middleware

## testing
- Uses Orchestra Testbench for integration tests
- Laravel versions: ^10.0|^11.0|^12.0|^13.0
- Deps: illuminate/support, illuminate/http, illuminate/routing, illuminate/contracts, illuminate/cache
- symfony/psr-http-message-bridge + nyholm/psr7 for PSR-7 bridge

## notes
- Guzzle bound as default `ClientInterface` via service provider
- Psr17Factory bound for all PSR-17 factories
- Adapter classes receive constructor params camelCased from config (e.g. `bot_token` → `$botToken`)
- `chat.identity` binding: closure `fn(Author $author): ?string` for transcript user resolution
- `ConcurrencyHandler::class` bound to `QueueConcurrencyHandler` — override by rebinding in your service provider if you need custom concurrency behavior
