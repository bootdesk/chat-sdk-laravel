<?php

namespace BootDesk\ChatSDK\Laravel\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ChatMakeAdapterCommand extends Command
{
    protected $signature = 'chat:make-adapter
        {name : Platform name in kebab-case (e.g., "custom-api")}
        {--force : Overwrite existing files}';

    protected $description = 'Scaffold a local adapter class in app/Chat/Adapters';

    private array $stubs = [
        'Adapter.stub' => '{class}Adapter.php',
        'FormatConverter.stub' => '{class}FormatConverter.php',
        'Cards.stub' => '{class}Cards.php',
        'WebhookVerifier.stub' => '{class}WebhookVerifier.php',
    ];

    public function handle(): int
    {
        $class = Str::studly($this->argument('name'));
        $kebab = Str::kebab($class);
        $namespace = "App\\Chat\\Adapters\\{$class}";

        $dir = app_path("Chat/Adapters/{$class}");

        if (is_dir($dir) && ! $this->option('force')) {
            $this->error("Adapter already exists at {$dir}. Use --force to overwrite.");

            return self::FAILURE;
        }

        $stubsDir = __DIR__.'/../../stubs/adapter';

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        foreach ($this->stubs as $stub => $filename) {
            $content = file_get_contents("{$stubsDir}/{$stub}");
            $content = str_replace(
                ['{{ namespace }}', '{{ class }}', '{{ kebab }}'],
                [$namespace, $class, $kebab],
                $content,
            );

            $this->components->task(
                "Creating {$filename}",
                fn (): int|false => file_put_contents("{$dir}/".str_replace('{class}', $class, $filename), $content),
            );
        }

        $this->newLine();
        $this->info('Adapter scaffolded at '.$dir);
        $this->newLine();
        $this->warn('Register in config/chat.php:');
        $this->line("'adapters' => [");
        $this->line("    '{$kebab}' => [");
        $this->line('        // config...');
        $this->line('    ],');
        $this->line('],');
        $this->newLine();
        $this->warn('Then register in AppServiceProvider::boot():');
        $this->line('$chat->registerAdapter(\''.$kebab.'\', new \\'.$namespace.'\\'.$class.'Adapter(...));');

        return self::SUCCESS;
    }
}
