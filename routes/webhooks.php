<?php

declare(strict_types=1);

/**
 * Example webhook routes. Copy this into your app's routes file and
 * customize as needed. The package does NOT auto-register routes.
 *
 * Usage:
 *   use BootDesk\ChatSDK\Laravel\Http\Controllers\WebhookController;
 *   Route::match(['get', 'post'], '/api/webhooks/{adapter}/{tenant?}', [WebhookController::class, 'handle']);
 */

use BootDesk\ChatSDK\Laravel\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::match(
    ['get', 'post'],
    '/api/webhooks/{adapter}/{tenant?}',
    [WebhookController::class, 'handle']
)->name('chat.webhook');
