<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for real HTTP file uploads.
 *
 * is_uploaded_file() and move_uploaded_file() only behave correctly for
 * genuine uploads, so we spin up PHP's built-in server and drive it with
 * curl. The harness lives in tests/_stubs/upload_server.php.
 */
final class UploadedFileServerTest extends TestCase
{
    private static string $docRoot;
    private static string $host;
    private static ?string $pidFile = null;

    public static function setUpBeforeClass(): void
    {
        self::$docRoot = __DIR__.'/_stubs';
        $port = 8900 + (int) (getmypid() % 100);
        self::$host = "http://127.0.0.1:{$port}";

        self::$pidFile = tempnam(sys_get_temp_dir(), 'ant_srv_');
        $cmd = sprintf(
            'php -S 127.0.0.1:%d -t %s >/dev/null 2>&1 & echo $! > %s',
            $port,
            escapeshellarg(self::$docRoot),
            escapeshellarg(self::$pidFile)
        );
        exec($cmd);

        // Wait for the server to accept connections.
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);
                break;
            }
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pidFile !== null && file_exists(self::$pidFile)) {
            $pid = (int) trim((string) file_get_contents(self::$pidFile));
            if ($pid > 0) {
                exec(sprintf('kill %d 2>/dev/null', $pid));
            }
            @unlink(self::$pidFile);
        }
    }

    private function curlPost(string $url, array $fields, string $fileField, string $filePath, string $fileName): array
    {
        $ch = curl_init($url);
        $post = $fields;
        $post[$fileField] = new \CURLFile($filePath, 'text/plain', $fileName);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $body = curl_exec($ch);
        curl_close($ch);

        $this->assertIsString($body, 'Server returned no body');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function test_store_moves_uploaded_file(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'ant_src_');
        file_put_contents($src, 'uploaded contents');

        $out = $this->curlPost(
            self::$host.'/upload_server.php?action=store',
            [],
            'upload',
            $src,
            'orig.txt'
        );

        $this->assertSame('uploaded contents', $out['stored'] ?? null);
        $this->assertFileExists($out['path'] ?? '/nonexistent');
        @unlink($src);
        @unlink($out['path'] ?? '');
    }

    public function test_validate_accepts_valid_image_and_txt(): void
    {
        $img = tempnam(sys_get_temp_dir(), 'ant_img_');
        // Minimal valid PNG (1x1) so mime_content_type reports image/png.
        file_put_contents($img, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));
        $doc = tempnam(sys_get_temp_dir(), 'ant_doc_');
        file_put_contents($doc, 'plain text document');

        $out = $this->curlPost(
            self::$host.'/upload_server.php?action=validate',
            [],
            'avatar',
            $img,
            'avatar.png'
        );
        // Re-upload with the doc field instead (server only reads one file).
        $out = $this->curlPost(
            self::$host.'/upload_server.php?action=validate',
            [],
            'doc',
            $doc,
            'notes.txt'
        );

        $this->assertTrue($out['valid'] ?? false, 'Expected valid: '.json_encode($out['errors'] ?? []));

        @unlink($img);
        @unlink($doc);
    }

    public function test_validate_rejects_non_image_for_image_rule(): void
    {
        $doc = tempnam(sys_get_temp_dir(), 'ant_doc_');
        file_put_contents($doc, 'plain text, not an image');

        $out = $this->curlPost(
            self::$host.'/upload_server.php?action=validate',
            [],
            'avatar',
            $doc,
            'notes.txt'
        );

        $this->assertFalse($out['valid'] ?? true);
        $this->assertArrayHasKey('avatar', $out['errors'] ?? []);
        $this->assertStringContainsString('must be an image', $out['errors']['avatar'][0] ?? '');

        @unlink($doc);
    }

    public function test_validate_rejects_wrong_mime_for_mimes_rule(): void
    {
        $doc = tempnam(sys_get_temp_dir(), 'ant_doc_');
        file_put_contents($doc, 'plain text');

        $out = $this->curlPost(
            self::$host.'/upload_server.php?action=validate',
            [],
            'doc',
            $doc,
            'notes.csv'
        );

        $this->assertFalse($out['valid'] ?? true);
        $this->assertArrayHasKey('doc', $out['errors'] ?? []);
        $this->assertStringContainsString('pdf,txt', $out['errors']['doc'][0] ?? '');

        @unlink($doc);
    }

    public function test_validate_rejects_oversized_file(): void
    {
        $big = tempnam(sys_get_temp_dir(), 'ant_big_');
        file_put_contents($big, str_repeat('a', 2048)); // 2 KB > 1 KB limit

        $out = $this->curlPost(
            self::$host.'/upload_server.php?action=validate',
            [],
            'big',
            $big,
            'big.bin'
        );

        $this->assertFalse($out['valid'] ?? true);
        $this->assertArrayHasKey('big', $out['errors'] ?? []);
        $this->assertStringContainsString('1 kilobytes', $out['errors']['big'][0] ?? '');

        @unlink($big);
    }
}
