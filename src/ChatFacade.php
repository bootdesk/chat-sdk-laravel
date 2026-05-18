<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel;

use BootDesk\ChatSDK\Core\Chat;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \BootDesk\ChatSDK\Core\Contracts\Adapter|null resolveAdapter(string $name, ?\Psr\Http\Message\ServerRequestInterface $request = null)
 * @method static self registerAdapter(string $name, \BootDesk\ChatSDK\Core\Contracts\Adapter $adapter)
 * @method static \BootDesk\ChatSDK\Core\Thread thread(string $threadId)
 * @method static \BootDesk\ChatSDK\Core\Channel channel(string $channelId)
 * @method static self onNewMessage(string $pattern, callable $handler)
 * @method static self onNewMention(callable $handler)
 * @method static self onDirectMessage(callable $handler)
 * @method static self onSubscribedMessage(callable $handler)
 * @method static self onReaction(string|array|callable $emoji, ?callable $handler = null)
 * @method static self onAction(string|array|callable $actionId, ?callable $handler = null)
 * @method static self onSlashCommand(string|array|callable $command, ?callable $handler = null)
 * @method static self onModalSubmit(string|array|callable $callbackId, ?callable $handler = null)
 * @method static self onModalClose(string|array|callable $callbackId, ?callable $handler = null)
 * @method static self onOptionsLoad(string|array|callable $actionId, ?callable $handler = null)
 * @method static self onAssistantThreadStarted(callable $handler)
 * @method static self onAssistantContextChanged(callable $handler)
 * @method static self onAppHomeOpened(callable $handler)
 * @method static self onMemberJoinedChannel(callable $handler)
 * @method static self onMessageDelivered(callable $handler)
 * @method static self onMessageRead(callable $handler)
 * @method static void processReaction(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $threadId, string $emoji, string $messageId, \BootDesk\ChatSDK\Core\Author $user, bool $added = true, string $rawEmoji = '', mixed $raw = null)
 * @method static void processAction(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $threadId, string $actionId, ?string $value, string $messageId, \BootDesk\ChatSDK\Core\Author $user, ?string $triggerId = null, mixed $raw = null)
 * @method static void processModalSubmit(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $callbackId, array $values, \BootDesk\ChatSDK\Core\Author $user, mixed $raw = null, ?string $viewId = null, ?string $contextId = null)
 * @method static void processModalClose(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $callbackId, \BootDesk\ChatSDK\Core\Author $user, mixed $raw = null, ?string $viewId = null, ?string $contextId = null)
 * @method static void processSlashCommand(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $channelId, string $command, string $text, ?\BootDesk\ChatSDK\Core\Author $user = null, mixed $raw = null, ?string $triggerId = null)
 * @method static array|null processOptionsLoad(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $actionId, string $query, \BootDesk\ChatSDK\Core\Author $user, mixed $raw = null)
 * @method static void processAssistantThreadStarted(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $channelId, string $threadId, string $userId, mixed $context, ?string $threadTs = null, mixed $raw = null)
 * @method static void processAssistantContextChanged(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $channelId, string $threadId, string $userId, mixed $context, ?string $threadTs = null, mixed $raw = null)
 * @method static void processAppHomeOpened(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $channelId, string $userId, mixed $raw = null)
 * @method static void processMemberJoinedChannel(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $channelId, string $userId, ?string $inviterId = null, mixed $raw = null)
 * @method static void processMessageDelivered(string $threadId, array $messageIds, string $userId, mixed $raw = null)
 * @method static void processMessageRead(string $threadId, string $userId, mixed $raw = null, ?int $timestamp = null)
 * @method static self onMessageDelivered(callable $handler)
 * @method static void storeModalContext(string $adapterName, string $contextId, array $data, int $ttlMs = 86400000)
 * @method static array|null getAndDeleteModalContext(string $adapterName, string $contextId)
 * @method static string|null openDM(string $adapterName, string $userId)
 * @method static \BootDesk\ChatSDK\Core\UserInfo|null getUser(string $adapterName, string $userId)
 * @method static \Psr\Http\Message\ResponseInterface handleWebhook(string $adapterName, \Psr\Http\Message\ServerRequestInterface $request, array $options = [])
 * @method static void processMessage(\BootDesk\ChatSDK\Core\Contracts\Adapter $adapter, string $threadId, \BootDesk\ChatSDK\Core\Message $message)
 * @method static void initialize()
 * @method static void shutdown()
 * @method static self addWebhookMiddleware(\BootDesk\ChatSDK\Core\Contracts\WebhookMiddleware $middleware)
 * @method static self addReceivingMiddleware(\BootDesk\ChatSDK\Core\Contracts\ReceivingMiddleware $middleware)
 * @method static self addSendingMiddleware(\BootDesk\ChatSDK\Core\Contracts\SendingMiddleware $middleware)
 */
class ChatFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Chat::class;
    }
}
