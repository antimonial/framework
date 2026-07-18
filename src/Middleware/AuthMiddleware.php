<?php

declare(strict_types=1);

namespace Antimonial\Middleware;

use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Security\Auth;
use Antimonial\Session\Session;

/**
 * Authentication middleware.
 *
 * Blocks unauthenticated requests by redirecting browsers to a login
 * route (default '/login') and returning 401 for JSON / XHR clients.
 * Authenticated requests pass straight through.
 *
 * Register it on protected routes or route groups — the Router already
 * supports both. It is opt-in: the framework does not force auth on you.
 *
 * @see Auth
 * @see GuestMiddleware
 */
final class AuthMiddleware implements MiddlewareInterface
{
    /**
     * URL to redirect unauthenticated browsers to.
     */
    private const LOGIN_URL = '/login';

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request  Incoming HTTP request
     * @param  callable  $next  Next middleware / controller
     */
    public function handle(Request $request, callable $next): Response
    {
        Session::start();

        if (Auth::check()) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        if (self::wantsJson($request)) {
            return (new Response)
                ->status(401)
                ->header('Content-Type', 'application/json; charset=UTF-8')
                ->body('{"error":"Unauthenticated."}');
        }

        return (new Response)->redirect(self::LOGIN_URL, 302);
    }

    /**
     * Whether the request expects a JSON response.
     *
     * @param  Request  $request  Incoming HTTP request
     */
    private static function wantsJson(Request $request): bool
    {
        /** @var mixed $acceptRaw */
        $acceptRaw = $request->header('Accept', '');
        /** @var mixed $xhrRaw */
        $xhrRaw = $request->header('X-Requested-With', '');

        $accept = is_string($acceptRaw) ? $acceptRaw : '';
        $xhr = is_string($xhrRaw) ? $xhrRaw : '';

        return stripos($accept, 'application/json') !== false
            || strtolower($xhr) === 'xmlhttprequest';
    }
}
