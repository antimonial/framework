<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Database\Connection;
use Antimonial\Model\Model;
use Antimonial\Security\Auth;
use Antimonial\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Auth facade.
 *
 * Uses an in-memory sqlite table via a tiny User model. Sessions are
 * exercised through the real Session layer (temp save path, no cookies).
 */
final class AuthTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ant_auth_'.uniqid();
        mkdir($this->dir, 0777, true);
        ini_set('session.save_path', $this->dir);
        ini_set('session.use_cookies', '0');

        // Reset the framework's static "started" flag so each test gets a
        // fresh session bound to this test's save path.
        $ref = new \ReflectionProperty(Session::class, 'started');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
        @session_write_close();

        Session::start();

        Auth::useModel(UserStub::class);
        UserStub::reset();
    }

    protected function tearDown(): void
    {
        Session::flush();
        @session_write_close();
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_attempt_logs_in_with_valid_credentials(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        UserStub::seed(['id' => 1, 'email' => 'a@b.c', 'password' => $hash]);

        self::assertTrue(Auth::attempt(['email' => 'a@b.c', 'password' => 'secret']));
        self::assertTrue(Auth::check());
        self::assertSame(1, Auth::id());
    }

    public function test_attempt_fails_with_wrong_password(): void
    {
        $hash = password_hash('secret', PASSWORD_DEFAULT);
        UserStub::seed(['id' => 1, 'email' => 'a@b.c', 'password' => $hash]);

        self::assertFalse(Auth::attempt(['email' => 'a@b.c', 'password' => 'nope']));
        self::assertFalse(Auth::check());
        self::assertNull(Auth::id());
    }

    public function test_attempt_fails_with_unknown_user(): void
    {
        self::assertFalse(Auth::attempt(['email' => 'missing@b.c', 'password' => 'x']));
        self::assertFalse(Auth::check());
    }

    public function test_attempt_fails_without_password(): void
    {
        self::assertFalse(Auth::attempt(['email' => 'a@b.c']));
        self::assertFalse(Auth::check());
    }

    public function test_attempt_fails_with_empty_lookup(): void
    {
        self::assertFalse(Auth::attempt(['password' => 'x']));
        self::assertFalse(Auth::check());
    }

    public function test_login_and_logout(): void
    {
        Auth::login((object) ['id' => 7]);
        self::assertTrue(Auth::check());
        self::assertSame(7, Auth::id());

        Auth::logout();
        self::assertFalse(Auth::check());
        self::assertNull(Auth::id());
    }

    public function test_login_ignores_user_without_id(): void
    {
        Auth::login((object) ['name' => 'no-id']);
        self::assertFalse(Auth::check());
        self::assertNull(Auth::id());
    }

    public function test_user_returns_record_when_authenticated(): void
    {
        UserStub::seed(['id' => 3, 'email' => 'c@b.c', 'password' => 'x']);
        Auth::login((object) ['id' => 3]);

        $user = Auth::user();
        self::assertNotNull($user);
        self::assertSame('c@b.c', $user->email);
    }

    public function test_user_returns_null_when_guest(): void
    {
        self::assertNull(Auth::user());
    }
}

/**
 * Minimal user model backed by an in-memory sqlite table.
 *
 * @internal
 */
final class UserStub extends Model
{
    protected string $table = 'users';

    /** @var array<int, array<string, mixed>> */
    private static array $rows = [];

    /**
     * Clear seeded rows between tests.
     */
    public static function reset(): void
    {
        self::$rows = [];
    }

    public function __construct()
    {
        parent::__construct(new Connection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]));

        $this->getConnection()->execute(
            'CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, email TEXT, password TEXT)'
        );
        foreach (self::$rows as $row) {
            $this->getConnection()->execute(
                'INSERT INTO users (id, email, password) VALUES (?, ?, ?)',
                [$row['id'], $row['email'], $row['password']]
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function seed(array $rows): void
    {
        self::$rows = [$rows];
    }
}
