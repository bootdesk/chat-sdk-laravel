<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Contracts;

use BootDesk\ChatSDK\Core\Chat;
use Psr\Http\Message\ServerRequestInterface;

interface ChatHandlerWithRequest extends ChatHandler
{
    public function register(Chat $chat, ?ServerRequestInterface $request = null): void;
}
