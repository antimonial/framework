<?php

declare(strict_types=1);

namespace Antimonial\View;

use RuntimeException;
use \Antimonial\View\Compiler;

/**
 * Built-in template engine for Antimonial.
 *
 * Compiles templates to PHP once, caches them in a storage dir, and
 * includes the cached file. Auto-escaping is on by default.
 *
 * Layouts: a child template calls @extends('layout'); its @section blocks
 * are captured and injected into the parent's @yield slots. Only one
 * @extends per template is supported (matching Blade/Twig).
 *
 * @see View
 * @see Compiler
 */
class ViewEngine
{
    /**
     * @var string Base directory for .php template files
     */
    private string $viewPath;

    /**
     * @var string Directory for compiled PHP cache
     */
    private string $cachePath;

    /**
     * @param string $viewPath  Absolute path to the Views directory
     * @param string $cachePath Absolute path for compiled PHP (default: viewPath/../storage/views)
     */
    public function __construct(string $viewPath, ?string $cachePath = null)
    {
        $this->viewPath = rtrim($viewPath, '/');
        $this->cachePath = $cachePath ?? dirname($this->viewPath) . '/storage/views';
    }

    /**
     * Render a template to a string.
     *
     * @param string $path Template path relative to viewPath (e.g. 'users/index')
     * @param array  $data Variables available in the template
     * @return string
     * @throws RuntimeException If the template is missing
     */
    public function render(string $path, array $data = []): string
    {
        $source = $this->resolve($path);

        $compiled = $this->compiledPath($source);
        if ($this->isExpired($source, $compiled)) {
            (new Compiler())->compile($source, $compiled);
        }

        return $this->evaluate($compiled, $data);
    }

    /**
     * Whether the most recent evaluate() was for a child template that
     * extends a layout (its output is the rendered layout, not free HTML).
     *
     * @var bool
     */
    private bool $extendingChild = false;

    /**
     * Layout path set via @extends, pending render at the end of evaluate().
     *
     * @var string|null
     */
    private ?string $extendLayout = null;

    // ─── Runtime helpers (called from compiled PHP) ────────────

    /**
     * Extends a layout; renders it with this template's sections and the
     * child's free content (everything outside @section) as $content.
     *
     * Called from the footer of a compiled child template, after the child
     * body has been evaluated (so free content and sections are captured).
     *
     * @param string $layout Layout path relative to viewPath
     * @return string
     */
    /**
     * Mark this template as extending a layout.
     *
     * Called from the footer of a compiled child template. evaluate() reads
     * this flag once the child body (free content + @section blocks) has
     * been captured, and renders the layout with that content.
     *
     * @param string $layout Layout path relative to viewPath
     * @return void
     */
    public function beginExtend(string $layout): void
    {
        $this->extendLayout = $layout;
        $this->extendingChild = true;
    }

    /**
     * Start capturing a section.
     *
     * @param string $name
     * @return void
     */
    public function section(string $name): void
    {
        $this->activeSection = $name;
        ob_start();
    }

    /**
     * End the active section and store its content.
     *
     * @return void
     */
    public function endSection(): void
    {
        if ($this->activeSection === null) {
            return;
        }
        $this->sections[$this->activeSection] = ob_get_clean() ?: '';
        $this->activeSection = null;
    }

    /**
     * Output a section's content (with optional default).
     *
     * @param string $name
     * @param string $default
     * @return string
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Placeholder for @parent (renders the parent layout's slot content).
     *
     * @return string
     */
    public function parent(): string
    {
        return '';
    }

    /**
     * Include and render another template, returning its output.
     *
     * If no data is given, the parent template's variables are inherited.
     *
     * @param string $path
     * @param array  $data
     * @return string
     */
    public function include(string $path, array $data = []): string
    {
        if (empty($data)) {
            $data = $this->parentData;
        }
        return $this->render($path, $data);
    }

    // ─── Internals ─────────────────────────────────────────────

    /**
     * @var array<string, string> Captured sections for the current render
     */
    private array $sections = [];

    /**
     * @var string|null Name of the section currently being captured
     */
    private ?string $activeSection = null;

    /**
     * @var array<string, mixed> Data passed down to the extended layout
     */
    private array $parentData = [];

    /**
     * Resolve a template path to an absolute, safe file path.
     *
     * @param string $path
     * @return string
     * @throws RuntimeException
     */
    private function resolve(string $path): string
    {
        $base = realpath($this->viewPath);
        if ($base === false) {
            throw new RuntimeException("View directory not found: {$this->viewPath}");
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

    /**
     * Compute the compiled cache file path (hash of source path).
     *
     * @param string $source
     * @return string
     */
    private function compiledPath(string $source): string
    {
        return $this->cachePath . '/' . hash('xxh128', $source) . '.php';
    }

    /**
     * True if the compiled file is missing or older than the source.
     *
     * @param string $source
     * @param string $compiled
     * @return bool
     */
    private function isExpired(string $source, string $compiled): bool
    {
        if (!is_file($compiled)) {
            return true;
        }
        return filemtime($source) > filemtime($compiled);
    }

    /**
     * Evaluate compiled PHP with extracted data.
     *
     * @param string $compiled
     * @param array  $data
     * @return string
     */
    private function evaluate(string $compiled, array $data): string
    {
        $this->parentData = $data;
        $this->extendingChild = false;
        $this->extendLayout = null;
        $__engine = $this;
        extract($data, EXTR_SKIP);

        ob_start();
        include $compiled;
        $output = ob_get_clean() ?: '';

        // A child template that extends a layout: its free content (text
        // outside @section, already isolated because sections captured
        // their own buffers) becomes $content of the layout.
        if ($this->extendingChild && $this->extendLayout !== null) {
            $content = $output;
            $this->extendingChild = false;
            $layout = $this->extendLayout;
            $this->extendLayout = null;

            return $this->render(
                $layout,
                array_merge($data, ['content' => $content])
            );
        }

        return $output;
    }
}
