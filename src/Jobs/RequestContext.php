<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Jobs;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

class RequestContext
{
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $headers,
        public readonly string $body,
        public readonly array $queryParams,
        public readonly ?array $parsedBody,
        public readonly array $serverParams,
        public readonly array $cookies,
        public readonly string $version,
    ) {}

    public static function fromServerRequest(ServerRequestInterface $request): self
    {
        $request->getBody()->rewind();
        $body = $request->getBody()->getContents();
        $request->getBody()->rewind();

        return new self(
            method: $request->getMethod(),
            uri: (string) $request->getUri(),
            headers: $request->getHeaders(),
            body: $body,
            queryParams: $request->getQueryParams(),
            parsedBody: $request->getParsedBody(),
            serverParams: $request->getServerParams(),
            cookies: $request->getCookieParams(),
            version: $request->getProtocolVersion(),
        );
    }

    public function toPsrRequest(): ServerRequestInterface
    {
        $serverRequest = new ServerRequest(
            $this->method,
            $this->uri,
            $this->headers,
            $this->body,
            $this->version,
            $this->serverParams,
        );

        return $serverRequest
            ->withQueryParams($this->queryParams)
            ->withParsedBody($this->parsedBody)
            ->withCookieParams($this->cookies);
    }
}
