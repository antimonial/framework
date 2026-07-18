<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Controller\Controller;
use Antimonial\Http\Request;
use Antimonial\Http\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for array-valued fields (HTML inputs with array
 * notation, e.g. name="tags[]") reaching Controller::validate().
 *
 * Before the fix, a non-`required` rule applied to an array value threw a
 * TypeError from strlen()/preg_match(); now it yields a normal error.
 */
final class ControllerArrayValidationTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'];
        $_COOKIE = [];
        $_FILES = [];
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

    public function test_array_value_with_min_rule_returns_error_not_type_error(): void
    {
        $_POST['tags'] = ['a', 'b'];
        $request = Request::fromGlobals();

        try {
            $this->ctrl()->run($request, ['tags' => 'min:3']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('tags', $e->errors());
            self::assertStringContainsString('must be a single value, not a list', $e->errors()['tags'][0]);
        }
    }

    public function test_array_value_with_alpha_rule_returns_error_not_type_error(): void
    {
        $_POST['tags'] = ['a', 'b'];
        $request = Request::fromGlobals();

        try {
            $this->ctrl()->run($request, ['tags' => 'alpha']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('tags', $e->errors());
            self::assertStringContainsString('must be a single value, not a list', $e->errors()['tags'][0]);
        }
    }

    public function test_array_value_with_required_empty_fails(): void
    {
        $_POST['tags'] = [];
        $request = Request::fromGlobals();

        try {
            $this->ctrl()->run($request, ['tags' => 'required']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('tags', $e->errors());
            self::assertStringContainsString('is required', $e->errors()['tags'][0]);
        }
    }

    public function test_array_value_with_required_non_empty_passes(): void
    {
        $_POST['tags'] = ['a'];
        $request = Request::fromGlobals();

        $result = $this->ctrl()->run($request, ['tags' => 'required']);

        self::assertSame(['tags' => ['a']], $result);
    }

    public function test_array_value_with_explicit_array_rule_passes(): void
    {
        $_POST['tags'] = ['a', 'b'];
        $request = Request::fromGlobals();

        $result = $this->ctrl()->run($request, ['tags' => 'array']);

        self::assertSame(['tags' => ['a', 'b']], $result);
    }
}
