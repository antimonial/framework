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

    /**
     * @param  string  $viewPath  Base view directory
     * @param  string|null  $cachePath  Compiled template cache directory (defaults to storage/views)
     */
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

    /** @var array<int, array{extendingChild: bool, extendLayout: ?string, parentData: array<string, mixed>}> */
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
     *
     * @param  string  $layout  Layout template path
     */
    public function beginExtend(string $layout): void
    {
        $this->extendLayout = $layout;
        $this->extendingChild = true;
    }

    /**
     * Start capturing a section.
     *
     * @param  string  $name  Section name (matches @yield in the layout)
     */
    public function section(string $name, string $value = null): void
    {
        if ($value !== null) {
            $this->sections[$name] = $value;

            return;
        }

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
     *
     * @param  string  $name  Section name
     * @param  string  $default  Fallback text if the section was never set
     * @return string The section content or the default
     */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /**
     * Placeholder for @parent (renders the parent layout's slot content).
     *
     * Currently a no-op; the slot content is always replaced by children.
     *
     * @return string Empty string (for future use)
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
     * @param  string  $path  Template path relative to the view directory
     * @param  array<string, mixed>  $data  Explicit variables for the included template
     * @return string Rendered output of the included template
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
     * Pop the most recent eval state from the stack.
     *
     * @return array{extendingChild: bool, extendLayout: ?string, parentData: array<string, mixed>}|null
     */
    private function popEvalState(): ?array
    {
        return array_pop($this->evalStack);
    }

    /**
     * Resolve a template path to an absolute, safe file path.
     *
     * Guards against directory traversal by verifying the resolved path
     * starts with the view directory.
     *
     * @param  string  $path  Template path relative to the view directory
     *
     * @throws RuntimeException If the view directory or template does not exist
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
     *
     * @param  string  $source  Absolute path to the source template
     * @return string Absolute path to the compiled cache file
     */
    private function compiledPath(string $source): string
    {
        return $this->cachePath.'/'.hash('xxh128', $source.'|v'.Compiler::VERSION).'.php';
    }

    /**
     * True if the compiled file is missing or older than the source.
     *
     * @param  string  $source  Absolute path to the source template
     * @param  string  $compiled  Absolute path to the compiled cache file
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
     * @param  string  $compiled  Absolute path to the compiled PHP file
     * @param  array<string, mixed>  $data  Template variables
     * @return string Rendered output
     */
    private function evaluate(string $compiled, array $data): string
    {
        $__engine = $this;

        // Push current state onto the stack before resetting for this eval
        $this->evalStack[] = [
            'extendingChild' => $this->extendingChild,
            'extendLayout' => $this->extendLayout,
            'parentData' => $this->parentData,
        ];

        $this->parentData = $data;
        extract($data, EXTR_SKIP);

        ob_start();
        include $compiled;
        $output = ob_get_clean() ?: '';

        // Capture post-render state: a child template's @extends call (made
        // during include above) sets these on the engine instance.
        $isExtending = $this->extendingChild;
        $layoutName = $this->extendLayout;

        // Reset for this eval frame.
        $this->extendingChild = false;
        $this->extendLayout = null;

        if ($isExtending && $layoutName !== null) {
            $content = $output;

            // Push a dummy slot so the ancestor is restored when the
            // layout evaluate() pops.
            $this->evalStack[] = [
                'extendingChild' => false,
                'extendLayout' => null,
                'parentData' => $data,
            ];

            $result = $this->render(
                $layoutName,
                array_merge($data, ['content' => $content])
            );

            // Pop the dummy + the ancestor state
            array_pop($this->evalStack);
            $prev = $this->popEvalState();
            if ($prev === null) {
                return $result;
            }

            $this->extendingChild = $prev['extendingChild'];
            $this->extendLayout = $prev['extendLayout'];
            $this->parentData = $prev['parentData'];

            return $result;
        }

        // Pop — restore the parent template's state
        $prev = $this->popEvalState();
        if ($prev === null) {
            return $output;
        }

        $this->extendingChild = $prev['extendingChild'];
        $this->extendLayout = $prev['extendLayout'];
        $this->parentData = $prev['parentData'];

        return $output;
    }
}
