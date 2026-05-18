<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Tests\Helpers\MockAdapter;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

class WebhookControllerTest extends TestCase
{
    private MockAdapter $mockAdapter;

    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('cache.default', 'array');

        // Register webhook route manually (package doesn't auto-register routes)
        Route::match(['get', 'post'], '/api/webhooks/{adapter}', [WebhookController::class, 'handle']);

        // Bind a mock adapter for testing
        $this->mockAdapter = new MockAdapter;

        $app->extend(Chat::class, function (Chat $chat, $app) {
            $chat->registerAdapter('mock', $this->mockAdapter);

            return $chat;
        });
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
}
