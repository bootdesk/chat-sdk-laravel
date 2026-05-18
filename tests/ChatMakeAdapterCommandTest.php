<?php

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Laravel\ChatServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Orchestra\Testbench\TestCase;

class ChatMakeAdapterCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $path = app_path('Chat');
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
    }

    protected function tearDown(): void
    {
        $path = app_path('Chat');
        if (is_dir($path)) {
            File::deleteDirectory($path);
        }
        parent::tearDown();
    }

    public function test_scaffold(): void
    {
        $this->artisan('chat:make-adapter', ['name' => 'scaffold-test'])
            ->assertSuccessful()
            ->expectsOutputToContain('scaffold-test');

        $this->assertDirectoryExists(app_path('Chat/Adapters/ScaffoldTest'));
    }

    public function test_force_flag(): void
    {
        $dir = app_path('Chat/Adapters/ForceTest');
        mkdir($dir, 0755, true);
        file_put_contents("{$dir}/ForceTestAdapter.php", 'old');

        $this->artisan('chat:make-adapter', ['name' => 'force-test', '--force' => true])
            ->assertSuccessful();

        $this->assertFileExists("{$dir}/ForceTestAdapter.php");
    }

    public function test_normalizes_studly_case(): void
    {
        $this->artisan('chat:make-adapter', ['name' => 'CustomAPI'])
            ->assertSuccessful();

        $dir = app_path('Chat/Adapters/'.Str::studly('CustomAPI'));
        $this->assertDirectoryExists($dir);
    }
}
