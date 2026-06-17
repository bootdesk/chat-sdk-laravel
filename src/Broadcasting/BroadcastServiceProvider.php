<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Broadcasting;

use BootDesk\ChatSDK\Core\Contracts\BroadcastAdapter;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/chat-broadcasting.php', 'chat-broadcasting');

        $this->app->bindIf(BroadcastAdapter::class, function (): LaravelBroadcastAdapter {
            return new LaravelBroadcastAdapter(
                broadcasterType: config('chat-broadcasting.default', 'pusher'),
                channelPrefix: config('chat-broadcasting.channel_prefix', 'chat'),
                threadChannelType: config('chat-broadcasting.thread_channel_type', 'public'),
                userChannelType: config('chat-broadcasting.user_channel_type', 'private'),
                useHashChannel: config('chat-broadcasting.use_hash_channel', false),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/chat-broadcasting.php' => config_path('chat-broadcasting.php'),
            ], 'chat-config');
        }
    }
}
