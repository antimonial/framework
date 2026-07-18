<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Controller\Controller;
use Antimonial\Database\Connection;
use Antimonial\Database\DB;
use Antimonial\Http\Request;
use Antimonial\Http\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the database-backed validation rules (unique / exists)
 * added to Controller::validate().
 */
final class ControllerDbValidationTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'];
        $_COOKIE = [];
        $_FILES = [];

        $conn = new Connection(['driver' => 'sqlite', 'database' => ':memory:']);
        $conn->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, name TEXT)');
        $conn->execute("INSERT INTO users (email, name) VALUES ('taken@x.com', 'Bob')");
        DB::connection(['driver' => 'sqlite', 'database' => ':memory:']); // reset shared facade
        // Re-bind the in-memory connection so queries hit our seeded data.
        $ref = new \ReflectionProperty(DB::class, 'connection');
        $ref->setAccessible(true);
        $ref->setValue(null, $conn);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(DB::class, 'connection');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
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

    public function test_unique_passes_for_new_value(): void
    {
        $_POST['email'] = 'free@x.com';
        $request = Request::fromGlobals();

        $result = $this->ctrl()->run($request, ['email' => 'unique:users,email']);

        self::assertSame(['email' => 'free@x.com'], $result);
    }

    public function test_unique_fails_for_existing_value(): void
    {
        $_POST['email'] = 'taken@x.com';
        $request = Request::fromGlobals();

        try {
            $this->ctrl()->run($request, ['email' => 'unique:users,email']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
            self::assertStringContainsString('already been taken', $e->errors()['email'][0]);
        }
    }

    public function test_unique_defaults_column_to_field(): void
    {
        $_POST['name'] = 'Bob';
        $request = Request::fromGlobals();

        try {
            $this->ctrl()->run($request, ['name' => 'unique:users']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->errors());
        }
    }

    public function test_unique_empty_value_passes(): void
    {
        $_POST['email'] = '';
        $request = Request::fromGlobals();

        $result = $this->ctrl()->run($request, ['email' => 'unique:users,email']);

        self::assertSame(['email' => ''], $result);
    }

    public function test_exists_passes_for_existing_value(): void
    {
        $_POST['email'] = 'taken@x.com';
        $request = Request::fromGlobals();

        $result = $this->ctrl()->run($request, ['email' => 'exists:users,email']);

        self::assertSame(['email' => 'taken@x.com'], $result);
    }

    public function test_exists_fails_for_missing_value(): void
    {
        $_POST['email'] = 'nobody@x.com';
        $request = Request::fromGlobals();

        try {
            $this->ctrl()->run($request, ['email' => 'exists:users,email']);
            self::fail('Expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
            self::assertStringContainsString('is invalid', $e->errors()['email'][0]);
        }
    }

    public function test_exists_empty_value_passes(): void
    {
        $_POST['email'] = '';
        $request = Request::fromGlobals();

        $result = $this->ctrl()->run($request, ['email' => 'exists:users,email']);

        self::assertSame(['email' => ''], $result);
    }
}
