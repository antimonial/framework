<?php

namespace Antimonial\Core;

/**
 * Minimal .env loader (dependency-free).
 *
 * Reads a .env file and exposes its variables to the process environment
 * via putenv() and $_ENV/$_SERVER, so Helpers\env() can resolve them.
 *
 * This is intentionally tiny — not a full dotenv implementation. Unknown
 * lines, comments and blank lines are skipped; values may be optionally
 * quoted. Existing process environment variables are never overridden.
 */
final class DotEnv
{
    /**
     * Load environment variables from a .env file.
     *
     * No-op if the file does not exist, so it is safe to call unconditionally
     * in the front controller.
     */
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            $len = strlen($value);
            if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }
}
