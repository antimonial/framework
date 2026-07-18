<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Core\App;
use Antimonial\Core\Config;
use Antimonial\Http\Request;
use Antimonial\Http\Response;
use Antimonial\Session\Session;
use PHPUnit\Framework\TestCase;

/**
 * Tests for form re-population: the `old()` / `errors()` helpers and the
 * App validation-error response (JSON vs flash+redirect).
 */
final class FormRepopulationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ant_form_'.uniqid();
        mkdir($this->dir, 0777, true);
        ini_set('session.save_path', $this->dir);
        ini_set('session.use_cookies', '0');

        $ref = new \ReflectionProperty(Session::class, 'started');
        $ref->setAccessible(true);
        $ref->setValue(null, false);
        @session_write_close();

        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
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

    public function test_old_returns_flash_value(): void
    {
        Session::start();
        Session::flash('old', ['email' => 'a@b.c']);

        self::assertSame('a@b.c', old('email'));
        self::assertSame('', old('missing'));
        self::assertSame('fallback', old('missing', 'fallback'));
    }

    public function test_old_returns_default_when_not_array(): void
    {
        Session::start();
        Session::flash('old', 'not-an-array');

        self::assertSame('fallback', old('email', 'fallback'));
    }

    public function test_errors_returns_flash_errors(): void
    {
        Session::start();
        $errors = ['email' => ['The email is required.']];
        Session::flash('errors', $errors);

        self::assertSame($errors, errors());
    }

    public function test_errors_returns_empty_when_none(): void
    {
        Session::start();

        self::assertSame([], errors());
    }

    public function test_errors_returns_empty_when_not_array(): void
    {
        Session::start();
        Session::flash('errors', 'garbage');

        self::assertSame([], errors());
    }

    public function test_validation_response_is_json_when_sessions_disabled(): void
    {
        $this->setConfig('app.session', false);
        Session::start();

        $request = Request::fromGlobals();
        $errors = ['email' => ['Required.']];

        $response = $this->callValidationResponse($errors, $request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaders()['Content-Type']);
        self::assertStringContainsString('Required.', $response->getBody());
    }

    public function test_validation_response_is_json_for_xhr(): void
    {
        $this->setConfig('app.session', true);
        Session::start();

        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $request = Request::fromGlobals();
        $errors = ['email' => ['Required.']];

        $response = $this->callValidationResponse($errors, $request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaders()['Content-Type']);
    }

    public function test_validation_response_flashes_and_redirects_for_browser(): void
    {
        $this->setConfig('app.session', true);
        Session::start();

        $_SERVER['HTTP_REFERER'] = '/contact';
        $_POST = ['email' => 'a@b.c'];
        $request = Request::fromGlobals();
        $errors = ['email' => ['Required.']];

        $response = $this->callValidationResponse($errors, $request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/contact', $response->getHeaders()['Location']);

        // Errors and old input were flashed for the next request.
        self::assertSame($errors, Session::getFlash('errors'));
        self::assertSame(['email' => 'a@b.c'], Session::getFlash('old'));
    }

    public function test_validation_response_redirects_to_root_without_referer(): void
    {
        $this->setConfig('app.session', true);
        Session::start();

        $request = Request::fromGlobals();
        $errors = ['email' => ['Required.']];

        $response = $this->callValidationResponse($errors, $request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaders()['Location']);
    }

    /**
     * Invoke App::validationErrorResponse() via reflection.
     *
     * @param  array<string, string[]>  $errors
     */
    private function callValidationResponse(array $errors, Request $request): Response
    {
        $method = new \ReflectionMethod(App::class, 'validationErrorResponse');
        $method->setAccessible(true);

        /** @var Response $result */
        $result = $method->invoke(new App, $errors, $request);

        return $result;
    }

    /**
     * Set a Config value via reflection (Config has no public setter).
     */
    private function setConfig(string $key, mixed $value): void
    {
        $ref = new \ReflectionProperty(Config::class, 'data');
        $ref->setAccessible(true);
        /** @var array<string, mixed> $data */
        $data = $ref->getValue();
        $data['app']['session'] = $value;
        $ref->setValue($data);
    }
}
