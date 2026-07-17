<?php

declare(strict_types=1);

namespace Antimonial\Routing;

use Antimonial\Core\App;
use Antimonial\Core\ErrorHandler;
use Antimonial\Http\Request;
use Antimonial\Middleware\MiddlewareInterface;
use Closure;

/**
 * HTTP router.
 *
 * Registers routes by HTTP method and URI pattern, matches incoming
 * requests against them, and extracts route parameters.
 *
 * Supports:
 *  - Exact path matches (fast hash lookup)
 *  - Parameterized paths with {param} placeholders
 *  - Route groups with prefixes
 *  - Global middleware
 *
 * @example
 *   $router->get('/users/{id}', [UserController::class, 'show']);
 *   $router->post('/users', [UserController::class, 'store']);
 *
 * @see Route
 * @see App
 */
class Router
{
    /**
     * Registered routes, keyed by HTTP method.
     *
     * Each method maps to an array of Route objects.
     *
     * @var array<string, Route[]>
     */
    private array $routes = [];

    /**
     * Parameterized routes, keyed by HTTP method.
     *
     * Each method maps to a list of Route objects that contain
     * {param} placeholders (matched via regex).
     *
     * @var array<string, Route[]>
     */
    private array $paramRoutes = [];

    /**
     * Active group stack.
     *
     * Each entry: ['prefix' => string, 'middleware' => class-string[]]
     *
     * @var array<int, array{prefix: string, middleware: array<int, class-string>}>
     */
    private array $groupStack = [];

    /**
     * Middleware applied to ALL routes.
     *
     * @var class-string[]
     */
    private array $globalMiddleware = [];

    // ─── Route Registration ──────────────────────────────────────

