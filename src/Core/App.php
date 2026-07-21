<?php

declare(strict_types=1);

namespace Antimonial\Core;

use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Http\ValidationException;
use Antimonial\Middleware\MiddlewareInterface;
use Antimonial\Routing\HttpNotFoundException;
use Antimonial\Routing\Router;
use Antimonial\Session\Session;
use Antimonial\View\View;
use Closure;
use RuntimeException;
use Throwable;

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
        $this->router = new Router;
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
     */
    public function run(): void
    {
        ErrorHandler::register();

        $timezone = Config::get('app.timezone', 'UTC');
        if (! is_string($timezone)) {
            $timezone = 'UTC';
        }
        date_default_timezone_set($timezone);

        // Opt-in sessions: the framework does not force a session on you.
        // Enable via app/Config/app.php: ['session' => true].
        if (Config::get('app.session', false)) {
            Session::start();
        }

        $request = Request::fromGlobals();

        $this->loadRoutes();

        try {
            $match = $this->router->dispatch($request);

            // Place route parameters into request attributes
            foreach ($match['params'] as $key => $value) {
                $request->set($key, $value);
            }

            /** @var array<int, class-string<MiddlewareInterface>> $middlewares */
            $middlewares = $match['middleware'];
            /** @var array{0: class-string, 1: string}|Closure $handler */
            $handler = $match['handler'];

            $response = $this->runMiddleware(
                $middlewares,
                $request,
                fn (Request $req) => $this->dispatchController($handler, $req)
            );
        } catch (HttpNotFoundException $e) {
            $response = $this->notFoundResponse();
        } catch (ValidationException $e) {
            $response = $this->validationErrorResponse($e->errors(), $request);
        }

        $response->send();
    }

    /**
     * Build a 404 response, falling back to a plain message if the
     * error view or layout cannot be rendered.
     */
    private function notFoundResponse(): Response
    {
        try {
            $body = View::render('errors/404');
        } catch (RuntimeException) {
            $body = '<h1>404 Not Found</h1>';
        }

        return (new Response)
            ->status(404)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->body($body);
    }

    /**
     * Build a 422 response carrying the validation errors as JSON.
     */
    /**
     * Build a response for a failed validation.
     *
     * For JSON / XHR clients (or when sessions are disabled) the errors are
     * returned as a 422 JSON body. For browser form submissions with sessions
     * enabled, the errors and the submitted input are flashed and the user is
     * redirected (303) back to the referring page (defaulting to '/') so the
     * form can be re-populated via the `old()` and `errors()` helpers.
     *
     * @param  array<string, string[]>  $errors  Validation errors keyed by field
     * @param  Request  $request  The originating request
     */
    private function validationErrorResponse(array $errors, Request $request): Response
    {
        $sessionsEnabled = Config::get('app.session', false) === true;

        if ($request->wantsJson() || ! $sessionsEnabled) {
            return (new Response)->json(['errors' => $errors], 422);
        }

        Session::flash('errors', $errors);
        Session::flash('old', $request->all());

        /** @var string $referer */
        $referer = $request->header('referer', '/') ?? '/';

        return (new Response)->redirect($referer, 303);
    }

    /**
     * Load route definitions from the application.
     *
     * Expects `app/Routes/web.php` to exist. The file receives
     * the Router instance as `$router` in its scope.
     */
    private function loadRoutes(): void
    {
        $routesFile = ROOT_PATH.'/app/Routes/web.php';

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
     * @param  array{0: class-string, 1: string}|callable  $handler
     * @param  Request  $request  The incoming request
     * @return Response The controller's response
     *
     * @throws RuntimeException If the handler returns an unsupported type
     */
    private function dispatchController(array|callable $handler, Request $request): Response
    {
        if ($handler instanceof Closure) {
            $result = $handler($request);
        } elseif (is_array($handler)) {
            [$class, $method] = $handler;
            $controller = new $class;
            $result = $controller->$method($request);
        } else {
            throw new RuntimeException('Unsupported controller handler type: '.get_debug_type($handler));
        }

        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return (new Response)->json($result);
        }

        if (is_string($result)) {
            return (new Response)->body($result);
        }

        throw new RuntimeException(
            'Controller must return Response, array, or string. Got '.get_debug_type($result)
        );
    }

    /**
     * Run a chain of middleware around a core handler.
     *
     * Builds a closure chain (onion pattern): each middleware wraps
     * the next, and the innermost layer is the controller dispatch.
     *
     * @param  string[]  $middlewares  Class names implementing MiddlewareInterface
     * @param  Request  $request  The incoming request
     * @param  callable  $core  The final handler (controller dispatch)
     * @return Response The final response
     *
     * @throws Throwable Any exception from middleware or controller
     *
     * @see MiddlewareInterface
     */
    private function runMiddleware(array $middlewares, Request $request, callable $core): Response
    {
        $handler = $core;

        foreach (array_reverse($middlewares) as $middleware) {
            $next = $handler;
            $handler = function (Request $req) use ($middleware, $next) {
                /** @var MiddlewareInterface $instance */
                $instance = new $middleware;

                return $instance->handle($req, $next);
            };
        }

        $result = $handler($request);

        if (! $result instanceof Response) {
            throw new RuntimeException(
                'Middleware must return an instance of '.Response::class.', got '.get_debug_type($result)
            );
        }

        return $result;
    }
}
