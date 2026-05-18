<?php

namespace BootDesk\ChatSDK\Laravel\Http\Controllers;

use BootDesk\ChatSDK\Core\Chat;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function __construct(
        private readonly Chat $chat,
    ) {}

    public function handle(string $adapter, Request $request): Response
    {
        $psrFactory = new Psr17Factory;
        $psrHttpFactory = new PsrHttpFactory(
            serverRequestFactory: $psrFactory,
            streamFactory: $psrFactory,
            uploadedFileFactory: $psrFactory,
            responseFactory: $psrFactory,
        );

        $psrRequest = $psrHttpFactory->createRequest($request);
        $psrResponse = $this->chat->handleWebhook($adapter, $psrRequest);

        $httpFoundationFactory = new HttpFoundationFactory;

        return $httpFoundationFactory->createResponse($psrResponse);
    }
}
