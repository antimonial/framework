<?php

declare(strict_types=1);

namespace Antimonial\View;

use RuntimeException;

/**
 * Template-to-PHP compiler (Blade-style, regex-driven).
 *
 * One pass over the source: comments -> statements (@directive) ->
 * echos ({{ }} escaped, {{{ }}} raw). No lexer states, no node tree.
 *
 * Output is cached PHP that ViewEngine includes. Auto-escaping is the
 * default; only {{{ }}} and @php emit raw content (developer-trusted).
 *
 * @see ViewEngine
 */
class Compiler
{
    /**
     * Compile a template file to a PHP file.
     *
     * @param string $source Absolute path to the .php template
     * @param string $target Absolute path for the compiled PHP
     * @return void
     * @throws RuntimeException If the source cannot be read
     */
    public function compile(string $source, string $target): void
    {
        $content = file_get_contents($source);
        if ($content === false) {
            throw new RuntimeException("Cannot read view: {$source}");
        }

        $php = $this->compileString($content);

        $dir = dirname($target);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($target, $php);
    }

    /**
     * Compile a template string into PHP.
     *
     * @param string $value
     * @return string
     */
    public function compileString(string $value): string
    {
        // 1. Strip comments
        $value = preg_replace('/{{--.*?--}}/s', '', $value) ?? $value;

        // 2. Statements (@if, @foreach, @extends, ...)
        $value = $this->compileStatements($value);

        // 3. Echos (raw first, then escaped)
        $value = $this->compileEchos($value);

        return $value;
    }

    // ─── Statements ────────────────────────────────────────────

    /**
     * Resolve @directive(...) blocks.
     *
     * The expression inside (...) is captured as a whole (balanced enough
     * for typical templates), so the emitted PHP is valid. Paired blocks
     * (@if ... @else ... @endif) are matched up to their closer.
     *
     * @param string $value
     * @return string
     */
    private function compileStatements(string $value): string
    {
        // Move @extends to the footer (like Blade), returning '' inline.
        // The child body (free content + @section blocks) is evaluated first;
        // evaluate() then renders the layout with the captured content.
        $footer = '';

        $value = preg_replace_callback(
            '/^[ \t]*@extends' . $this->exprPattern() . '/',
            function ($m) use (&$footer) {
                $footer .= "<?php \$__engine->beginExtend({$m[1]}); ?>\n";
                return '';
            },
            $value
        ) ?? $value;

        // Paired blocks: @name(expr) ... @endname. The body is re-compiled
        // (recursion) so nested blocks resolve. Following Blade's approach,
        // @else / @elseif / @endif are handled as ATOMIC directives below
        // (not post-processed inside the body), which is robust regardless
        // of where they appear on the line.
        $pairPattern = '/@(if|unless|foreach|for|while|switch|isset|empty|section)\b'
            . $this->exprPattern() . '([\s\S]*?)@end\1/s';

        // Standalone (atomic) directives handled in the same iteration:
        // @elseif(expr), @else, @endif, @endunless, @endisset, @endempty.
        // @elseif carries its own (expr) and gets a dedicated pattern to
        // avoid PCRE group-numbering conflicts when exprPattern recurses.
        $elseifPattern = '/@elseif' . $this->exprPattern() . '/';
        $atomicPattern = '/@(else|endif|endunless|endisset|endempty)\b/';

        $inlinePattern = '/@(include|yield|parent|set)\b' . $this->exprPattern() . '/';

        // @php ... @endphp: raw PHP block (no parentheses around the body).
        do {
            $prevPhp = $value;
            $value = preg_replace_callback(
                '/@php\b([\s\S]*?)@endphp/s',
                fn ($m) => "<?php " . trim($m[1]) . " ?>",
                $value
            ) ?? $value;
        } while ($value !== $prevPhp);

        // Iterate until stable. preg_replace is not nested, so the innermost
        // blocks resolve first; repeated passes unwrap parents (@if/@foreach).
        // Atomic directives (@else etc.) are replaced alongside paired ones.
        do {
            $prev = $value;

            $value = preg_replace_callback(
                $pairPattern,
                function ($m) {
                    $inner = $this->compileStatements($m[3]);
                    return $this->compileDirective($m[1], $m[2], $inner, true);
                },
                $value
            ) ?? $value;

            $value = preg_replace_callback(
                $elseifPattern,
                function ($m) {
                    return $this->compileAtomic('elseif' . $m[1]);
                },
                $value
            ) ?? $value;

            $value = preg_replace_callback(
                $atomicPattern,
                function ($m) {
                    return $this->compileAtomic($m[1]);
                },
                $value
            ) ?? $value;

            $value = preg_replace_callback(
                $inlinePattern,
                function ($m) {
                    return $this->compileDirective($m[1], $m[2], '', false);
                },
                $value
            ) ?? $value;
        } while ($value !== $prev);

        return $footer . $value;
    }

