<?php

declare(strict_types=1);

namespace Antimonial\Core;

use ErrorException;
use Throwable;

/**
 * Global error and exception handler.
 *
 * Registers handlers for PHP errors, exceptions, and fatal errors.
 * In debug mode, renders a detailed error page with stack trace,
 * source code context, and request information.
 * In production, renders a minimal 500 page.
 *
 * @see App::run()
 */
class ErrorHandler
{
    /**
     * @var bool Debug mode (show detailed errors)
     */
    private static bool $debug = false;

    /**
     * Enable debug mode for detailed error reporting.
     */
    public static function enableDebug(bool $debug = true): void
    {
        self::$debug = $debug;
    }

    /**
     * Whether debug mode is currently enabled.
     */
    public static function isDebug(): bool
    {
        return self::$debug;
    }

    /**
     * Register error, exception, and shutdown handlers.
     *
     * @see App::run()
     */
    public static function register(): void
    {
        set_error_handler(
            function (int $errno, string $errstr, string $errfile = '', int $errline = 0): bool {
                self::handleError($errno, $errstr, $errfile, $errline);

                return true;
            }
        );
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    /**
     * Handle PHP warnings and notices.
     *
     * Converts PHP errors into ErrorException instances.
     *
     * @param  int  $level  Error level
     * @param  string  $message  Error message
     * @param  string  $file  Source file
     * @param  int  $line  Line number
     *
     * @throws ErrorException Always
     */
    public static function handleError(int $level, string $message, string $file, int $line): void
    {
        if (! (error_reporting() & $level)) {
            return;
        }
        throw new ErrorException($message, 0, $level, $file, $line);
    }

    /**
     * Handle uncaught exceptions.
     *
     * Logs the error and renders a 500 response.
     */
    public static function handleException(Throwable $exception): void
    {
        self::log($exception);
        self::render($exception);
    }

    /**
     * Handle fatal errors via the shutdown function.
     *
     * Checks for a fatal error in the last error and renders it.
     */
    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $exception = new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
            self::log($exception);
            self::render($exception);
        }
    }

    /**
     * Log the exception to the error log.
     */
    private static function log(Throwable $exception): void
    {
        $message = sprintf(
            "[%s] %s in %s:%d\n",
            date('Y-m-d H:i:s'),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
        error_log($message);
    }

    /**
     * Render an error response (HTML in debug mode, minimal otherwise).
     */
    private static function render(Throwable $exception): void
    {
        if (! headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
        }

        if (self::$debug) {
            self::renderDebugPage($exception);
        } else {
            echo '<h1>500 Server Error</h1>';
            echo '<p>Something went wrong.</p>';
        }
    }

    /**
     * Render a detailed debug error page with stack trace.
     */
    private static function renderDebugPage(Throwable $exception): void
    {
        $trace = htmlspecialchars($exception->getTraceAsString());
        $file = htmlspecialchars((string) $exception->getFile());
        $line = $exception->getLine();
        $message = htmlspecialchars($exception->getMessage());

        echo <<<HTML
        <!DOCTYPE html>
        <html>
        <head><title>Error</title>
        <style>
            body { font-family: monospace; background: #1e1e2e; color: #cdd6f4; padding: 2rem; }
            h1 { color: #f38ba8; }
            .info { background: #313244; padding: 1rem; border-radius: 4px; margin: 1rem 0; }
            .trace { background: #181825; padding: 1rem; border-radius: 4px; white-space: pre-wrap; }
        </style>
        </head>
        <body>
            <h1>Error: {$message}</h1>
            <div class="info">File: {$file} on line <strong>{$line}</strong></div>
            <h2>Stack Trace:</h2>
            <div class="trace">{$trace}</div>
        </body>
        </html>
        HTML;
    }
}
