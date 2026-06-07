<?php

namespace BootDesk\ChatSDK\Laravel\Http\Controllers;

use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Laravel\ChatFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct(
        private readonly ChatFactory $chatFactory,
    ) {}

    public function handle(string $adapter, Request $request): Response
    {
        $psrRequest = $this->createPsrRequest($request);
        $groups = $this->resolveGroups($adapter, $request, $psrRequest);
        $psrRequest = $this->withGroupsAttribute($psrRequest, $groups);
        $chat = $this->createChat($groups, $psrRequest);
        $psrResponse = $this->handleWebhook($adapter, $chat, $psrRequest);

        return $this->createResponse($psrResponse);
    }

    protected function createPsrRequest(Request $request): ServerRequestInterface
    {
        $psrFactory = new Psr17Factory;
        $psrHttpFactory = new PsrHttpFactory(
            serverRequestFactory: $psrFactory,
            streamFactory: $psrFactory,
            uploadedFileFactory: $psrFactory,
            responseFactory: $psrFactory,
        );

        return $psrHttpFactory->createRequest($request);
    }

    /**
     * Determine which handler groups to use for this webhook.
     *
     * Override to route different channels/tenants to different handler groups.
     * Default: uses the adapter name as the only group (matching pre-v2.0 behaviour).
     *
     * @return string[]
     */
    protected function resolveGroups(string $adapter, Request $request, ServerRequestInterface $psrRequest): array
    {
        return [$adapter];
    }

    /**
     * Store resolved groups as a PSR-7 request attribute so they survive
     * serialization into async queue jobs via RequestContext.
     */
    protected function withGroupsAttribute(ServerRequestInterface $psrRequest, array $groups): ServerRequestInterface
    {
        return $psrRequest->withAttribute('chat_groups', $groups);
    }

    /**
     * Build a Chat instance scoped to the resolved handler groups.
     *
     * @param  string[]  $groups
     */
    protected function createChat(array $groups, ?ServerRequestInterface $request = null): Chat
    {
        return $this->chatFactory->forGroups($groups, $request);
    }

    protected function handleWebhook(string $adapter, Chat $chat, ServerRequestInterface $psrRequest): ResponseInterface
    {
        return $chat->handleWebhook($adapter, $psrRequest);
    }

    protected function createResponse(ResponseInterface $psrResponse): Response
    {
        $httpFoundationFactory = new HttpFoundationFactory;

        return $httpFoundationFactory->createResponse($psrResponse);
    }
}
