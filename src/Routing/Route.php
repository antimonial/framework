<?php

declare(strict_types=1);

namespace Antimonial\Routing;

use Antimonial\Middleware\MiddlewareInterface;
use Closure;

/**
 * Value object representing a single route.
 *
 * Holds the HTTP method, URI path, handler (closure or controller array),
 * and any middleware attached to this route.
 *
 * @example
 *   $route = new Route('GET', '/users/{id}', [UserController::class, 'show']);
 *   $route->middleware(Auth::class);
 *
 * @see Router
 * @see MiddlewareInterface
 */
class Route
{
    /**
     * @var string HTTP method (GET, POST, PUT, DELETE, PATCH, etc.)
     */
    public readonly string $method;

    /**
     * @var string URI path pattern (e.g. '/users/{id}')
     */
    public readonly string $path;

    /**
     * Route handler: a closure or [ControllerClass::class, 'method'].
     *
     * @var Closure|array{0: class-string, 1: string}
     */
    public readonly Closure|array $handler;

    /**
     * Middleware classes to run before this route's handler.
     *
     * @var class-string[]
     */
    public array $middleware = [];

    /**
     * Optional route name for reverse URL generation.
     */
    public ?string $name = null;

    /**
     * Global registry of named routes: name => Route instance.
     *
     * @var array<string, Route>
     */
    public static array $namedRoutes = [];

    /**
     * @param  string  $method  HTTP method
     * @param  string  $path  URI path
     * @param  Closure|array{0: class-string, 1: string}  $handler  Route handler
     */
    public function __construct(string $method, string $path, Closure|array $handler)
    {
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;
    }

    /**
     * Set the route name for reverse URL generation (fluent).
     *
     * @example $router->get('/posts/{slug}', ...)->name('posts.show');
     *
     * @param  string  $name  Route name (e.g. 'posts.show')
     */
    public function name(string $name): static
    {
        $this->name = $name;
        self::$namedRoutes[$name] = $this;

        return $this;
    }

    /**
     * Attach middleware to this route (fluent).
     *
     * @example $route->middleware(Auth::class, AdminCheck::class);
     *
     * @param  class-string  ...$middleware  Middleware class names
     *
     * @see MiddlewareInterface
     */
    public function middleware(string ...$middleware): static
    {
        $this->middleware = array_merge($this->middleware, $middleware);

        return $this;
    }
}
