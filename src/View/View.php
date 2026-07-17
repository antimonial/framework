<?php

declare(strict_types=1);

namespace Antimonial\View;

use RuntimeException;

/**
 * View renderer.
 *
 * Renders PHP view files by extracting data into the local scope
 * and including the file. Supports optional layouts where a view
 * is rendered first, then its content is injected into a layout.
 *
 * A built-in template engine (ViewEngine) ships with the framework and
 * is auto-registered on first render. It compiles templates to cached
 * PHP (Blade-style directives, auto-escaping, |filters, @extends/@section
 * layouts, @include) — see ViewEngine, Compiler and Filters.
 *
 * The renderer also exposes an extension point: call setEngine(null) to
 * force native PHP rendering, or setEngine($custom) to swap in your own
 * engine (must implement render(string, array): string).
 *
 * @see \Antimonial\Controller\Controller::view()
 * @see Helpers::view()
 * @see ViewEngine
 */
class View
{
    /**
     * @var string Base directory for view files
     */
    private static string $viewPath = '';

    /**
     * Optional custom rendering engine.
     *
     * When set, this engine is used instead of native PHP rendering.
     * The engine must implement a render(string $path, array $data): string method.
     *
     * If left null, the built-in ViewEngine is auto-registered on first
     * render (so the template engine ships with the framework). Set it to
     * null explicitly to force native PHP rendering.
     *
     * @var object|null
     */
    private static ?object $engine = null;

    /**
     * Whether the engine slot has been resolved (auto or explicit).
     *
     * @var bool
     */
    private static bool $engineResolved = false;

    /**
     * Set the base directory for view files.
     *
     * @param string $path Absolute path to the Views directory
     * @return void
     */
    public static function setViewPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
    }

    /**
     * Get the base directory for view files.
     *
     * Defaults to ROOT_PATH/app/Views if not explicitly set.
     *
     * @return string
     */
    private static function getViewPath(): string
    {
        if (self::$viewPath === '') {
            self::$viewPath = ROOT_PATH . '/app/Views';
        }
        return self::$viewPath;
    }

    /**
     * Resolve the rendering engine.
     *
     * Lazily registers the built-in ViewEngine on first use unless an
     * engine (or explicit null) was already set via setEngine().
     *
     * @return object|null
     */
    private static function resolveEngine(): ?object
    {
        if (self::$engineResolved) {
            return self::$engine;
        }

        self::$engineResolved = true;

        if (self::$engine === null) {
            self::$engine = new ViewEngine(self::getViewPath());
        }

        return self::$engine;
    }

    /**
     * Set a custom rendering engine.
     *
     * Pass null to force native PHP rendering (skips the built-in engine).
     *
     * @param object|null $engine Must implement render(string, array): string
     * @return void
     */
    public static function setEngine(?object $engine): void
    {
        self::$engine = $engine;
        self::$engineResolved = true;
    }

    /**
     * Render a view file with data.
     *
     * @example View::render('users/index', ['users' => $users]);
     *
     * @param string $path  View path relative to the Views directory (e.g. 'users/index')
     * @param array  $data  Variables to make available in the view
     * @param array|null &$capturedVars If set, receives any extra variables defined by the view
     * @return string Rendered HTML
     * @throws RuntimeException If the view file does not exist
     * @see renderWithLayout()
     */
    public static function render(string $path, array $data = [], ?array &$capturedVars = null): string
    {
        $engine = self::resolveEngine();

        if ($engine !== null) {
            return $engine->render($path, $data);
        }

        $file = self::resolve($path);

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        $output = ob_get_clean() ?: '';

        if ($capturedVars !== null) {
            $afterVars = get_defined_vars();
            $internalKeys = array_flip([
                'path', 'data', 'capturedVars', 'file', 'output', 'afterVars', 'internalKeys',
            ]);
            $capturedVars = array_diff_key($afterVars, $internalKeys, $data);
        }

        return $output;
    }

    /**
     * Render a view inside an optional layout.
     *
     * When a layout is provided:
     *  1. The inner view is rendered first
     *  2. The layout is rendered with $content (the inner view's output)
     *     and all original data variables available
     *
     * @note The layout receives the inner view's output as `$content`. Do not
     *       name a view variable `content`, as it would be overwritten.
     *
     * @example View::renderWithLayout('users/index', 'layouts/main', ['users' => $users]);
     *
     * @param string      $path   View path
     * @param string|null $layout Layout path (null = no layout)
     * @param array       $data   Variables for the view
     * @return string
     * @throws RuntimeException If the view file does not exist
     * @see render()
     */
    public static function renderWithLayout(string $path, ?string $layout, array $data = []): string
    {
        if ($layout === null) {
            return self::render($path, $data);
        }

        $captured = [];
        $content = self::render($path, $data, $captured);

        $layoutData = array_merge($data, ['content' => $content], $captured);

        return self::render($layout, $layoutData);
    }

    /**
     * Resolve a view path to an absolute file path.
     *
     * @param string $path
     * @return string Absolute file path
     * @throws RuntimeException If the view file does not exist
     */
    private static function resolve(string $path): string
    {
        $base = realpath(self::getViewPath());

        if ($base === false) {
            throw new RuntimeException("View directory not found: " . self::getViewPath());
        }

        $file = realpath($base . '/' . ltrim($path, '/') . '.php');

        if ($file === false
            || strncmp($file, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
            || !is_file($file)
        ) {
            throw new RuntimeException("View not found: {$path}");
        }

        return $file;
    }
}
