<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function test_status_code_defaults_to_200(): void
    {
        $this->assertSame(200, (new Response)->getStatusCode());
    }

    public function test_status_setter(): void
    {
        $res = (new Response)->status(404);
        $this->assertSame(404, $res->getStatusCode());
    }

    public function test_header_added(): void
    {
        $res = (new Response)->header('X-Custom', 'value');
        $this->assertArrayHasKey('X-Custom', $res->getHeaders());
        $this->assertSame('value', $res->getHeaders()['X-Custom']);
    }

    public function test_body(): void
    {
        $res = (new Response)->body('Hello');
        $this->assertSame('Hello', $res->getBody());
    }

    public function test_json_sets_headers_and_body(): void
    {
        $res = (new Response)->json(['ok' => true], 201);

        $this->assertSame(201, $res->getStatusCode());
        $this->assertStringContainsString('application/json', $res->getHeaders()['Content-Type']);
        $this->assertStringContainsString('"ok":true', $res->getBody());
    }

    public function test_json_defaults_to_200(): void
    {
        $res = (new Response)->json(['ok' => true]);

        $this->assertSame(200, $res->getStatusCode());
    }

    public function test_redirect_sets_location(): void
    {
        $res = (new Response)->redirect('/login');

        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaders()['Location']);
    }

    public function test_redirect_with_custom_status(): void
    {
        $res = (new Response)->redirect('/old-page', 301);
        $this->assertSame(301, $res->getStatusCode());
    }

    public function test_fluent_api(): void
    {
        $res = (new Response)
            ->status(200)
            ->header('X-Foo', 'bar')
            ->body('test');

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('bar', $res->getHeaders()['X-Foo']);
        $this->assertSame('test', $res->getBody());
    }

    public function test_get_headers_returns_array(): void
    {
        $res = (new Response)
            ->header('X-One', '1')
            ->header('X-Two', '2');

        // Only the two custom headers — security headers are added in send()
        $this->assertCount(2, $res->getHeaders());
    }
}
