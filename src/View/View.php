<?php

declare(strict_types=1);

namespace Antimonial\View;

/**
 * View renderer.
 *
 * Renders PHP view files by extracting data into the local scope
 * and including the file. Supports optional layouts where a view
 * is rendered first, then its content is injected into a layout.
 *
 * Views are plain PHP files in app/Views/. No template engine required.
 *
 * The renderer also exposes an extension point: you can swap in
 * a custom engine (Blade, Twig, etc.) via setEngine() without
 * modifying any framework code.
 *
 * @see \Antimonial\Controller\Controller::view()
 * @see Helpers::view()
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
     * @var object|null
     */
    private static ?object $engine = null;

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
     * Set a custom rendering engine.
     *
     * @param object|null $engine Must implement render(string, array): string
     * @return void
     */
    public static function setEngine(?object $engine): void
    {
        self::$engine = $engine;
    }

    /**
     * Render a view file with data.
     *
     * @example View::render('users/index', ['users' => $users]);
     *
     * @param string $path View path relative to the Views directory (e.g. 'users/index')
     * @param array  $data Variables to make available in the view
     * @return string Rendered HTML
     * @throws \RuntimeException If the view file does not exist
     * @see renderWithLayout()
     */
    public static function render(string $path, array $data = []): string
    {
        if (self::$engine !== null) {
            return self::$engine->render($path, $data);
        }

        $file = self::resolve($path);

        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return ob_get_clean() ?: '';
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
     * @throws \RuntimeException If the view file does not exist
     * @see render()
     */
    public static function renderWithLayout(string $path, ?string $layout, array $data = []): string
    {
        if ($layout === null) {
            return self::render($path, $data);
        }

        $content = self::render($path, $data);

        $layoutData = array_merge($data, ['content' => $content]);

        return self::render($layout, $layoutData);
    }

    /**
     * Resolve a view path to an absolute file path.
     *
     * @param string $path
     * @return string Absolute file path
     * @throws \RuntimeException If the view file does not exist
     */
    private static function resolve(string $path): string
    {
        $base = realpath(self::getViewPath());

        if ($base === false) {
            throw new \RuntimeException("View directory not found: " . self::getViewPath());
        }

        $file = realpath($base . '/' . ltrim($path, '/') . '.php');

        if ($file === false
            || strncmp($file, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
            || !is_file($file)
        ) {
            throw new \RuntimeException("View not found: {$path}");
        }

        return $file;
    }
}
