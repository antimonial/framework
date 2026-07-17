<?php

declare(strict_types=1);

namespace Antimonial\View;

/**
 * View renderer.
 *
 * Renders view files by extracting data into the local scope and including
 * the file. Supports optional layouts where a view is rendered first, then
 * its content is injected into a layout.
 *
 * The framework ships a built-in template engine (ViewEngine) that compiles
 * templates to cached PHP (Blade-style directives, auto-escaping, |filters,
 * @extends/@section layouts, @include) — see ViewEngine, Compiler and Filters.
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
     * @var ViewEngine|null The built-in template engine (created on setViewPath)
     */
    private static ?ViewEngine $engine = null;

    /**
     * Set the base directory for view files and build the engine.
     *
     * @param string $path Absolute path to the Views directory
     * @return void
     */
    public static function setViewPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
        self::$engine = new ViewEngine(self::$viewPath);
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
     * Render a view file with data using the built-in template engine.
     *
     * @example View::render('users/index', ['users' => $users]);
     *
     * @param string $path View path relative to the Views directory (e.g. 'users/index')
     * @param array<string, mixed> $data Variables to make available in the view
     * @param array<string, mixed>|null &$capturedVars If set, receives any extra variables defined by the view
     * @return string Rendered HTML
     * @throws RuntimeException If the view file does not exist
     * @see renderWithLayout()
     */
    public static function render(string $path, array $data = [], ?array &$capturedVars = null): string
    {
        if (self::$engine === null) {
            self::$engine = new ViewEngine(self::getViewPath());
        }

        return self::$engine->render($path, $data);
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
     * @param string $path View path
     * @param string|null $layout Layout path (null = no layout)
     * @param array<string, mixed> $data Variables for the view
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
}
