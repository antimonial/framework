<?php

declare(strict_types=1);

namespace Antimonial\Middleware;

use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Security\Auth;
use Antimonial\Session\Session;

/**
 * Guest middleware.
 *
 * The inverse of {@see AuthMiddleware}: it blocks already-authenticated
 * requests (e.g. the login / register pages) by redirecting browsers to a
 * home route (default '/') and returning 403 for JSON / XHR clients.
 * Guest requests pass straight through.
 *
 * @see Auth
 * @see AuthMiddleware
 */
final class GuestMiddleware implements MiddlewareInterface
{
    /**
     * URL to redirect authenticated browsers to.
     */
    private const HOME_URL = '/';

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request  Incoming HTTP request
     * @param  callable  $next  Next middleware / controller
     */
    public function handle(Request $request, callable $next): Response
    {
        Session::start();

        if (! Auth::check()) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        if (self::wantsJson($request)) {
            return (new Response)
                ->status(403)
                ->header('Content-Type', 'application/json; charset=UTF-8')
                ->body('{"error":"Forbidden."}');
        }

        return (new Response)->redirect(self::HOME_URL, 302);
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
