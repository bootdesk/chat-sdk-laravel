<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Commands;

use Illuminate\Console\Command;

class ChatInstallCommand extends Command
{
    protected $signature = 'chat:install';

    protected $description = 'Publish chat config and show setup instructions';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'chat-config']);

        $this->newLine();
        $this->info('Chat config published to config/chat.php');
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Edit config/chat.php to configure your adapters');
        $this->line('  2. Set webhook URLs in your platform:');
        $this->line('     https://your-app.com/api/webhooks/{adapter}');
        $this->line('  3. Create a handler class and add it to config(chat.handlers)');
        $this->newLine();

        return self::SUCCESS;
    }
}
