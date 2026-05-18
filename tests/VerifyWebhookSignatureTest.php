<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Laravel\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;

class VerifyWebhookSignatureTest extends TestCase
{
    public function test_middleware_passes_request(): void
    {
        $middleware = new VerifyWebhookSignature;
        $request = new Request;

        $response = $middleware->handle($request, function ($req) {
            return new Response('ok');
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }
}
