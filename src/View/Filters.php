<?php

declare(strict_types=1);

namespace Antimonial\View;

/**
 * Template filter registry.
 *
 * Maps pipe-filter names ({{ $name|upper }}) to plain callables.
 * This is the Twig-style "filters" idea, kept tiny: a single map.
 *
 * Add your own with Filters::add('slug', fn ($v) => ...).
 */
final class Filters
{
    /**
     * @var array<string, callable>
     */
    private static array $map = [
        'escape' => [self::class, 'escape'],
        'e' => [self::class, 'escape'],
        'raw' => [self::class, 'raw'],
        'upper' => 'strtoupper',
        'lower' => 'strtolower',
        'trim' => 'trim',
        'length' => [self::class, 'length'],
        'json' => [self::class, 'toJson'],
        'date' => [self::class, 'date'],
    ];

    /**
     * Register a filter callable.
     *
     * @param  string  $name  Filter name used in templates (e.g. "slug")
     * @param  callable  $fn  The filter implementation
     */
    public static function add(string $name, callable $fn): void
    {
        self::$map[$name] = $fn;
    }

    /**
     * Apply a filter chain ("a|b:c") to a value.
     *
     * @param  mixed  $value  The input value to filter
     * @param  string  $chain  Pipe-separated filter names, optionally ":arg"
     * @return mixed Filtered value
     */
    public static function apply(mixed $value, string $chain): mixed
    {
        /** @var list<string> $parts */
        $parts = explode('|', $chain);
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            [$name, $arg] = array_pad(explode(':', $part, 2), 2, null);
            $name = strtolower(trim((string) $name));

            if (! isset(self::$map[$name])) {
                continue;
            }

            $value = $arg === null
                ? call_user_func(self::$map[$name], $value)
                : call_user_func(self::$map[$name], $value, trim((string) $arg));
        }

        return $value;
    }

    // ─── Built-in filters ──────────────────────────────────────

    /**
     * Escape a value for safe HTML output (XSS-safe).
     *
     * Uses htmlspecialchars with ENT_QUOTES and UTF-8 encoding.
     */
    public static function escape(mixed $value): string
    {
        $str = match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value) => (string) $value,
            $value === null => '',
            default => '',
        };

        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Coalesce a value to a raw (unescaped) string.
     */
    public static function raw(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value) => (string) $value,
            $value === null => '',
            default => '',
        };
    }

    /**
     * Get the length of a string or countable value.
     *
     * Strings return their byte length; countable values return their count.
     * All other values return 0.
     */
    public static function length(mixed $value): int
    {
        if (is_string($value)) {
            return strlen($value);
        }
        if (is_countable($value)) {
            return count($value);
        }

        return 0;
    }

    /**
     * Encode a value as pretty-printed JSON.
     */
    public static function toJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '';
    }

    /**
     * Format a date value.
     *
     * Accepts a DateTimeInterface object, a Unix timestamp, or a date string
     * parsable by strtotime. Returns the formatted string or an empty string
     * on failure.
     *
     * @param  string  $format  PHP date format (default "Y-m-d")
     */
    public static function date(mixed $value, string $format = 'Y-m-d'): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }
        if (is_numeric($value)) {
            return date($format, (int) $value);
        }
        if (is_string($value)) {
            $ts = strtotime($value);

            return $ts === false ? '' : date($format, $ts);
        }

        return '';
    }
}
