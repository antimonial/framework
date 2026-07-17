<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $_COOKIE = [];
        $_FILES = [];
    }

    public function test_uri_returns_path(): void
    {
        $_SERVER['REQUEST_URI'] = '/users';
        $this->assertSame('/users', Request::fromGlobals()->uri());
    }

    public function test_uri_strips_query_string(): void
    {
        $_SERVER['REQUEST_URI'] = '/users?page=2';
        $this->assertSame('/users', Request::fromGlobals()->uri());
    }

    public function test_method_returns_uppercase(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'post';
        $this->assertSame('POST', Request::fromGlobals()->method());
    }

    public function test_is_get(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->assertTrue(Request::fromGlobals()->isGet());
    }

    public function test_is_post(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue(Request::fromGlobals()->isPost());
    }

    public function test_is_put(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $this->assertTrue(Request::fromGlobals()->isPut());
    }

    public function test_is_delete(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $this->assertTrue(Request::fromGlobals()->isDelete());
    }

    public function test_query_returns_get_value(): void
    {
        $_GET['page'] = '2';
        $_SERVER['REQUEST_URI'] = '/users?page=2';
        $this->assertSame('2', Request::fromGlobals()->query('page'));
    }

    public function test_query_returns_default(): void
    {
        $this->assertNull(Request::fromGlobals()->query('nonexistent'));
        $this->assertSame('default', Request::fromGlobals()->query('nonexistent', 'default'));
    }

    public function test_post_returns_value(): void
    {
        $_POST['name'] = 'Alice';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame('Alice', Request::fromGlobals()->post('name'));
    }

    public function test_input_falls_back_to_get(): void
    {
        $_GET['page'] = '2';
        $this->assertSame('2', Request::fromGlobals()->input('page'));
    }

    public function test_input_returns_default(): void
    {
        $this->assertNull(Request::fromGlobals()->input('missing'));
    }

    public function test_all_merges_post_and_get(): void
    {
        $_GET['page'] = '1';
        $_POST['name'] = 'Alice';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertSame(['page' => '1', 'name' => 'Alice'], Request::fromGlobals()->all());
    }

    public function test_has_returns_true(): void
    {
        $_GET['key'] = 'value';
        $this->assertTrue(Request::fromGlobals()->has('key'));
    }

    public function test_has_returns_false(): void
    {
        $this->assertFalse(Request::fromGlobals()->has('missing'));
    }

    public function test_header_returns_value(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'abc';
        $this->assertSame('abc', Request::fromGlobals()->header('X-CSRF-TOKEN'));
    }

    public function test_header_returns_default(): void
    {
        $this->assertNull(Request::fromGlobals()->header('Missing'));
    }

    public function test_set_and_get_attributes(): void
    {
        $req = Request::fromGlobals();
        $req->set('user_id', 42);
        $this->assertSame(42, $req->get('user_id'));
    }

    public function test_get_returns_default(): void
    {
        $this->assertNull(Request::fromGlobals()->get('missing'));
    }

    public function test_cookie_returns_value(): void
    {
        $_COOKIE['session'] = 'abc123';
        $this->assertSame('abc123', Request::fromGlobals()->cookie('session'));
    }

    public function test_file_returns_upload(): void
    {
        $_FILES['avatar'] = [
            'name' => 'test.png',
            'type' => 'image/png',
            'tmp_name' => '/tmp/php/test',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
        ];
        $file = Request::fromGlobals()->file('avatar');
        $this->assertNotNull($file);
        $this->assertSame('test.png', $file['name']);
    }

    public function test_file_returns_null_on_missing(): void
    {
        $this->assertNull(Request::fromGlobals()->file('missing'));
    }

    public function test_method_override_via_post(): void
    {
        $_POST['_method'] = 'DELETE';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertTrue(Request::fromGlobals()->isDelete());
    }
}
