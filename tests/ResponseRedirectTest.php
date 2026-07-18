<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Http\Response;
use PHPUnit\Framework\TestCase;

/**
 * Regression test for Response::send() no longer calling exit() on a
 * redirect. Previously send() hard-killed the PHP process after setting
 * the Location header, which made any controller that triggered a
 * redirect impossible to unit-test without process isolation and
 * prevented register_shutdown_function hooks from running.
 *
 * The test calls send() on a redirect Response and proves the process
 * survives (no process isolation needed) and the Response object keeps
 * its status/headers intact for assertions.
 */
final class ResponseRedirectTest extends TestCase
{
    public function test_send_on_redirect_does_not_exit(): void
    {
        $response = (new Response)->redirect('/login', 302);

        // Suppress actual header output during the test run.
        $response->send();

        // If send() had called exit(), this assertion would never run.
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
        $this->assertTrue($response->wasSent());
    }

    public function test_send_on_permanent_redirect_does_not_exit(): void
    {
        $response = (new Response)->redirect('/old', 301);

        $response->send();

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/old', $response->getHeaders()['Location']);
    }
}
