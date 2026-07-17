<?php

declare(strict_types=1);

namespace Antimonial\Middleware;

use Antimonial\Core\App;
use Antimonial\Http\Request;
use Antimonial\Http\Response;

/**
 * Contract for middleware.
 *
 * Middleware sits between the incoming request and the controller,
 * performing actions like authentication, logging, or CSRF checks.
 *
 * A middleware receives the request and a $next callable. It can:
 *  - Inspect/modify the request, then call $next to continue
 *  - Return a Response early (aborting the chain)
 *  - Modify the Response after $next returns
 *
 * @example
 *   class Auth implements MiddlewareInterface
 *   {
 *       public function handle(Request $request, callable $next): Response
 *       {
 *           if (!$request->header('Authorization')) {
 *               return (new Response())->status(401)->body('Unauthorized');
 *           }
 *           return $next($request);
 *       }
 *   }
 *
 * @see App::runMiddleware()
 */
interface MiddlewareInterface
{
    /**
     * Handle an incoming request.
     *
     * @param  callable  $next  callable(Request): Response
     */
    public function handle(Request $request, callable $next): Response;
}
