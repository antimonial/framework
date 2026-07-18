<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Controller\Controller;
use Antimonial\Http\Request;
use Antimonial\Http\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the file-validation rule routing in Controller::validate().
 *
 * These cover the logic that does not require a genuine HTTP upload:
 *  - a field with any file rule is routed away from the string path
 *  - a missing file passes file rules (required handles absence)
 *  - an upload with a non-OK error code is reported via errorMessage()
 *
 * The content-based rules (image/mimes/max_size) and store() success path
 * need a real upload and are covered by UploadedFileServerTest.
 */
final class ControllerFileValidationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/ant_cv_'.uniqid();
        mkdir($this->tmp, 0777, true);
        $_GET = [];
        $_POST = [];
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'];
        $_COOKIE = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function requestWithFile(string $key, array $entry): Request
    {
        $_FILES = [$key => $entry];

        return Request::fromGlobals();
    }

    private function entry(string $name, string $path, int $error = UPLOAD_ERR_OK, int $size = 0): array
    {
        return [
            'name' => $name,
            'type' => 'text/plain',
            'tmp_name' => $path,
            'error' => $error,
            'size' => $size,
        ];
    }

    private function ctrl(): Controller
    {
        return new class extends Controller
        {
            public function run(Request $r, array $rules): array
            {
                return $this->validate($r, $rules);
            }
        };
    }

    public function test_missing_file_passes_file_rules(): void
    {
        // No file in $_FILES: file rules must not error (required handles absence).
        $request = Request::fromGlobals();
        $result = $this->ctrl()->run($request, ['avatar' => 'file|image']);
        $this->assertSame(['avatar' => null], $result);
    }

    public function test_invalid_upload_errors(): void
    {
        $path = $this->tmp.'/x.txt';
        file_put_contents($path, 'x');
        $request = $this->requestWithFile('avatar', $this->entry('x.txt', $path, UPLOAD_ERR_PARTIAL, 1));

        try {
            $this->ctrl()->run($request, ['avatar' => 'file']);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('avatar', $e->errors());
            $this->assertStringContainsString('partially uploaded', $e->errors()['avatar'][0]);
        }
    }

    public function test_file_rule_not_validated_as_string(): void
    {
        // A field with a file rule must NOT be run through the string path.
        // Here 'avatar' has 'file|required' but no file is present. Via the
        // file path, 'required' is not a file rule so it is ignored (passes);
        // via the string path, 'required' on an empty value would fail.
        $request = Request::fromGlobals();
        $result = $this->ctrl()->run($request, ['avatar' => 'file|required']);
        $this->assertArrayHasKey('avatar', $result);
    }
}
