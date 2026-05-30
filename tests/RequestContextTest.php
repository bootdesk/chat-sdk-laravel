<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Laravel\Tests;

use BootDesk\ChatSDK\Laravel\Jobs\RequestContext;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class RequestContextTest extends TestCase
{
    public function test_roundtrip_preserves_all_request_data(): void
    {
        $original = new ServerRequest(
            'POST',
            'https://example.com/webhooks/slack?foo=bar',
            ['Content-Type' => ['application/json'], 'X-Custom' => ['val1', 'val2']],
            '{"hello":"world"}',
            '2.0',
            ['REMOTE_ADDR' => '127.0.0.1'],
        );

        $original = $original
            ->withQueryParams(['foo' => 'bar'])
            ->withParsedBody(['hello' => 'world'])
            ->withCookieParams(['session' => 'abc']);

        $context = RequestContext::fromServerRequest($original);
        $reconstructed = $context->toPsrRequest();

        $this->assertSame('POST', $reconstructed->getMethod());
        $this->assertSame('https://example.com/webhooks/slack?foo=bar', (string) $reconstructed->getUri());
        $this->assertSame('{"hello":"world"}', (string) $reconstructed->getBody());
        $this->assertSame('2.0', $reconstructed->getProtocolVersion());
        $this->assertSame(['foo' => 'bar'], $reconstructed->getQueryParams());
        $this->assertSame(['hello' => 'world'], $reconstructed->getParsedBody());
        $this->assertSame(['session' => 'abc'], $reconstructed->getCookieParams());
        $this->assertSame('127.0.0.1', $reconstructed->getServerParams()['REMOTE_ADDR']);
    }

    public function test_from_server_request_captures_headers(): void
    {
        $original = new ServerRequest('GET', '/test', ['Authorization' => ['Bearer tok'], 'Accept' => ['text/html']]);

        $context = RequestContext::fromServerRequest($original);

        $this->assertSame(['Bearer tok'], $context->headers['Authorization']);
        $this->assertSame(['text/html'], $context->headers['Accept']);
    }

    public function test_from_server_request_with_null_parsed_body(): void
    {
        $original = new ServerRequest('GET', '/test');

        $context = RequestContext::fromServerRequest($original);

        $this->assertNull($context->parsedBody);
    }

    public function test_to_psr_request_restores_empty_body(): void
    {
        $context = new RequestContext(
            method: 'GET',
            uri: '/health',
            headers: [],
            body: '',
            queryParams: [],
            parsedBody: null,
            serverParams: [],
            cookies: [],
            version: '1.1',
        );

        $request = $context->toPsrRequest();

        $this->assertSame('', (string) $request->getBody());
    }
}
