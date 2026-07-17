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

    public static function setViewPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/');
        self::$engine = new ViewEngine(self::$viewPath);
    }

    private static function getViewPath(): string
    {
        if (self::$viewPath === '') {
            self::$viewPath = ROOT_PATH.'/app/Views';
        }

        return self::$viewPath;
    }

    private static function engine(): ViewEngine
    {
        if (self::$engine === null) {
            self::$engine = new ViewEngine(self::getViewPath());
        }

        return self::$engine;
    }

    public static function render(string $path, array $data = [], ?array &$capturedVars = null): string
    {
        return self::engine()->render($path, $data);
    }

    public static function renderWithLayout(string $path, ?string $layout, array $data = []): string
    {
        if ($layout === null) {
            return self::render($path, $data);
        }

        $captured = [];
        $content = self::render($path, $data, $captured);

        /** @var array<string, mixed> $layoutData */
        $layoutData = array_merge($data, ['content' => $content], $captured);

        return self::render($layout, $layoutData);
    }
}
