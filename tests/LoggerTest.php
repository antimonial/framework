<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Core\ErrorHandler;
use Antimonial\Core\Logger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the file Logger and ErrorHandler's file-logging integration.
 */
final class LoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ant_log_'.uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $item) {
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        @rmdir($dir);
    }

    public function test_write_creates_daily_file_with_entry(): void
    {
        $ok = Logger::write('error', 'something broke', $this->dir);

        self::assertTrue($ok);
        $file = $this->dir.'/'.date('Y-m-d').'.log';
        self::assertFileExists($file);

        $contents = (string) file_get_contents($file);
        self::assertStringContainsString('[', $contents);
        self::assertStringContainsString('] ERROR: something broke', $contents);
    }

    public function test_write_creates_missing_directory(): void
    {
        $nested = $this->dir.'/nested/deeper';

        $ok = Logger::write('info', 'hi', $nested);

        self::assertTrue($ok);
        self::assertDirectoryExists($nested);
        self::assertFileExists($nested.'/'.date('Y-m-d').'.log');
    }

    public function test_write_lowercases_level(): void
    {
        Logger::write('WARNING', 'careful', $this->dir);
        $contents = (string) file_get_contents($this->dir.'/'.date('Y-m-d').'.log');

        self::assertStringContainsString('WARNING: careful', $contents);
    }

    public function test_write_rejects_unknown_level(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown log level');

        Logger::write('bogus', 'msg', $this->dir);
    }

    public function test_error_handler_logs_to_file(): void
    {
        $logDir = $this->dir.'/errors';
        ErrorHandler::setLogDirectory($logDir);

        set_exception_handler(null);
        try {
            ErrorHandler::handleException(new RuntimeException('boom'));
        } catch (Throwable) {
            // handleException renders output; ignore it.
        }

        $file = $logDir.'/'.date('Y-m-d').'.log';
        self::assertFileExists($file);
        self::assertStringContainsString('boom', (string) file_get_contents($file));

        ErrorHandler::setLogDirectory(null);
    }
}
