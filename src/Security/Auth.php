<?php

declare(strict_types=1);

namespace Antimonial\Security;

use Antimonial\Model\Model;
use Antimonial\Session\Session;

/**
 * Simple authentication facade.
 *
 * A tiny static facade over "a user is identified by a row in some
 * table". It is deliberately not an ORM or a guard system: you point it
 * at your user model via {@see Auth::useModel()}, then {@see Auth::attempt()}
 * verifies credentials (bcrypt by default) and {@see Auth::login()} stores
 * the user's id in the session and regenerates the session id to defend
 * against session fixation.
 *
 * The session is the only store, reusing the framework's opt-in
 * {@see Session} layer — no separate auth service to wire up.
 *
 * @see AuthMiddleware  redirects unauthenticated requests.
 * @see GuestMiddleware redirects already-authenticated requests.
 */
final class Auth
{
    /**
     * Session key under which the authenticated user's id is stored.
     */
    private const SESSION_KEY = '_auth_user_id';

    /**
     * Name of the password credential key in attempt().
     */
    private const PASSWORD_KEY = 'password';

    /**
     * The user model class name (must extend Model).
     *
     * @var class-string<Model>|null
     */
    private static ?string $modelClass = null;

    /**
     * Point Auth at the application's user model.
     *
     * @param  class-string<Model>  $modelClass  A class extending Model
     */
    public static function useModel(string $modelClass): void
    {
        self::$modelClass = $modelClass;
    }

    /**
     * Attempt to authenticate using the given credentials.
     *
     * The credentials array must contain a 'password' key (the plain-text
     * password to verify) plus one or more lookup fields (e.g. 'email').
     * The matching user row is loaded and its 'password' column is verified
     * with password_verify(). On success the user is logged in.
     *
     * @param  array<string, mixed>  $credentials  e.g. ['email' => 'a@b.c', 'password' => 'secret']
     * @return bool True if the credentials were valid and the user logged in
     */
    public static function attempt(array $credentials): bool
    {
        $password = $credentials[self::PASSWORD_KEY] ?? null;
        if (! is_string($password)) {
            return false;
        }

        $lookup = $credentials;
        unset($lookup[self::PASSWORD_KEY]);
        if ($lookup === []) {
            return false;
        }

        $user = self::findByCredentials($lookup);
        if ($user === null) {
            return false;
        }

        $hash = $user->{self::PASSWORD_KEY} ?? null;
        if (! is_string($hash) || ! password_verify($password, $hash)) {
            return false;
        }

        self::login($user);

        return true;
    }

    /**
     * Log a user in: store their id and regenerate the session.
     *
     * @param  object  $user  The user record (must expose an 'id')
     */
    public static function login(object $user): void
    {
        $id = $user->id ?? null;
        if ($id === null) {
            return;
        }

        Session::put(self::SESSION_KEY, $id);
        Session::regenerate();
    }

    /**
     * Log the current user out and regenerate the session.
     */
    public static function logout(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::regenerate();
    }

    /**
     * Whether a user is currently authenticated.
     *
     * @return bool True if a user is logged in
     */
    public static function check(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    /**
     * Get the id of the authenticated user.
     *
     * @return int|null The user id, or null if not authenticated
     */
    public static function id(): ?int
    {
        $id = Session::get(self::SESSION_KEY);

        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && is_numeric($id)) {
            return (int) $id;
        }

        return null;
    }

    /**
     * Get the authenticated user record, or null.
     *
     * @return object|null The user row, or null if not authenticated
     */
    public static function user(): ?object
    {
        $id = self::id();
        if ($id === null || self::$modelClass === null) {
            return null;
        }

        /** @var Model $model */
        $model = new (self::$modelClass)();

        return $model->find($id);
    }

    /**
     * Find a user row by the given lookup fields (AND combined).
     *
     * @param  array<string, mixed>  $lookup
     */
    private static function findByCredentials(array $lookup): ?object
    {
        if (self::$modelClass === null) {
            return null;
        }

        /** @var Model $model */
        $model = new (self::$modelClass)();
        $query = $model->query();

        foreach ($lookup as $field => $value) {
            $query->where((string) $field, $value);
        }

        return $query->first();
    }
}
