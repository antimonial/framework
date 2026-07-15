<?php

declare(strict_types=1);

namespace Antimonial\Routing;

use Closure;

use Antimonial\Core\ErrorHandler;
use Antimonial\Core\HttpNotFoundException;
use Antimonial\Http\Request;

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
 * @see \Antimonial\Core\App
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
     * Active group prefix stack.
     *
     * @var string[]
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
     * @param string                                      $path
     * @param Closure|array{0: class-string, 1: string}  $handler
     * @return Route
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
     * @param string                                      $path
     * @param Closure|array{0: class-string, 1: string}  $handler
     * @return Route
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
     * @param string                                      $path
     * @param Closure|array{0: class-string, 1: string}  $handler
     * @return Route
     */
    public function put(string $path, Closure|array $handler): Route
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * Register a DELETE route.
     *
     * @example $router->delete('/users/{id}', [UserController::class, 'destroy']);
     *
     * @param string                                      $path
     * @param Closure|array{0: class-string, 1: string}  $handler
     * @return Route
     */
    public function delete(string $path, Closure|array $handler): Route
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * Register a route for multiple HTTP methods.
     *
     * @param string[]                                    $methods
     * @param string                                      $path
     * @param Closure|array{0: class-string, 1: string}  $handler
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
     * Define a route group with a common prefix.
     *
     * @example
     *   $router->group('/api', function (Router $r) {
     *       $r->get('/users', [UserController::class, 'index']);
     *   });
     *
     * @param string   $prefix   URI prefix (e.g. '/api')
     * @param callable $callback Receives this Router instance
     * @return void
     */
    public function group(string $prefix, callable $callback): void
    {
        $this->groupStack[] = $prefix;
        $callback($this);
        array_pop($this->groupStack);
    }

    /**
     * Add middleware applied to ALL routes.
     *
     * @param class-string $middleware
     * @return void
     * @see \Antimonial\Middleware\MiddlewareInterface
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
     * @param Request $request
     * @return array{handler: Closure|array, middleware: class-string[], params: array<string, string>}
     * @throws HttpNotFoundException If no route matches
     */
    public function dispatch(Request $request): array
    {
        $method = $request->method();
        $uri = $request->uri();

        // Try exact match first (O(1) hash lookup)
        if (isset($this->routes[$method][$uri])) {
            $route = $this->routes[$method][$uri];
            return [
                'handler'    => $route->handler,
                'middleware' => array_merge($this->globalMiddleware, $route->middleware),
                'params'     => [],
            ];
        }

        // Try parameterized match
        if (isset($this->paramRoutes[$method])) {
            foreach ($this->paramRoutes[$method] as $route) {
                $params = $this->matchParameters($route->path, $uri);
                if ($params !== false) {
                    return [
                        'handler'    => $route->handler,
                        'middleware' => array_merge($this->globalMiddleware, $route->middleware),
                        'params'     => $params,
                    ];
                }
            }
        }

        throw new HttpNotFoundException("No route for {$method} {$uri}");
    }

    // ─── Internal ────────────────────────────────────────────────

    /**
     * Create and store a route.
     *
     * @param string              $method
     * @param string              $path
     * @param Closure|array      $handler
     * @return Route The registered Route instance
     */
    private function addRoute(string $method, string $path, Closure|array $handler): Route
    {
        $fullPath = $this->applyGroupPrefix($path);
        $route = new Route($method, $fullPath, $handler);

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
     * @param string $path
     * @return string
     */
    private function applyGroupPrefix(string $path): string
    {
        if (empty($this->groupStack)) {
            return $path;
        }

        $prefix = implode('', $this->groupStack);
        return '/' . trim($prefix, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Match a parameterized route pattern against a URI.
     *
     * Converts {param} placeholders to regex named groups and
     * tests against the URI. Returns extracted parameters on
     * match, or false on failure.
     *
     * @param string $pattern Route pattern (e.g. '/users/{id}')
     * @param string $uri     Actual request URI (e.g. '/users/42')
     * @return array<string, string>|false
     */
    private function matchParameters(string $pattern, string $uri): array|false
    {
        // Convert {param} to named regex groups
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            // Extract only named groups (skip numeric indices)
            $params = [];
            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = $value;
                }
            }
            return $params;
        }

        return false;
    }
}
