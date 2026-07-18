<?php

declare(strict_types=1);

namespace Antimonial\Core;

/**
 * Minimal file logger.
 *
 * Appends timestamped, single-line entries to a per-day log file inside a
 * caller-supplied directory. There is no log-level filtering, no rotation,
 * and no external dependency — just `file_put_contents` with a lock.
 *
 * The directory is provided by the caller (e.g. from config) so the logger
 * never hardcodes an application path.
 *
 * @see ErrorHandler  uses Logger to persist uncaught exceptions.
 */
final class Logger
{
    /**
     * Severity levels, in ascending order of seriousness.
     *
     * @var array<int, string>
     */
    private const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Write a log entry to a daily file in the given directory.
     *
     * The file is named `YYYY-MM-DD.log` and each line is formatted as:
     *   [YYYY-MM-DD HH:MM:SS] LEVEL: message
     *
     * @param  string  $level  One of the RFC 5424 levels (e.g. 'error')
     * @param  string  $message  The message to log
     * @param  string  $directory  Directory where log files are written
     * @return bool True if the entry was written, false on failure
     *
     * @throws \RuntimeException If the level is not recognized
     */
    public static function write(string $level, string $message, string $directory): bool
    {
        $normalized = strtolower($level);
        if (! in_array($normalized, self::LEVELS, true)) {
            throw new \RuntimeException(
                sprintf('Unknown log level "%s". Expected one of: %s.', $level, implode(', ', self::LEVELS))
            );
        }

        $dir = rtrim($directory, '/\\');
        if (! is_dir($dir)) {
            if (! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                return false;
            }
        }

        $file = $dir.'/'.date('Y-m-d').'.log';
        $line = sprintf(
            "[%s] %s: %s\n",
            date('Y-m-d H:i:s'),
            strtoupper($normalized),
            $message
        );

        return file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false;
    }
}
