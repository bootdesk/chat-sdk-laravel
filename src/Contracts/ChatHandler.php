<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Contracts;

use BootDesk\ChatSDK\Core\Chat;

interface ChatHandler
{
    public function register(Chat $chat): void;
}
