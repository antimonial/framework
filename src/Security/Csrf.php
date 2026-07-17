<?php

declare(strict_types=1);

namespace Antimonial\Security;

use Antimonial\Session\Session;

/**
 * Cross-Site Request Forgery protection, the 80%-useful slice.
 *
 * A single token is kept in the session (inspired by Laravel's CSRF, which
 * stores it server-side). Each HTML form must include the token (via the
 * @csrf view directive or Csrf::field()) and state-changing requests
 * (POST/PUT/DELETE/PATCH) must verify it. Comparison is timing-safe.
 *
 * No dependencies, no coupled service: the session is the only store, and
 * it plugs into whatever session the app already uses.
 *
 * @see \Antimonial\Middleware\CsrfMiddleware
 * @see \Antimonial\Session\Session
 */
final class Csrf
{
    private const KEY = '_token';

    /**
     * Get the current token, generating one if absent.
     *
     * @return string
     */
    public static function token(): string
    {
        $token = Session::get(self::KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        Session::put(self::KEY, $token);
        return $token;
    }

    /**
     * Verify a submitted token against the session token.
     *
     * Uses hash_equals for constant-time comparison (timing-safe).
     *
     * @param string|null $submitted
     * @return bool
     * @throws TokenMismatchException When the token is missing or invalid.
     */
    public static function verify(?string $submitted): bool
    {
        $expected = Session::get(self::KEY);

        if (!is_string($expected) || $expected === ''
            || !is_string($submitted) || $submitted === ''
            || !hash_equals($expected, $submitted)) {
            throw new TokenMismatchException('CSRF token mismatch.');
        }

        return true;
    }

    /**
     * Render a hidden input field for use in HTML forms.
     *
     * @return string
     */
    public static function field(): string
    {
        $token = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_token" value="' . $token . '">';
    }
}
