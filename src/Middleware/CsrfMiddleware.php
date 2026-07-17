<?php

declare(strict_types=1);

namespace Antimonial\Middleware;

use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Security\Csrf;
use Antimonial\Security\TokenMismatchException;
use Antimonial\Session\Session;

/**
 * CSRF protection middleware.
 *
 * Verifies the request token for state-changing methods (POST/PUT/DELETE/
 * PATCH). Safe methods (GET/HEAD/OPTIONS) pass through untouched. On a
 * mismatch it returns a 419 response.
 *
 * Register it globally or on a route group — the Router already supports
 * both. It is opt-in: the framework does not force CSRF on you.
 *
 * @see \Antimonial\Security\Csrf
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, callable $next): Response
    {
        if (in_array(strtoupper($request->method()), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        Session::start();

        try {
            Csrf::verify($request->post('_token') ?? $request->header('X-CSRF-TOKEN'));
        } catch (TokenMismatchException) {
            return (new Response())
                ->status(419)
                ->header('Content-Type', 'text/plain; charset=UTF-8')
                ->body('419 Page Expired');
        }

        return $next($request);
    }
}
