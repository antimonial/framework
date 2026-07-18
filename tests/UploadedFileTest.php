<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure (non-upload-dependent) parts of UploadedFile:
 * metadata accessors, error reporting, and the store() failure path.
 *
 * The success path of store() and the content-based validation rules
 * (image/mimes/max_size) require a genuine HTTP upload and are covered
 * by UploadedFileServerTest via the built-in PHP server.
 */
final class UploadedFileTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/ant_up_'.uniqid();
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function makeFile(string $name, string $contents, int $error = UPLOAD_ERR_OK): UploadedFile
    {
        $path = $this->tmp.'/'.$name;
        file_put_contents($path, $contents);

        return new UploadedFile([
            'name' => $name,
            'type' => 'text/plain',
            'tmp_name' => $path,
            'error' => $error,
            'size' => strlen($contents),
        ]);
    }

    public function test_size_and_client_name(): void
    {
        $file = $this->makeFile('report.txt', 'hello');
        $this->assertSame(5, $file->size());
        $this->assertSame('report.txt', $file->clientName());
        $this->assertSame('txt', $file->clientExtension());
    }

    public function test_client_extension_empty_when_no_name(): void
    {
        $file = new UploadedFile(['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'type' => '']);
        $this->assertSame('', $file->clientExtension());
    }

    public function test_mime_type_empty_when_tmp_missing(): void
    {
        $file = new UploadedFile(['name' => 'x', 'tmp_name' => '/no/such/file', 'error' => UPLOAD_ERR_OK, 'size' => 0, 'type' => '']);
        $this->assertSame('', $file->mimeType());
    }

    public function test_is_valid_false_for_non_uploaded_file(): void
    {
        $file = $this->makeFile('report.txt', 'hello');
        // Not moved via HTTP upload, so is_uploaded_file() is false.
        $this->assertFalse($file->isValid());
    }

    public function test_error_and_message(): void
    {
        $file = new UploadedFile(['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'type' => '']);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $file->error());
        $this->assertSame('No file was uploaded.', $file->errorMessage());
    }

    public function test_error_message_ok_is_empty(): void
    {
        $file = $this->makeFile('ok.txt', 'x');
        $this->assertSame(UPLOAD_ERR_OK, $file->error());
        $this->assertSame('', $file->errorMessage());
    }

    public function test_error_message_unknown(): void
    {
        $file = new UploadedFile(['name' => '', 'tmp_name' => '', 'error' => 9999, 'size' => 0, 'type' => '']);
        $this->assertSame('Unknown upload error.', $file->errorMessage());
    }

    public function test_store_throws_when_move_fails(): void
    {
        // A non-uploaded temp file makes move_uploaded_file() fail.
        $file = $this->makeFile('x.txt', 'data');
        $this->expectException(\RuntimeException::class);
        $file->store($this->tmp, 'x.txt');
    }
}
