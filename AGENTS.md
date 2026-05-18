# laravel

Laravel integration for the PHP Chat SDK. Namespace: `BootDesk\ChatSDK\Laravel`

## entrypoints
- `ChatServiceProvider` — registers Chat singleton, `Chat::class` alias, `chat` alias
- `ChatFacade` — `Chat` facade
- `Http/Controllers/WebhookController` — single `handle` method for all platform webhooks
- `State/CacheStateAdapter` — StateAdapter impl backed by Laravel cache (file, redis, etc.)

## route example (routes/webhooks.php)
```php
Route::match(['get', 'post'], '/api/webhooks/{adapter}/{tenant?}', [WebhookController::class, 'handle'])->name('chat.webhook');
```
Routes are NOT auto-registered — copy into app's routes file.

## config (config/chat.php)
- `adapters` — map of name → config (bot_token, signing_secret, etc.)
- `state.store` — cache store name (default: `file`)
- `state.prefix` — cache key prefix (default: `chat:`)
- `handlers` — handler classes with `register($chat)` method
- `concurrency` — drop/queue/debounce/concurrent
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
- `Jobs/ProcessMessageJob` — queueable message processing (for `queue` concurrency strategy)

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
