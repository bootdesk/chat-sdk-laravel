<?php

namespace BootDesk\ChatSDK\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        // Signature verification is per-adapter (Slack HMAC, Discord Ed25519, etc.)
        // Adapters handle verification inside Adapter::verifyWebhook().
        // This middleware can be extended for rate limiting, IP whitelisting, etc.

        return $next($request);
    }
}
