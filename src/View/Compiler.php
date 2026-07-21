<?php

declare(strict_types=1);

namespace Antimonial\View;

use RuntimeException;

/**
 * Template-to-PHP compiler (Blade-style, hybrid tokenizer + regex).
 *
 * Uses PHP's own token_get_all() lexer to separate raw HTML from
 * existing PHP code  — directives inside T_INLINE_HTML segments are
 * replaced with <?php ?> blocks, while pre-existing PHP passes through
 * unmodified.  This gives correct nesting of @if/@endif (PHP's parser
 * handles the nesting) and avoids the regex-pair-matching bugs that
 * a purely regex approach suffers from.
 *
 * @see ViewEngine
 */
class Compiler
{
    /**
     * Compiler version — bump this whenever the compilation logic changes
     * to force recompilation of all cached views.
     */
    public const VERSION = '0.9.4';

    /**
     * HTML-escaping expression template (sprintf with the value to escape).
     */
    private const ESC = "htmlspecialchars(%s, ENT_QUOTES, 'UTF-8')";

    /**
     * Compile a template file to a PHP file.
     *
     * @param  string  $source  Absolute path to the .php template
     * @param  string  $target  Absolute path for the compiled PHP
     *
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
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $tmp = tempnam($dir, 'cmp_');
        if ($tmp === false) {
            throw new RuntimeException("Cannot create temp file in {$dir}");
        }

        file_put_contents($tmp, $php);

        if (! rename($tmp, $target)) {
            @unlink($tmp);

            throw new RuntimeException("Cannot write compiled view: {$target}");
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($target, true);
        }
    }

    /**
     * Compile a template string into PHP.
     *
     * Two-phase approach:
     *   1. Strip @comments.
     *   2. Tokenize with token_get_all() — PHP tokens pass through,
     *      T_INLINE_HTML segments are compiled (directives → PHP).
     *
     * @param  string  $value  Raw template content
     * @return string Compiled PHP source
     */
    public function compileString(string $value): string
    {
        $value = preg_replace('/{{--.*?--}}/s', '', $value) ?? $value;

        $result = '';

        foreach (token_get_all($value) as $token) {
            if (is_array($token) && $token[0] === T_INLINE_HTML) {
                $result .= $this->compileHtml($token[1]);
            } else {
                $result .= is_array($token) ? $token[1] : $token;
            }
        }

        return $result;
    }

    // ─── HTML compilation (directives → PHP) ───────────────────

