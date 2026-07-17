<?php

declare(strict_types=1);

namespace Antimonial\Session;

use Antimonial\Security\Csrf;

/**
 * Minimal server-side session, built on PHP's native $_SESSION.
 *
 * Inspired by the ergonomics of Laravel/Symfony sessions but following
 * Antimonial's "no magic, no coupled services" rule: there is no custom
 * storage backend, no serializer, no flash bags — just a thin, replaceable
 * wrapper over $_SESSION plus a tiny flash layer.
 *
 * Start it once (e.g. from App::run or a bootstrap file) and read/write
 * anywhere via the static API.
 *
 * @see Csrf  uses the session to store its token.
 */
final class Session
{
    private static bool $started = false;

    /**
     * Start (or resume) the native PHP session.
     *
     * Safe to call multiple times. On the first call of the process the
     * session is created/resumed; on later calls (a new request resuming
     * the session) any flash data from the previous request is aged out.
     *
     * @param  array<string, mixed>  $options  session_start() options
     */
    public static function start(array $options = []): void
    {
        if (self::$started) {
            // Subsequent resume: drop flash written on the previous request.
            unset($_SESSION['__flash']);

            return;
        }

        if (headers_sent()) {
            return; // Cannot start a session once output has begun.
        }

        session_start($options);
        self::$started = true;

        // Any flash left over from a prior request is no longer valid.
        unset($_SESSION['__flash']);
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Put one or many items into the session.
     *
     * @param  string|array<string, mixed>  $key
     */
    public static function put(string|array $key, mixed $value = null): void
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $_SESSION[$k] = $v;
            }

            return;
        }
        $_SESSION[$key] = $value;
    }

    /**
     * Get and forget: returns the value and removes it.
     */
    public static function pull(string $key, mixed $default = null): mixed
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    /**
     * Flash data: available on the next request only (then auto-cleared).
     */
    public static function flash(string $key, mixed $value): void
    {
        /** @var array<string, mixed> $flash */
        $flash = $_SESSION['__flash'] ?? [];
        $flash[$key] = $value;
        $_SESSION['__flash'] = $flash;
    }

    /**
     * Read flash data from the previous request (before it ages out).
     */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        /** @var array<string, mixed> $flash */
        $flash = $_SESSION['__flash'] ?? [];

        return $flash[$key] ?? $default;
    }

    /**
     * @param  string|array<int, string>  $key
     */
    public static function forget(string|array $key): void
    {
        if (is_string($key)) {
            unset($_SESSION[$key]);

            return;
        }

        /** @var array<int, string> $key */
        foreach ($key as $k => $v) {
            unset($_SESSION[$k]);
        }
    }

    public static function flush(): void
    {
        $_SESSION = [];
    }

    /**
     * Regenerate the session id (call after login / privilege change).
     *
     * @param  bool  $destroy  Whether to destroy the old session data.
     */
    public static function regenerate(bool $destroy = false): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
            self::$started = true;
        }
        session_regenerate_id($destroy);
    }

    public static function id(): string
    {
        return (string) session_id();
    }
}
