<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function test_e_escapes_html(): void
    {
        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', e('<script>alert(1)</script>'));
    }

    public function test_e_handles_null(): void
    {
        $this->assertSame('', e(null));
    }

    public function test_e_escapes_quotes(): void
    {
        $this->assertSame('&quot;hello&quot;', e('"hello"'));
    }

    public function test_env_returns_default(): void
    {
        $this->assertSame('fallback', env('UNDEFINED_VAR_12345', 'fallback'));
    }

    public function test_env_type_casts_boolean_true(): void
    {
        putenv('TEST_BOOL=true');
        $this->assertTrue(env('TEST_BOOL'));
        putenv('TEST_BOOL');
    }

    public function test_env_type_casts_boolean_false(): void
    {
        putenv('TEST_BOOL=false');
        $this->assertFalse(env('TEST_BOOL'));
        putenv('TEST_BOOL');
    }

    public function test_env_type_casts_null(): void
    {
        putenv('TEST_NULL=null');
        $this->assertNull(env('TEST_NULL'));
        putenv('TEST_NULL');
    }

    public function test_redirect_returns_response(): void
    {
        $res = redirect('/login', 302);
        $this->assertSame(302, $res->getStatusCode());
        $this->assertSame('/login', $res->getHeaders()['Location']);
    }
}
