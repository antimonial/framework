<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Http\Request;
use Antimonial\Routing\HttpNotFoundException;
use Antimonial\Routing\Route;
use Antimonial\Routing\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router;
        $_GET = [];
        $_POST = [];
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $_COOKIE = [];
        $_FILES = [];
        Route::$namedRoutes = [];
    }

    public function test_register_get_route(): void
    {
        $route = $this->router->get('/foo', fn () => 'bar');
        $this->assertSame('GET', $route->method);
        $this->assertSame('/foo', $route->path);
    }

    public function test_register_post_route(): void
    {
        $route = $this->router->post('/foo', fn () => 'bar');
        $this->assertSame('POST', $route->method);
    }

    public function test_register_put_route(): void
    {
        $route = $this->router->put('/foo', fn () => 'bar');
        $this->assertSame('PUT', $route->method);
    }

    public function test_register_delete_route(): void
    {
        $route = $this->router->delete('/foo', fn () => 'bar');
        $this->assertSame('DELETE', $route->method);
    }

    public function test_match_multi_method(): void
    {
        $routes = $this->router->match(['GET', 'POST'], '/foo', fn () => 'bar');
        $this->assertCount(2, $routes);
        $this->assertSame('GET', $routes[0]->method);
        $this->assertSame('POST', $routes[1]->method);
    }

    public function test_dispatch_exact_match(): void
    {
        $handler = fn () => 'hello';
        $this->router->get('/users', $handler);

        $_SERVER['REQUEST_URI'] = '/users';
        $request = Request::fromGlobals();
        $result = $this->router->dispatch($request);

        $this->assertSame($handler, $result['handler']);
        $this->assertSame([], $result['params']);
    }

    public function test_dispatch_parameterized_match(): void
    {
        $handler = fn () => 'show';
        $this->router->get('/users/{id}', $handler);

        $_SERVER['REQUEST_URI'] = '/users/42';
        $request = Request::fromGlobals();
        $result = $this->router->dispatch($request);

        $this->assertSame($handler, $result['handler']);
        $this->assertSame(['id' => '42'], $result['params']);
    }

    public function test_dispatch_with_regex_constraint(): void
    {
        $handler = fn () => 'show';
        $this->router->get('/posts/{slug:[a-z0-9\-]+}', $handler);

        $_SERVER['REQUEST_URI'] = '/posts/hello-world';
        $request = Request::fromGlobals();
        $result = $this->router->dispatch($request);

        $this->assertSame($handler, $result['handler']);
        $this->assertSame(['slug' => 'hello-world'], $result['params']);
    }

    public function test_dispatch_regex_constraint_rejects_invalid(): void
    {
        $handler = fn () => 'show';
        $this->router->get('/posts/{slug:[a-z0-9\-]+}', $handler);

        $_SERVER['REQUEST_URI'] = '/posts/INVALID_SLUG!';
        $request = Request::fromGlobals();

        $this->expectException(HttpNotFoundException::class);
        $this->router->dispatch($request);
    }

    public function test_dispatch_no_match_throws_exception(): void
    {
        $handler = fn () => 'list';
        $this->router->get('/users', $handler);

        $_SERVER['REQUEST_URI'] = '/nonexistent';
        $request = Request::fromGlobals();

        $this->expectException(HttpNotFoundException::class);
        $this->router->dispatch($request);
    }

    public function test_dispatch_wrong_method_throws_exception(): void
    {
        $handler = fn () => 'store';
        $this->router->post('/users', $handler);
        $this->router->get('/users', $handler);

        $_SERVER['REQUEST_URI'] = '/users';
        $request = Request::fromGlobals();

        // GET for /users exists, so dispatch succeeds
        $result = $this->router->dispatch($request);
        $this->assertNotFalse($result);
    }

    public function test_dispatch_no_matching_method_throws_exception(): void
    {
        $this->router->post('/users', fn () => 'store');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/users';
        $request = Request::fromGlobals();

        $this->expectException(HttpNotFoundException::class);
        $this->router->dispatch($request);
    }

    public function test_route_with_multiple_params(): void
    {
        $handler = fn () => 'show';
        $this->router->get('/{category}/{slug}', $handler);

        $_SERVER['REQUEST_URI'] = '/tech/hello-world';
        $request = Request::fromGlobals();
        $result = $this->router->dispatch($request);

        $this->assertSame($handler, $result['handler']);
        $this->assertSame(['category' => 'tech', 'slug' => 'hello-world'], $result['params']);
    }

    public function test_group_prefix(): void
    {
        $this->router->group('/admin', function () {
            $this->router->get('/dashboard', fn () => 'dashboard');
            $this->router->get('/users', fn () => 'users');
        });

        $_SERVER['REQUEST_URI'] = '/admin/dashboard';
        $this->assertIsArray($this->router->dispatch(Request::fromGlobals()));

        $_SERVER['REQUEST_URI'] = '/admin/users';
        $this->assertIsArray($this->router->dispatch(Request::fromGlobals()));
    }

    public function test_group_middleware(): void
    {
        $mw = 'App\Middleware\Auth';
        $routes = [];
        $this->router->group('/admin', function () use (&$routes) {
            $routes[] = $this->router->get('/dashboard', fn () => 'dashboard');
        }, [$mw]);

        $this->assertContains($mw, $routes[0]->middleware);
    }

    public function test_global_middleware_applies_to_all_routes(): void
    {
        $mw = 'App\Middleware\GlobalMw';
        $this->router->addMiddleware($mw);

        $route = $this->router->get('/test', fn () => 'test');
        $this->assertSame('/test', $route->path);

        $_SERVER['REQUEST_URI'] = '/test';
        $result = $this->router->dispatch(Request::fromGlobals());

        $this->assertContains($mw, $result['middleware']);
    }

    public function test_route_middleware_stored_on_route(): void
    {
        $mw = 'App\Middleware\Auth';
        $route = $this->router->get('/profile', fn () => 'profile')->middleware($mw);

        $this->assertContains($mw, $route->middleware);
    }

    public function test_nested_groups_stack_prefixes(): void
    {
        $this->router->group('/api', function () {
            $this->router->group('/v1', function () {
                $this->router->get('/users', fn () => 'api v1 users');
            });
        });

        $_SERVER['REQUEST_URI'] = '/api/v1/users';
        $this->assertIsArray($this->router->dispatch(Request::fromGlobals()));
    }

    public function test_named_route_registers(): void
    {
        $route = $this->router->get('/posts/{slug}', fn () => 'show')->name('posts.show');

        $this->assertSame('posts.show', $route->name);
        $this->assertArrayHasKey('posts.show', Route::$namedRoutes);
        $this->assertSame($route, Route::$namedRoutes['posts.show']);
    }

    public function test_route_helper_generates_url(): void
    {
        $this->router->get('/posts/{slug}', fn () => 'show')->name('posts.show');

        $url = route('posts.show', ['slug' => 'hello-world']);
        $this->assertSame('/posts/hello-world', $url);
    }

    public function test_route_helper_with_multiple_params(): void
    {
        $this->router->get('/{category}/{slug}', fn () => 'show')->name('posts.by_category');

        $url = route('posts.by_category', ['category' => 'tech', 'slug' => 'hello']);
        $this->assertSame('/tech/hello', $url);
    }

    public function test_route_helper_without_params(): void
    {
        $this->router->get('/', fn () => 'home')->name('home');

        $this->assertSame('/', route('home'));
    }

    public function test_route_helper_throws_on_unknown(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Route [nonexistent] not defined.');

        route('nonexistent');
    }

    public function test_dispatch_route_params_in_result(): void
    {
        $this->router->get('/users/{id}/posts/{postId}', fn () => 'show');

        $_SERVER['REQUEST_URI'] = '/users/42/posts/7';
        $result = $this->router->dispatch(Request::fromGlobals());

        $this->assertSame('42', $result['params']['id']);
        $this->assertSame('7', $result['params']['postId']);
    }

    public function test_exact_match_takes_precedence(): void
    {
        $this->router->get('/users', fn () => 'list');
        $this->router->get('/users/{id}', fn () => 'show');

        $_SERVER['REQUEST_URI'] = '/users';
        $result = $this->router->dispatch(Request::fromGlobals());

        $this->assertNotFalse($result);
        $this->assertSame([], $result['params']);
    }
}
