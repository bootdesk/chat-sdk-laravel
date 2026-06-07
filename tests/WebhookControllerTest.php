<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Core\Tests\Helpers\MockAdapter;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandler as ChatHandlerContract;
use BootDesk\ChatSDK\Laravel\Contracts\ChatHandlerWithRequest;
use BootDesk\ChatSDK\Laravel\HandlerRegistry;
use BootDesk\ChatSDK\Laravel\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class WebhookControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('chat.adapters', [
            'mock' => [],
        ]);

        AdapterRegistry::register('mock', MockAdapter::class);

        Route::match(['get', 'post'], '/api/webhooks/{adapter}', [WebhookController::class, 'handle']);
    }

    public function test_webhook_returns_200(): void
    {
        $response = $this->postJson('/api/webhooks/mock', [
            'id' => 'msg_test',
            'threadId' => 'mock:C123:1234',
            'text' => 'hello',
            'authorId' => 'U1',
        ]);

        $response->assertStatus(200);
    }

    public function test_webhook_get_returns_200(): void
    {
        $response = $this->get('/api/webhooks/mock');
        $response->assertStatus(200);
    }

    public function test_custom_controller_routes_to_different_handler_groups_by_channel(): void
    {
        $recorder = new class
        {
            public bool $internalCalled = false;

            public bool $externalCalled = false;
        };

        $internalHandler = new class($recorder) implements ChatHandlerContract
        {
            public function __construct(
                private readonly object $recorder,
            ) {}

            public function register(Chat $chat): void
            {
                $chat->onNewMessage('/.*/', function () {
                    $this->recorder->internalCalled = true;
                });
            }
        };

        $externalHandler = new class($recorder) implements ChatHandlerContract
        {
            public function __construct(
                private readonly object $recorder,
            ) {}

            public function register(Chat $chat): void
            {
                $chat->onNewMessage('/.*/', function () {
                    $this->recorder->externalCalled = true;
                });
            }
        };

        $this->app->instance(get_class($internalHandler), $internalHandler);
        $this->app->instance(get_class($externalHandler), $externalHandler);

        $this->app['config']->set('chat.handler_groups', [
            'internal' => [get_class($internalHandler)],
            'external' => [get_class($externalHandler)],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $this->app->singleton(WebhookController::class, function ($app) {
            return new class($app->make(ChatFactory::class)) extends WebhookController
            {
                protected function resolveGroups(string $adapter, Request $request, ServerRequestInterface $psrRequest): array
                {
                    return $request->input('channel') === 'ext'
                        ? ['external']
                        : ['internal'];
                }
            };
        });

        Route::match(['post'], '/api/webhooks-routed/{adapter}', [WebhookController::class, 'handle']);

        // Channel = ext → external group handler should fire
        $response = $this->postJson('/api/webhooks-routed/mock', [
            'channel' => 'ext',
            'threadId' => 'mock:C002:5678',
            'text' => 'external customer',
            'id' => 'msg_ext',
            'authorId' => 'U2',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($recorder->externalCalled);
        $this->assertFalse($recorder->internalCalled);

        // Reset
        $recorder->externalCalled = false;
        $recorder->internalCalled = false;

        // Channel = anything else → internal group handler should fire
        $response = $this->postJson('/api/webhooks-routed/mock', [
            'channel' => 'int',
            'threadId' => 'mock:C001:1234',
            'text' => 'internal user',
            'id' => 'msg_int',
            'authorId' => 'U1',
        ]);

        $response->assertStatus(200);
        $this->assertTrue($recorder->internalCalled);
        $this->assertFalse($recorder->externalCalled);
    }

    public function test_webhook_passes_request_to_chat_handler_with_request(): void
    {
        $handler = new class implements ChatHandlerWithRequest
        {
            public ?ServerRequestInterface $receivedRequest = null;

            public function register(Chat $chat, ?ServerRequestInterface $request = null): void
            {
                $this->receivedRequest = $request;
            }
        };

        $handlerClass = get_class($handler);
        $this->app->instance($handlerClass, $handler);

        $this->app['config']->set('chat.handler_groups', [
            'routed' => [$handlerClass],
        ]);

        $this->app->forgetInstance(HandlerRegistry::class);
        $this->app->forgetInstance(ChatFactory::class);

        $this->app->singleton(WebhookController::class, function ($app) {
            return new class($app->make(ChatFactory::class)) extends WebhookController
            {
                protected function resolveGroups(string $adapter, Request $request, ServerRequestInterface $psrRequest): array
                {
                    return ['routed'];
                }
            };
        });

        Route::match(['post'], '/api/webhooks-request/{adapter}', [WebhookController::class, 'handle']);

        $response = $this->postJson('/api/webhooks-request/mock', [
            'threadId' => 'mock:C001:1234',
            'text' => 'pass request',
            'id' => 'msg_req',
            'authorId' => 'U1',
        ]);

        $response->assertStatus(200);
        $this->assertNotNull($handler->receivedRequest);
        $this->assertSame('POST', $handler->receivedRequest->getMethod());
    }
}