    /**
     * Replace Blade-style @directives inside a raw-HTML segment with
     * <?php ?> blocks.  Each directive is handled independently — no
     * paired-block matching is needed because PHP's own parser will
     * resolve nesting (if/endif, foreach/endforeach, etc.) at runtime.
     *
     * @param  string  $html  Raw HTML segment from the template
     * @return string PHP code with directives replaced
     */
    private function compileHtml(string $html): string
    {
        $footer = '';

        // 1. @extends — move to footer (like Blade)
        $html = preg_replace_callback(
            '/^[ \t]*@extends'.$this->exprPattern().'/m',
            function ($m) use (&$footer) {
                /** @var array<int, string> $m */
                $footer .= "<?php \$__engine->beginExtend({$m[1]}); ?>\n";

                return '';
            },
            $html
        ) ?? $html;

        // 2. @php … @endphp — raw PHP blocks
        do {
            $prev = $html;
            $html = preg_replace_callback(
                '/@php\b([\s\S]*?)@endphp/s',
                function ($m) {
                    /** @var array<int, string> $m */
                    return '<?php '.trim($m[1]).' ?>';
                },
                $html
            ) ?? $html;
        } while ($html !== $prev);

        // 3. Standalone atomic directives that open or close blocks.
        //    Each is compiled independently — PHP resolves nesting.
        //
        // Order matters: longer matches first (e.g. @elseif before @else).
        $atomic = [
            // Openers (paired — emit opening PHP + record on stack for @end)
            '/@(if|unless|foreach|for|while|switch|isset|empty|section)\b'.$this->exprPattern().'/' => function (array $m): string {
                /** @var array<int, string> $m */
                return $this->openBlock($m[1] ?? '', $m[2] ?? '');
            },
            '/@elseif'.$this->exprPattern().'/' => function (array $m): string {
                /** @var array<int, string> $m */
                $cond = $m[1] ?? '';

                return "<?php elseif{$cond}: ?>";
            },
            '/@else\b(?!if)/' => fn () => '<?php else: ?>',
            // Switch case/default — MUST be before closers so @case/@default
            // run before @endswitch pops the switchStack.
            '/@case'.$this->exprPattern().'/' => function (array $m): string {
                /** @var array<int, string> $m */
                $val = substr($m[1] ?? '', 1, -1);
                if (! empty($this->switchStack)) {
                    $idx = array_key_last($this->switchStack);
                    $sw = $this->switchStack[$idx];
                    $keyword = $sw['firstCase'] ? 'if' : 'elseif';
                    $this->switchStack[$idx]['firstCase'] = false;

                    return "<?php {$keyword} ({$sw['expr']} === {$val}): ?>\n";
                }

                return "<?php case {$val}: ?>\n";
            },
            '/@default\b/' => fn () => ! empty($this->switchStack) ? "<?php else: ?>\n" : "<?php default: ?>\n",
            '/@break\b/' => fn () => "<?php break; ?>\n",
            // Atomic closers — explicit named closer first, then @end as universal.
            // These run AFTER @case/@default so the switchStack is still populated.
            '/@(endif|endunless|endisset|endempty|endforeach|endfor|endwhile|endswitch|endsection)\b/' => function (array $m): string {
                /** @var array<int, string> $m */
                return $this->closeBlock($m[1] ?? '');
            },
            // Universal closer: @end closes whatever block was most recently opened
            '/@end\b(?!if|unless|isset|empty|foreach|for|while|switch|section)/' => function () {
                $type = array_pop($this->blockStack);
                if ($type === null) {
                    return '';
                }
                $php = match ($type) {
                    'if', 'unless', 'isset', 'empty' => '<?php endif; ?>',
                    'switch' => $this->closeSwitch(),
                    'section' => '<?php $__engine->endSection(); ?>',
                    default => "<?php end{$type}; ?>",
                };

                return $php;
            },
            // Inline helpers
            '/@include'.$this->exprPattern().'/' => function (array $m): string {
                /** @var array<int, string> $m */
                $args = $m[1] ?? '';
                $inner = substr($args, 1, -1);

                if (! str_contains($inner, ',')) {
                    return "<?php echo \$__engine->include({$inner}, get_defined_vars()); ?>";
                }

                return "<?php echo \$__engine->include{$args}; ?>";
            },
            '/@yield'.$this->exprPattern().'/' => function (array $m): string {
                /** @var array<int, string> $m */
                $arg = $m[1] ?? '';

                return "<?php echo \$__engine->yield{$arg}; ?>";
            },
            '/@parent\b'.$this->exprPattern().'/' => fn (): string => '<?php echo $__engine->parent(); ?>',
            '/@set'.$this->exprPattern().'/' => function (array $m): string {
                /** @var array<int, string> $m */
                return $this->compileSet($m[1] ?? '');
            },
            '/@csrf\b/' => fn () => '<?php echo \\Antimonial\\Security\\Csrf::field(); ?>',
        ];

        do {
            $prev = $html;

            foreach ($atomic as $pattern => $callback) {
                $html = preg_replace_callback($pattern, $callback, $html) ?? $html;
            }
        } while ($html !== $prev);

        // 4. Echos (raw first, then escaped)
        $html = $this->compileEchos($html);

        return $html.$footer;
    }

    // ─── Block open / close helpers ────────────────────────────

    /**
     * @var list<string> Stack of open block types — used only by @end
     *                   to resolve the nearest open block.
     */
    private array $blockStack = [];

    /**
     * Emit PHP for a block-opening directive and push its type.
     *
     * @param  string  $type  Block type (if, foreach, etc.)
     * @param  string  $expr  The parenthesised expression, with @end stripped
     * @return string Compiled PHP opening block
     */
    private function openBlock(string $type, string $expr): string
    {
        // Resolve |filter chains inside the expression
        $compiledExpr = $this->compileExpr($expr);

        $php = match ($type) {
            'if' => "<?php if{$compiledExpr}: ?>",
            'unless' => "<?php if (!( {$compiledExpr} )): ?>",
            'foreach' => "<?php foreach{$compiledExpr}: ?>",
            'for' => "<?php for{$compiledExpr}: ?>",
            'while' => "<?php while{$compiledExpr}: ?>",
            'switch' => $this->openSwitch($compiledExpr),
            'isset' => "<?php if (isset{$compiledExpr}): ?>",
            'empty' => "<?php if (empty{$compiledExpr}): ?>",
            'section' => "<?php \$__engine->section{$expr}; ?>",
            default => '',
        };

        $this->blockStack[] = $type;

        return $php;
    }

