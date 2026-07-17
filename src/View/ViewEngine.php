<?php

declare(strict_types=1);

namespace Antimonial\View;

use RuntimeException;

/**
 * Built-in template engine for Antimonial.
 *
 * Compiles templates to PHP once, caches them in a storage dir, and
 * includes the cached file. Auto-escaping is on by default.
 *
 * Layouts: a child template calls the extends directive (@extends('layout'));
 * its @section blocks are captured and injected into the parent's @yield
 * slots. Only one extends directive per template is supported
 * (matching Blade/Twig).
 *
 * @see View
 * @see Compiler
 */
class ViewEngine
{
    private string $viewPath;

    private string $cachePath;

    public function __construct(string $viewPath, ?string $cachePath = null)
    {
        $this->viewPath = rtrim($viewPath, '/');
        $this->cachePath = $cachePath ?? dirname($this->viewPath).'/storage/views';
    }

    /**
     * Render a template to a string.
     *
     * @param  string  $path  Template path relative to viewPath (e.g. 'users/index')
     * @param  array<string, mixed>  $data  Variables available in the template
     *
     * @throws RuntimeException If the template is missing
     */
    public function render(string $path, array $data = []): string
    {
        $source = $this->resolve($path);

        $compiled = $this->compiledPath($source);
        if ($this->isExpired($source, $compiled)) {
            (new Compiler)->compile($source, $compiled);

            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($compiled, true);
            }
        }

        return $this->evaluate($compiled, $data);
    }

    // ─── Runtime state (stack-based to support nesting) ─────────

    /** @var array<int, array{extendingChild: bool, extendLayout: ?string, parentData: array}> */
    private array $evalStack = [];

    private bool $extendingChild = false;

    private ?string $extendLayout = null;

    /** @var array<string, mixed> */
    private array $parentData = [];

    /** @var array<string, string> */
    private array $sections = [];

    private ?string $activeSection = null;

    // ─── Runtime helpers (called from compiled PHP) ────────────

    /**
     * Mark this template as extending a layout.
     *
     * Called from the footer of a compiled child template. evaluate() reads
     * this flag once the child body (free content + @section blocks) has
     * been captured, and renders the layout with that content.
     */
    public function beginExtend(string $layout): void
    {
        $this->extendLayout = $layout;
        $this->extendingChild = true;
    }

    /**
     * Start capturing a section.
     */
    public function section(string $name): void
    {
        $this->activeSection = $name;
        ob_start();
    }

    /**
     * End the active section and store its content.
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
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Placeholder for @parent (renders the parent layout's slot content).
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
     * @param  array<string, mixed>  $data
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
     * Resolve a template path to an absolute, safe file path.
     *
     * @throws RuntimeException
     */
    private function resolve(string $path): string
    {
        $base = realpath($this->viewPath);
        if ($base === false) {
            throw new RuntimeException("View directory not found: {$this->viewPath}");
        }

        $file = realpath($base.'/'.ltrim($path, '/').'.php');
        if ($file === false
            || strncmp($file, $base.DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0
            || ! is_file($file)
        ) {
            throw new RuntimeException("View not found: {$path}");
        }

        return $file;
    }

    /**
     * Compute the compiled cache file path.
     *
     * Includes a compiler version component so that upgrading the framework
     * automatically invalidates all cached views.
     */
    private function compiledPath(string $source): string
    {
        return $this->cachePath.'/'.hash('xxh128', $source.'|v'.Compiler::VERSION).'.php';
    }

    /**
     * True if the compiled file is missing or older than the source.
     */
    private function isExpired(string $source, string $compiled): bool
    {
        if (! is_file($compiled)) {
            return true;
        }

        return filemtime($source) > filemtime($compiled);
    }

    /**
     * Evaluate compiled PHP with extracted data.
     *
     * Uses a state stack so nested evaluate() calls (e.g. via @include from
     * inside a @section) do NOT clobber the parent's extending/layout state.
     *
     * @param  array<string, mixed>  $data
     */
    private function evaluate(string $compiled, array $data): string
    {
        $__engine = $this;

        // Push current state onto the stack before resetting for this eval
        $this->evalStack[] = [
            'extendingChild' => $this->extendingChild,
            'extendLayout'   => $this->extendLayout,
            'parentData'     => $this->parentData,
        ];

        $this->parentData = $data;
        $this->extendingChild = false;
        $this->extendLayout = null;
        extract($data, EXTR_SKIP);

        ob_start();
        include $compiled;
        $output = ob_get_clean() ?: '';

        if ($this->extendingChild && $this->extendLayout !== null) {
            $content = $output;
            $layout = $this->extendLayout;

            // This IS the child that triggered @extends — render the layout.
            // Do NOT pop the stack (the layout rendering continues within
            // this call).
            $this->extendLayout = null;
            $this->extendingChild = false;

            // Push a dummy slot so the ancestor is restored when the
            // layout evaluate() pops.
            $this->evalStack[] = [
                'extendingChild' => false,
                'extendLayout'   => null,
                'parentData'     => $data,
            ];

            $result = $this->render(
                $layout,
                array_merge($data, ['content' => $content])
            );

            // Pop the dummy + the ancestor state
            array_pop($this->evalStack);
            $prev = array_pop($this->evalStack);
            $this->extendingChild = $prev['extendingChild'];
            $this->extendLayout = $prev['extendLayout'];
            $this->parentData = $prev['parentData'];

            return $result;
        }

        // Pop — restore the parent template's state
        $prev = array_pop($this->evalStack);
        $this->extendingChild = $prev['extendingChild'];
        $this->extendLayout = $prev['extendLayout'];
        $this->parentData = $prev['parentData'];

        return $output;
    }
}
