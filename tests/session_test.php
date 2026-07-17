#!/usr/bin/env php
<?php
/**
 * Standalone test harness for Session + CSRF (no PHPUnit / Composer needed).
 * Run: php tests/session_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/Session/Session.php';
require __DIR__ . '/../src/Security/TokenMismatchException.php';
require __DIR__ . '/../src/Security/Csrf.php';
require __DIR__ . '/../src/View/Compiler.php';
require __DIR__ . '/../src/View/Filters.php';

$passed = 0;
$failed = 0;
function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) { $passed++; echo "  ok  - $name\n"; }
    else { $failed++; echo "FAIL  - $name" . ($detail !== '' ? " :: $detail" : '') . "\n"; }
}

// We need a session without HTTP headers: use a temp save path.
$dir = sys_get_temp_dir() . '/ant_sess_' . uniqid();
mkdir($dir, 0777, true);
ini_set('session.save_path', $dir);
ini_set('session.use_cookies', '0');

// 1. start + put/get
Antimonial\Session\Session::start();
check('session started', session_status() === PHP_SESSION_ACTIVE);
Antimonial\Session\Session::put('user', 42);
check('put/get', Antimonial\Session\Session::get('user') === 42);
check('has', Antimonial\Session\Session::has('user'));

// 2. pull (get + forget)
Antimonial\Session\Session::put('once', 'x');
check('pull value', Antimonial\Session\Session::pull('once') === 'x');
check('pull forgot', !Antimonial\Session\Session::has('once'));

// 3. flash: available now, gone after a new request cycle
Antimonial\Session\Session::flash('msg', 'hi');
check('flash readable now', Antimonial\Session\Session::getFlash('msg') === 'hi');
// Simulate end-of-request aging: Session::start() clears __flash on next call.
session_write_close();
Antimonial\Session\Session::start();
check('flash cleared next request', Antimonial\Session\Session::getFlash('msg') === null);

// 4. regenerate
$before = Antimonial\Session\Session::id();
Antimonial\Session\Session::regenerate();
$after = Antimonial\Session\Session::id();
check('regenerate changes id', $before !== $after);

// 5. CSRF token stable + verify OK
$token = Antimonial\Security\Csrf::token();
check('csrf token generated', $token !== '' && strlen($token) === 64);
check('csrf token stable', Antimonial\Security\Csrf::token() === $token);
check('csrf verify ok', Antimonial\Security\Csrf::verify($token) === true);

// 6. CSRF verify fails on wrong/missing token
try { Antimonial\Security\Csrf::verify('wrong'); $failed++; echo "FAIL  - csrf rejects wrong\n"; }
catch (Antimonial\Security\TokenMismatchException $e) { $passed++; echo "  ok  - csrf rejects wrong\n"; }
try { Antimonial\Security\Csrf::verify(null); $failed++; echo "FAIL  - csrf rejects null\n"; }
catch (Antimonial\Security\TokenMismatchException $e) { $passed++; echo "  ok  - csrf rejects null\n"; }

// 7. Csrf::field() renders hidden input
$field = Antimonial\Security\Csrf::field();
check('csrf field html', str_contains($field, 'name="_token"') && str_contains($field, $token), $field);

// 8. @csrf directive compiles via the engine
$c = new Antimonial\View\Compiler();
$out = $c->compileString('<form>@csrf</form>');
check('@csrf compiles', str_contains($out, "Csrf::field()") && str_contains($out, '<form>'), $out);

session_write_close();
array_map('unlink', glob($dir . '/*'));
@rmdir($dir);

echo "\n" . str_repeat('=', 40) . "\n";
echo "PASSED: $passed   FAILED: $failed\n";
echo str_repeat('=', 40) . "\n";
exit($failed === 0 ? 0 : 1);
