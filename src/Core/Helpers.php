<?php

declare(strict_types=1);

/**
 * Global helper functions.
 *
 * These are convenience wrappers for common framework operations.
 * They live in the global namespace so you can call them from anywhere.
 *
 * @see \Antimonial\View\View
 * @see \Antimonial\Http\Response
 * @see \Antimonial\Core\Config
 */

use Antimonial\View\View;
use Antimonial\Http\Response;

/**
 * Render a view and return a Response.
 *
 * @example return view('users/index', ['users' => $users], 'layouts/main');
 *
 * @param string      $path   View path relative to app/Views
 * @param array       $data   Variables for the view
 * @param string|null $layout Optional layout to wrap the view
 * @return Response
 * @see View::renderWithLayout()
 */
function view(string $path, array $data = [], ?string $layout = null): Response
{
    $html = View::renderWithLayout($path, $layout, $data);

    return (new Response())
        ->header('Content-Type', 'text/html; charset=UTF-8')
        ->body($html);
}

/**
 * Create a redirect response.
 *
 * @example redirect('/login', 302);
 *
 * @param string $url
 * @param int    $status HTTP status code (301, 302, etc.)
 * @return Response
 * @see Response::redirect()
 */
function redirect(string $url, int $status = 302): Response
{
    return (new Response())->redirect($url, $status);
}

/**
 * Escape HTML to prevent XSS.
 *
 * @example echo e($userInput);
 *
 * @param string|null $value
 * @return string
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Get a value from the environment.
 *
 * Reads via getenv(). Variables populated by Core\DotEnv::load() (e.g. from
 * a .env file) are resolved here too. Process environment variables always
 * take precedence over values loaded from .env.
 *
 * @example $dbHost = env('DB_HOST', 'localhost');
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function env(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    // Type-cast common values
    return match (strtolower($value)) {
        'true', 'yes', '1' => true,
        'false', 'no', '0' => false,
        'null', '' => null,
        default => $value,
    };
}

/**
 * Get a config value using dot notation.
 *
 * @example $timezone = config('app.timezone', 'UTC');
 *
 * @param string $key
 * @param mixed  $default
 * @return mixed
 * @see \Antimonial\Core\Config::get()
 */
function config(string $key, mixed $default = null): mixed
{
    return \Antimonial\Core\Config::get($key, $default);
}

/**
 * Dump variables and die (debug helper).
 *
 * @example dd($user, $request->all());
 *
 * @return never
 */
function dd(mixed ...$vars): never
{
    echo '<pre style="background:#1e1e2e;color:#cdd6f4;padding:1rem;font-family:monospace;">';
    foreach ($vars as $var) {
        ob_start();
        var_dump($var);
        echo htmlspecialchars(ob_get_clean(), ENT_QUOTES, 'UTF-8');
    }
    echo '</pre>';
    exit(1);
}

/**
 * Dump variables and die (JSON output).
 *
 * @return never
 */
function ddj(mixed ...$vars): never
{
    header('Content-Type: application/json');

    try {
        echo json_encode(
            count($vars) === 1 ? $vars[0] : $vars,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    } catch (\JsonException $e) {
        echo json_encode(['error' => 'json_encode failed: ' . $e->getMessage()]);
    }

    exit(1);
}
