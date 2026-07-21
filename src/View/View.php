<?php

declare(strict_types=1);

namespace Antimonial\View;

use Antimonial\Controller\Controller;
use RuntimeException;

/**
 * View renderer.
 *
 * Renders view files by extracting data into the local scope and including
 * the file. Supports optional layouts where a view is rendered first, then
 * its content is injected into a layout.
 *
 * The framework ships a built-in template engine (ViewEngine) that compiles
 * templates to cached PHP (Blade-style directives, auto-escaping, |filters,
 * the extends/section layouts, and include) — see ViewEngine, Compiler
 * and Filters.
 *
 * @see Controller::view()
 * @see Helpers::view()
 * @see ViewEngine
 */
class View
{
    private static string $viewPath = '';

    private static ?ViewEngine $engine = null;

    /**
     * Set the base view directory.
     *
     * Also resets the engine so the new path takes effect immediately.
     */
    public static function setViewPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
        self::$engine = new ViewEngine(self::$viewPath);
    }

    /**
     * Get the base view directory.
     *
     * The view path must be declared explicitly via setViewPath(); the
     * framework does not assume a directory. If it was never set, the
     * caller is told to configure it instead of silently guessing.
     *
     * @return string The view directory path
     *
     * @throws RuntimeException If setViewPath() was never called
     */
    private static function getViewPath(): string
    {
        if (self::$viewPath === '') {
            throw new RuntimeException(
                'View path not configured. Call View::setViewPath($path) (e.g. from your bootstrap) before rendering views.'
            );
        }

        return self::$viewPath;
    }

    /**
     * Get (or create) the shared ViewEngine instance.
     */
    private static function engine(): ViewEngine
    {
        if (self::$engine === null) {
            self::$engine = new ViewEngine(self::getViewPath());
        }

        return self::$engine;
    }

    /**
     * Render a view to a string.
     *
     * @param  string  $path  View path relative to the view directory
     * @param  array<string, mixed>  $data  Variables extracted into the view
     *
     * @throws RuntimeException If the template is missing
     */
    public static function render(string $path, array $data = []): string
    {
        return self::engine()->render($path, $data);
    }
}
