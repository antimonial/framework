<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Middleware\AuthMiddleware;
use Antimonial\Middleware\GuestMiddleware;
use Antimonial\Security\Auth;
use Antimonial\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Auth and Guest middleware.
 *
 * Each middleware is invoked directly with a Request and a $next callable,
 * asserting the redirect / JSON behavior for both authenticated and guest
 * states. Sessions use a temp save path with cookies disabled.
 */
final class AuthMiddlewareTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ant_authmw_'.uniqid();
        mkdir($this->dir, 0777, true);
        ini_set('session.save_path', $this->dir);
        ini_set('session.use_cookies', '0');

        // Reset the framework's static "started" flag so each test gets a
        // fresh session bound to this test's save path.
        $ref = new \ReflectionProperty(Session::class, 'started');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
        @session_write_close();

        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        Session::start();
        Auth::useModel(UserStub::class);
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
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];

        return Request::fromGlobals();
    }

    public function test_auth_passes_when_authenticated(): void
    {
        Auth::login((object) ['id' => 1]);
        $next = fn (Request $r): Response => (new Response)->body('ok');

        $response = (new AuthMiddleware)->handle($this->request(), $next);

        self::assertSame('ok', $response->getBody());
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_auth_redirects_guest_to_login(): void
    {
        $next = fn (Request $r): Response => (new Response)->body('should not run');

        $response = (new AuthMiddleware)->handle($this->request(), $next);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaders()['Location']);
    }

    public function test_auth_returns_401_for_json_guest(): void
    {
        $next = fn (Request $r): Response => (new Response)->body('should not run');

        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $request = $this->request();

        $response = (new AuthMiddleware)->handle($request, $next);

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_guest_passes_when_guest(): void
    {
        $next = fn (Request $r): Response => (new Response)->body('ok');

        $response = (new GuestMiddleware)->handle($this->request(), $next);

        self::assertSame('ok', $response->getBody());
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_guest_redirects_authenticated_to_home(): void
    {
        Auth::login((object) ['id' => 1]);
        $next = fn (Request $r): Response => (new Response)->body('should not run');

        $response = (new GuestMiddleware)->handle($this->request(), $next);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaders()['Location']);
    }

    public function test_guest_returns_403_for_json_authenticated(): void
    {
        Auth::login((object) ['id' => 1]);
        $next = fn (Request $r): Response => (new Response)->body('should not run');

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $request = $this->request();

        $response = (new GuestMiddleware)->handle($request, $next);

        self::assertSame(403, $response->getStatusCode());
    }
}