    /**
     * Regex fragment matching a balanced-parenthesis expression: ( ... ).
     *
     * Uses a relative recursion (?-1) so the subpattern references itself
     * (the nearest preceding capturing group) rather than group 1 of the
     * full pattern (which is the directive name). This lets
     * "if ($a > count($b))" capture intact.
     *
     * @return string
     */
    private function exprPattern(): string
    {
        return '(\((?:[^()]+|(?-1))*\))';
    }

    /**
     * Compile an atomic (standalone) directive: @else, @elseif(expr),
     * @endif, @endunless, @endisset, @endempty.
     *
     * These are replaced in the iteration loop (Blade-style) rather than
     * post-processed inside a parent block, so their position on the line
     * does not matter.
     *
     * @param string $token The matched directive text (e.g. "elseif($x)")
     * @return string
     */
    private function compileAtomic(string $token): string
    {
        if (str_starts_with($token, 'elseif')) {
            preg_match('/^elseif' . $this->exprPattern() . '$/', $token, $m);
            return "<?php elseif{$m[1]}: ?>";
        }

        return match ($token) {
            'else'       => '<?php else: ?>',
            'endif'      => '<?php endif; ?>',
            'endunless'  => '<?php endif; ?>',
            'endisset'   => '<?php endif; ?>',
            'endempty'   => '<?php endif; ?>',
            default      => '',
        };
    }

    /**
     * Map a directive name + expression to PHP.
     *
     * @param string $name   Directive name (if, foreach, include, ...)
     * @param string $expr   The parenthesized expression, e.g. "($users as $u)"
     * @param string $inner  Body between open and @end (for paired directives)
     * @param bool   $paired Whether this directive has an @end<name> closer
     * @return string
     */
    private function compileDirective(string $name, string $expr, string $inner, bool $paired): string
    {
        $open = match ($name) {
            'if'      => "<?php if{$expr}: ?>",
            'unless'  => "<?php if (!( {$expr} )): ?>",
            'foreach' => "<?php foreach{$expr}: ?>",
            'for'     => "<?php for{$expr}: ?>",
            'while'   => "<?php while{$expr}: ?>",
            'switch'  => "<?php switch{$expr}: ?>",
            'isset'   => "<?php if (isset{$expr}): ?>",
            'empty'   => "<?php if (empty{$expr}): ?>",
            'section' => "<?php \$__engine->section{$expr}; ?>",
            'include' => "<?php echo \$__engine->include{$expr}; ?>",
            'yield'   => "<?php echo \$__engine->yield{$expr}; ?>",
            'parent'  => "<?php echo \$__engine->parent(); ?>",
            'set'     => $this->compileSet($expr),
            'php'     => "<?php " . trim($expr, '()') . " ?>",
            default   => '',
        };

        if (!$paired) {
            return $open;
        }

        $close = match ($name) {
            'section' => "<?php \$__engine->endSection(); ?>",
            'unless'  => '<?php endif; ?>',
            'isset'   => '<?php endif; ?>',
            'empty'   => '<?php endif; ?>',
            'php'     => '<?php endif; ?>',
            default   => '<?php end' . $name . '; ?>',
        };

        return $open . $inner . $close;
    }

    /**
     * @set($total = 0) -> <?php $total = 0; ?>
     *
     * @param string $body
     * @return string
     */
    private function compileSet(string $body): string
    {
        // Strip wrapping parentheses if present: @set($x = 1) or @set($x = 1)
        $body = trim($body);
        if (str_starts_with($body, '(') && str_ends_with($body, ')')) {
            $body = substr($body, 1, -1);
        }
        return "<?php {$body}; ?>";
    }

    // ─── Echos ─────────────────────────────────────────────────

    /**
     * Compile raw {{{ }}} then escaped {{ }} (with optional |filters).
     *
     * @param string $value
     * @return string
     */
    private function compileEchos(string $value): string
    {
        // Raw: {{{ $html }}}
        $value = preg_replace_callback(
            '/{{{\s*(.+?)\s*}}}/s',
            fn ($m) => "<?= {$m[1]} ?>",
            $value
        ) ?? $value;

        // Escaped: {{ $name|upper }}
        $value = preg_replace_callback(
            '/{{\s*(.+?)\s*}}/s',
            function ($m) {
                $expr = trim($m[1]);
                if (!str_contains($expr, '|')) {
                    return "<?= htmlspecialchars((string) ({$expr}), ENT_QUOTES, 'UTF-8') ?>";
                }
                [$var, $filters] = array_map('trim', explode('|', $expr, 2));
                return "<?= htmlspecialchars((string) \\Antimonial\\View\\Filters::apply({$var}, " . var_export($filters, true) . "), ENT_QUOTES, 'UTF-8') ?>";
            },
            $value
        ) ?? $value;

        return $value;
    }
}
