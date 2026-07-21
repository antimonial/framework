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

        if ($request->wantsJson()) {
            return (new Response)->json(['error' => 'Unauthenticated.'], 401);
        }

        return (new Response)->redirect(self::LOGIN_URL, 302);
    }
}
