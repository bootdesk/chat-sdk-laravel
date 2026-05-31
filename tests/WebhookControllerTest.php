<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Core\Tests\Helpers\MockAdapter;
use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use BootDesk\ChatSDK\Laravel\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

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
}
