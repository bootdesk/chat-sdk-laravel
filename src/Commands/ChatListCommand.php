<?php

namespace BootDesk\ChatSDK\Laravel\Commands;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use Illuminate\Console\Command;

class ChatListCommand extends Command
{
    protected $signature = 'chat:list';

    protected $description = 'List configured chat adapters and their status';

    public function handle(Chat $chat): int
    {
        $allAdapters = array_unique(
            [
                ...array_keys($configuredAdapters = config('chat.adapters', [])),
                ...array_keys($registeredAdapters = AdapterRegistry::all()),
            ]
        );

        $rows = [];
        foreach ($allAdapters as $name) {
            $resolved = $chat->resolveAdapter($name);

            $isRegistered = isset($registeredAdapters[$name]);
            $isGloballyConfigured = isset($configuredAdapters[$name]);

            $rows[] = [
                $name,
                match (true) {
                    $isRegistered && $isGloballyConfigured => '<fg=green>Registered globally</>',
                    ! $isRegistered => '<fg=red>Globally configured but not available</>',
                    default => '<fg=yellow>Registered with config per request</>',
                },
                $resolved instanceof Adapter ? $resolved->getName() : '-',
            ];
        }

        $this->table(['Adapter', 'Status', 'Name'], $rows);

        return self::SUCCESS;
    }
}