    /**
     * Register a GET route.
     *
     * @example $router->get('/users/{id}', [UserController::class, 'show']);
     *
     * @param  string  $path  URI pattern (e.g. '/users/{id}')
     * @param  Closure|array{0: class-string, 1: string}  $handler
     * @return Route The registered Route instance
     */
    public function get(string $path, Closure|array $handler): Route
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * Register a POST route.
     *
     * @example $router->post('/users', [UserController::class, 'store']);
     *
     * @param  string  $path  URI pattern
     * @param  Closure|array{0: class-string, 1: string}  $handler
     * @return Route The registered Route instance
     */
    public function post(string $path, Closure|array $handler): Route
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * Register a PUT route.
     *
     * @example $router->put('/users/{id}', [UserController::class, 'update']);
     *
     * @param  string  $path  URI pattern
     * @param  Closure|array{0: class-string, 1: string}  $handler
     * @return Route The registered Route instance
     */
    public function put(string $path, Closure|array $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a PATCH route.
     *
     * @example $router->patch('/users/{id}', [UserController::class, 'update']);
     *
     * @param  string  $path  URI pattern
     * @param  Closure|array{0: class-string, 1: string}  $handler
     * @return Route The registered Route instance
     */
    public function patch(string $path, Closure|array $handler): Route
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @example $router->delete('/users/{id}', [UserController::class, 'destroy']);
     *
     * @param  string  $path  URI pattern
     * @param  Closure|array{0: class-string, 1: string}  $handler
     * @return Route The registered Route instance
     */
    public function delete(string $path, Closure|array $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a route for multiple HTTP methods.
     *
     * @param  string[]  $methods
     * @param  Closure|array{0: class-string, 1: string}  $handler
     * @return Route[]
     */
    public function match(array $methods, string $path, Closure|array $handler): array
    {
        $routes = [];
        foreach ($methods as $method) {
            $routes[] = $this->addRoute(strtoupper($method), $path, $handler);
        }

        return $routes;
    }

    /**
     * Define a route group with a common prefix and optional middleware.
     *
     * @example
     *   $router->group('/api', function (Router $r) {
     *       $r->get('/users', [UserController::class, 'index']);
     *   });
     * @example
     *   $router->group('/admin', function (Router $r) {
     *       $r->get('/dashboard', [AdminController::class, 'dashboard']);
     *   }, [AdminMiddleware::class]);
     *
     * @param  string  $prefix  URI prefix (e.g. '/api')
     * @param  callable  $callback  Receives this Router instance
     * @param  array<int, class-string>  $middleware  Middleware class names applied to all routes in the group
     */
    public function group(string $prefix, callable $callback, array $middleware = []): void
    {
        $this->groupStack[] = [
            'prefix' => $prefix,
            'middleware' => array_values($middleware),
        ];
        $callback($this);
        array_pop($this->groupStack);
    }

    /**
     * Add middleware applied to ALL routes.
     *
     * @param  class-string  $middleware
     *
     * @see MiddlewareInterface
     */
    public function addMiddleware(string $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    // ─── Dispatch ────────────────────────────────────────────────

    /**
     * Match an incoming request against registered routes.
     *
     * Returns the handler, middleware, and extracted parameters.
     *
     * @return array{handler: Closure|array{0: class-string, 1: string}, middleware: class-string[], params: array<string, string>}
     *
     * @throws HttpNotFoundException If no route matches
     */
    public function dispatch(Request $request): array
    {
        $method = $request->method();
        $uri = $request->uri();

        // Try exact match first (O(1) hash lookup)
        if (isset($this->routes[$method][$uri])) {
            return $this->matchResult($this->routes[$method][$uri], []);
        }

        // Try parameterized match
        if (isset($this->paramRoutes[$method])) {
            foreach ($this->paramRoutes[$method] as $route) {
                $params = $this->matchParameters($route->path, $uri);
                if ($params !== false) {
                    return $this->matchResult($route, $params);
                }
            }
        }

        throw new HttpNotFoundException("No route for {$method} {$uri}");
    }

    /**
     * Build the dispatch result for a matched route.
     *
     * @param  array<string, string>  $params  Extracted route parameters
     * @return array{handler: Closure|array{0: class-string, 1: string}, middleware: class-string[], params: array<string, string>}
     */
    private function matchResult(Route $route, array $params): array
    {
        return [
            'handler' => $route->handler,
            'middleware' => array_merge($this->globalMiddleware, $route->middleware),
            'params' => $params,
        ];
    }

    // ─── Internal ────────────────────────────────────────────────

    /**
     * Create and store a route.
     *
     * @param  string  $method  HTTP method
     * @param  string  $path  URI pattern
     * @param  Closure|array{0: class-string, 1: string}  $handler
     * @return Route The registered Route instance
     */
    private function addRoute(string $method, string $path, Closure|array $handler): Route
    {
        $fullPath = $this->applyGroupPrefix($path);
        $route = new Route($method, $fullPath, $handler);

        // Apply middleware from all active groups
        foreach ($this->groupStack as $group) {
            foreach ($group['middleware'] as $mw) {
                $route->middleware($mw);
            }
        }

        if (str_contains($fullPath, '{')) {
            $this->paramRoutes[$method][] = $route;
        } else {
            if (isset($this->routes[$method][$fullPath]) && ErrorHandler::isDebug()) {
                trigger_error("Route {$method} {$fullPath} is already defined.", E_USER_NOTICE);
            }
            $this->routes[$method][$fullPath] = $route;
        }

        return $route;
    }

    /**
     * Prepend the current group prefix to a path.
     *
     * @param  string  $path  Original route path
     * @return string Path with group prefix applied
     */
    private function applyGroupPrefix(string $path): string
    {
        if (empty($this->groupStack)) {
            return $path;
        }

        $prefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'];
        }

        return '/'.trim($prefix, '/').'/'.ltrim($path, '/');
    }

    /**
     * Match a parameterized route pattern against a URI.
     *
     * Converts {param} placeholders to regex named groups and
     * tests against the URI. Returns extracted parameters on
     * match, or false on failure.
     *
     * @param  string  $pattern  Route pattern (e.g. '/users/{id}')
     * @param  string  $uri  Actual request URI (e.g. '/users/42')
     * @return array<string, string>|false
     */
    private function matchParameters(string $pattern, string $uri): array|false
    {
        // Convert {param} or {param:regex} to named regex groups
        $regex = preg_replace_callback('#\{(\w+)(?::([^}]+))?\}#', function ($m) {
            $pattern = $m[2] ?? '[^/]+';

            return '(?P<'.$m[1].'>'.$pattern.')';
        }, $pattern);
        $regex = '#^'.$regex.'$#';

        if (preg_match($regex, $uri, $matches)) {
            // Keep only named groups (skip numeric indices)
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return false;
    }
}
