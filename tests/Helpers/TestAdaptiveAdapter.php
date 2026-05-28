<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Tests\Helpers;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class TestAdaptiveAdapter implements Adapter
{
    public function getName(): string
    {
        return 'test-adaptive';
    }

    public function getBotUserId(): ?string
    {
        return 'BOT';
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        return explode(':', $threadId)[1] ?? $threadId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        return new Message('', '', new Author('', ''), '');
    }

    public function encodeThreadId(mixed $platformData): string
    {
        return 'th';
    }

    public function decodeThreadId(string $threadId): mixed
    {
        return null;
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        return new SentMessage('m1', $threadId, (string) time());
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        return new SentMessage($messageId, $threadId, (string) time());
    }

    public function deleteMessage(string $threadId, string $messageId): void {}

    public function addReaction(string $threadId, string $messageId, string $emoji): void {}

    public function removeReaction(string $threadId, string $messageId, string $emoji): void {}

    public function startTyping(string $threadId): void {}

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        return new FetchResult([], null);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        return new ThreadInfo($threadId, 'ch', 'Test');
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        return new ChannelInfo($channelId, 'Test');
    }

    public function getUser(string $userId): ?UserInfo
    {
        return new UserInfo($userId, 'Test', null);
    }

    public function openDM(string $userId): ?string
    {
        return null;
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return null;
    }

    public function initialize(Chat $chat): void {}

    public function disconnect(): void {}

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        return null;
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }
}
