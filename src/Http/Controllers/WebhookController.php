<?php

namespace BootDesk\ChatSDK\Laravel\Http\Controllers;

use BootDesk\ChatSDK\Laravel\ChatFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nyholm\Psr7\Factory\Psr17Factory;
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
        $psrFactory = new Psr17Factory;
        $psrHttpFactory = new PsrHttpFactory(
            serverRequestFactory: $psrFactory,
            streamFactory: $psrFactory,
            uploadedFileFactory: $psrFactory,
            responseFactory: $psrFactory,
        );

        $psrRequest = $psrHttpFactory->createRequest($request);
        $chat = $this->chatFactory->forGroup($adapter);
        $psrResponse = $chat->handleWebhook($adapter, $psrRequest);

        $httpFoundationFactory = new HttpFoundationFactory;

        return $httpFoundationFactory->createResponse($psrResponse);
    }
}
