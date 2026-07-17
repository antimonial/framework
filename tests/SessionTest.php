<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Security\Csrf;
use Antimonial\Security\TokenMismatchException;
use Antimonial\Session\Session;
use Antimonial\View\Compiler;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive unit tests for the opt-in Session + CSRF layer.
 *
 * Migrated from the standalone harness (tests/session_test.php). Uses a
 * temp session save path with cookies disabled to exercise the native
 * PHP session without HTTP headers.
 */
final class SessionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ant_sess_'.uniqid();
        mkdir($this->dir, 0777, true);
        ini_set('session.save_path', $this->dir);
        ini_set('session.use_cookies', '0');
    }

    protected function tearDown(): void
    {
        @session_write_close();
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_start_and_put_get(): void
    {
        Session::start();
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        Session::put('user', 42);
        self::assertSame(42, Session::get('user'));
        self::assertTrue(Session::has('user'));
    }

    public function test_pull_gets_and_forgets(): void
    {
        Session::start();
        Session::put('once', 'x');
        self::assertSame('x', Session::pull('once'));
        self::assertFalse(Session::has('once'));
    }

    public function test_flash_ages_out_next_request(): void
    {
        Session::start();
        Session::flash('msg', 'hi');
        self::assertSame('hi', Session::getFlash('msg'));
        session_write_close();
        Session::start();
        self::assertNull(Session::getFlash('msg'));
    }

    public function test_regenerate_changes_id(): void
    {
        Session::start();
        $before = Session::id();
        Session::regenerate();
        self::assertNotSame($before, Session::id());
    }

    public function test_csrf_token_stable_and_verifies(): void
    {
        Session::start();
        $token = Csrf::token();
        self::assertNotEmpty($token);
        self::assertSame(64, strlen($token));
        self::assertSame($token, Csrf::token());
        self::assertTrue(Csrf::verify($token));
    }

    public function test_csrf_rejects_wrong_token(): void
    {
        Session::start();
        $this->expectException(TokenMismatchException::class);
        Csrf::verify('wrong');
    }

    public function test_csrf_rejects_null_token(): void
    {
        Session::start();
        $this->expectException(TokenMismatchException::class);
        Csrf::verify(null);
    }

    public function test_csrf_field_renders_hidden_input(): void
    {
        Session::start();
        $field = Csrf::field();
        $token = Csrf::token();
        self::assertStringContainsString('name="_token"', $field);
        self::assertStringContainsString($token, $field);
    }

    public function test_csrf_directive_compiles(): void
    {
        $out = (new Compiler)->compileString('<form>@csrf</form>');
        self::assertStringContainsString('Csrf::field()', $out);
        self::assertStringContainsString('<form>', $out);
    }
}
