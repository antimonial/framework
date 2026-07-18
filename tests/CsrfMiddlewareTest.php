<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Middleware\CsrfMiddleware;
use Antimonial\Security\Csrf;
use Antimonial\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CSRF middleware, including the X-CSRF-TOKEN / X-XSRF-TOKEN
 * header fallback used by AJAX clients.
 */
final class CsrfMiddlewareTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ant_csrfmw_'.uniqid();
        mkdir($this->dir, 0777, true);
        ini_set('session.save_path', $this->dir);
        ini_set('session.use_cookies', '0');

        $ref = new \ReflectionProperty(Session::class, 'started');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
        @session_write_close();

        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        Session::flush();
        @session_write_close();
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    private function request(): Request
    {
        return Request::fromGlobals();
    }

    public function test_safe_method_passes_through(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $next = fn (Request $r): Response => (new Response)->body('ok');

        $response = (new CsrfMiddleware)->handle($this->request(), $next);

        self::assertSame('ok', $response->getBody());
    }

    public function test_valid_form_token_passes(): void
    {
        Session::start();
        $token = Csrf::token();
        $_POST['_token'] = $token;
        $next = fn (Request $r): Response => (new Response)->body('ok');

        $response = (new CsrfMiddleware)->handle($this->request(), $next);

        self::assertSame('ok', $response->getBody());
    }

    public function test_valid_x_csrf_token_header_passes(): void
    {
        Session::start();
        $token = Csrf::token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
        $next = fn (Request $r): Response => (new Response)->body('ok');

        $response = (new CsrfMiddleware)->handle($this->request(), $next);

        self::assertSame('ok', $response->getBody());
    }

    public function test_valid_x_xsrf_token_header_passes(): void
    {
        Session::start();
        $token = Csrf::token();
        $_SERVER['HTTP_X_XSRF_TOKEN'] = $token;
        $next = fn (Request $r): Response => (new Response)->body('ok');

        $response = (new CsrfMiddleware)->handle($this->request(), $next);

        self::assertSame('ok', $response->getBody());
    }

    public function test_missing_token_returns_419(): void
    {
        Session::start();
        $next = fn (Request $r): Response => (new Response)->body('should not run');

        $response = (new CsrfMiddleware)->handle($this->request(), $next);

        self::assertSame(419, $response->getStatusCode());
    }

    public function test_wrong_token_returns_419(): void
    {
        Session::start();
        $_POST['_token'] = 'wrong';
        $next = fn (Request $r): Response => (new Response)->body('should not run');

        $response = (new CsrfMiddleware)->handle($this->request(), $next);

        self::assertSame(419, $response->getStatusCode());
    }
}
