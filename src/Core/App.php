<?php

declare(strict_types=1);

namespace Antimonial\Core;

use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Routing\Router;
use Antimonial\Core\ValidationException;

/**
 * Application kernel.
 *
 * Orchestrates the entire HTTP lifecycle: boots the environment,
 * loads routes, matches the request, runs middleware, executes the
 * controller, and sends the response.
 *
 * The `run()` method is intentionally the single entry point
 * for the whole framework. Read it top-to-bottom and you'll
 * understand every step.
 *
 * @see Router
 * @see Request
 * @see Response
 */
class App
{
    /**
     * @var Router The router instance
     */
    private Router $router;

    /**
     * Create the application and initialize the router.
     */
    public function __construct()
    {
        $this->router = new Router();
    }

    /**
     * Run the application.
     *
     * This is the full HTTP lifecycle:
     *
     *  1. Register error handlers
     *  2. Set timezone from config
     *  3. Build the Request from superglobals
     *  4. Load route definitions
     *  5. Dispatch: match route -> run middleware -> execute controller
     *  6. Send the Response
     *
     * @return void
     */
    public function run(): void
    {
        ErrorHandler::register();

        $timezone = Config::get('app.timezone', 'UTC');
        date_default_timezone_set($timezone);

        $request = Request::fromGlobals();

        $this->loadRoutes();

        try {
            $match = $this->router->dispatch($request);

            // Place route parameters into request attributes
            foreach ($match['params'] as $key => $value) {
                $request->set($key, $value);
            }

            $middlewares = $match['middleware'];
            $handler = $match['handler'];

            $response = $this->runMiddleware(
                $middlewares,
                $request,
                fn (Request $req) => $this->dispatchController($handler, $req)
            );
        } catch (HttpNotFoundException $e) {
            $response = (new Response())
                ->status(404)
                ->header('Content-Type', 'text/html; charset=UTF-8')
                ->body('<h1>404 Not Found</h1>');
        } catch (ValidationException $e) {
            $errors = $e->errors();

            try {
                $body = json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $body = json_encode(['errors' => ['validation' => 'unencodable']]);
            }

            $response = (new Response())
                ->status(422)
                ->header('Content-Type', 'application/json; charset=UTF-8')
                ->body($body);
        }

        $response->send();
    }

    /**
     * Load route definitions from the application.
     *
     * Expects `app/Routes/web.php` to exist. The file receives
     * the Router instance as `$router` in its scope.
     *
     * @return void
     */
    private function loadRoutes(): void
    {
        $routesFile = ROOT_PATH . '/app/Routes/web.php';

        if (file_exists($routesFile)) {
            $router = $this->router;
            require $routesFile;
        }
    }

    /**
     * Execute a controller handler.
     *
     * Accepts either:
     *  - A closure: `fn() => 'hello'`
     *  - An array: `[UserController::class, 'show']`
     *
     * The return value is automatically normalized:
     *  - Response -> used as-is
     *  - array -> converted to JSON response
     *  - string -> wrapped in an HTML response
     *
     * @param array{0: class-string, 1: string}|callable $handler
     * @param Request $request
     * @return Response
     * @throws \RuntimeException If the handler returns an unsupported type
     */
    private function dispatchController(array|callable $handler, Request $request): Response
    {
        if ($handler instanceof \Closure) {
            $result = $handler($request);
        } else {
            [$class, $method] = $handler;
            $controller = new $class();
            $result = $controller->$method($request);
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return (new Response())->json($result);
        }

        if (is_string($result)) {
            return (new Response())->body($result);
        }

        throw new \RuntimeException(
            'Controller must return Response, array, or string. Got ' . get_debug_type($result)
        );
    }

    /**
     * Run a chain of middleware around a core handler.
     *
     * Builds a closure chain (onion pattern): each middleware wraps
     * the next, and the innermost layer is the controller dispatch.
     *
     * @param string[]  $middlewares Class names implementing MiddlewareInterface
     * @param Request   $request
     * @param callable  $core       The final handler (controller dispatch)
     * @return Response
     * @throws \Throwable Any exception from middleware or controller
     * @see \Antimonial\Middleware\MiddlewareInterface
     */
    private function runMiddleware(array $middlewares, Request $request, callable $core): Response
    {
        $handler = $core;

        foreach (array_reverse($middlewares) as $middleware) {
            $next = $handler;
            $handler = function (Request $req) use ($middleware, $next) {
                $instance = is_string($middleware) ? new $middleware() : $middleware;
                return $instance->handle($req, $next);
            };
        }

        $result = $handler($request);

        if (!$result instanceof Response) {
            throw new \RuntimeException(
                'Middleware must return an instance of ' . Response::class . ', got ' . get_debug_type($result)
            );
        }

        return $result;
    }
}