    /**
     * Emit PHP for a standard @end<type> closer and pop the stack.
     *
     * @param  string  $type  The full closer name (e.g. "endif", "endforeach")
     * @return string Compiled PHP closing block
     */
    private function closeBlock(string $type): string
    {
        // Strip leading "end" → e.g. "endif" → "if"
        $openType = substr($type, 3);

        array_pop($this->blockStack);

        return match ($openType) {
            'if', 'unless', 'isset', 'empty' => '<?php endif; ?>',
            'switch' => $this->closeSwitch(),
            'section' => '<?php $__engine->endSection(); ?>',
            default => "<?php end{$openType}; ?>",
        };
    }

    // ─── Switch helpers ────────────────────────────────────────

    /**
     * @var list<array{expr: string, firstCase: bool}> Stack of switch
     *                                                 state for rewriting @case/@default to if/elseif/else
     */
    private array $switchStack = [];

    /**
     * Open a @switch block — we rewrite @case/@default to @if/elseif
     * because PHP does not allow T_INLINE_HTML inside a switch/case
     * alternative-syntax block.
     *
     * @param  string  $expr  The switch expression
     * @return string Always empty (switch content is rewritten inline)
     */
    private function openSwitch(string $expr): string
    {
        $this->switchStack[] = [
            'expr' => trim($expr, '()'),
            'firstCase' => true,
        ];

        return '';
    }

    /**
     * Close the current @switch block (rewritten).
     *
     * @return string "<?php endif; ?>" (the rewritten closer)
     */
    private function closeSwitch(): string
    {
        array_pop($this->switchStack);

        return '<?php endif; ?>';
    }

    /**
     * Compile a @set directive body to PHP.
     *
     * @param  string  $body  The assignment expression including parentheses
     * @return string PHP assignment statement
     */
    private function compileSet(string $body): string
    {
        $body = trim($body);
        if (str_starts_with($body, '(') && str_ends_with($body, ')')) {
            $body = substr($body, 1, -1);
        }

        return "<?php {$body}; ?>";
    }

    // ─── Expression compilation (filter pipes) ─────────────────

    /**
     * Resolve |filter chains inside a parenthesised expression.
     *
     * Transforms  ($x|length > 0)  →  (Filters::apply($x, 'length') > 0)
     * so that filters work in @if, @for, etc.
     *
     * @param  string  $expr  The parenthesised expression
     * @return string Expression with filter pipes replaced
     */
    private function compileExpr(string $expr): string
    {
        return preg_replace_callback(
            '/(\$[a-zA-Z_]\w*)((?:\|\w+(?::(?:[^()|]+|(?-1))*)?)+)/',
            function ($m) {
                /** @var array<int, string> $m */
                $var = $m[1];
                $filters = [];
                preg_match_all('/\|(\w+)(?::((?:[^()|]+|(?-1))*))?/', $m[2], $matches, PREG_SET_ORDER);
                foreach ($matches as $fm) {
                    $name = $fm[1];
                    $args = $fm[2] ?? null;
                    if ($args !== null) {
                        $filters[] = $name.':'.$args;
                    } else {
                        $filters[] = $name;
                    }
                }

                return "\\Antimonial\\View\\Filters::apply({$var}, ".var_export($filters, true).')';
            },
            $expr
        ) ?? $expr;
    }

    // ─── Switch rewriting in compileHtml ───────────────────────

    /**
     * Regex pattern for matching a parenthesised expression.
     *
     * @return string A recursive regex pattern
     */
    private function exprPattern(): string
    {
        return '(\((?:[^()]+|(?-1))*\))';
    }

    // ─── Echos ─────────────────────────────────────────────────

    /**
     * Compile raw {{{ }}} then escaped {{ }} (with optional |filters).
     *
     * @param  string  $value  HTML with echo delimiters
     * @return string Compiled PHP echo statements
     */
    private function compileEchos(string $value): string
    {
        // Raw: {{{ $html }}}
        $value = preg_replace_callback(
            '/{{{\s*(.+?)\s*}}}/s',
            function ($m) {
                /** @var array<int, string> $m */
                return "<?= {$m[1]} ?>";
            },
            $value
        ) ?? $value;

        // Escaped: {{ $name|upper }}
        $value = preg_replace_callback(
            '/{{\s*(.+?)\s*}}/s',
            function ($m) {
                /** @var array<int, string> $m */
                $expr = trim($m[1]);
                if (! str_contains($expr, '|')) {
                    return '<?= '.sprintf(self::ESC, "(string) ({$expr})").' ?>';
                }
                [$var, $filters] = array_map('trim', explode('|', $expr, 2));

                return '<?= '.sprintf(self::ESC, "(string) \\Antimonial\\View\\Filters::apply({$var}, ".var_export($filters, true).')').' ?>';
            },
            $value
        ) ?? $value;

        return $value;
    }
}
