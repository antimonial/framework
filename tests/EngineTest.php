<?php

declare(strict_types=1);

namespace Antimonial\Tests;

use Antimonial\View\Compiler;
use Antimonial\View\Filters;
use Antimonial\View\ViewEngine;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive unit tests for the built-in template engine.
 *
 * Migrated from the standalone harness (tests/engine_test.php) into a
 * PHPUnit test case. Covers auto-escaping, raw echoes, comments, loops,
 * conditionals (if/elseif/else, unless), for/while, set, filters (built-in
 * + custom), includes (inherited/explicit data), layouts (@extends/@section/
 *
 * @yield/$content), nested blocks, cross-render state isolation, caching,
 * and missing-template errors.
 */
final class EngineTest extends TestCase
{
    private string $dir;

    private ViewEngine $engine;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ant_view_test_'.uniqid();
        mkdir($this->dir, 0777, true);
        mkdir($this->dir.'/partials', 0777, true);
        mkdir($this->dir.'/layouts', 0777, true);
        $this->engine = new ViewEngine($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*.php') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir.'/partials/*.php') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir.'/layouts/*.php') ?: [] as $f) {
            unlink($f);
        }
        foreach (glob($this->dir.'/../storage/views/*.php') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir.'/partials');
        @rmdir($this->dir.'/layouts');
        @rmdir($this->dir.'/../storage/views');
        @rmdir($this->dir.'/..');
    }

    private function tpl(string $rel, string $content): void
    {
        file_put_contents($this->dir.'/'.$rel, $content);
    }

    public function test_auto_escape(): void
    {
        $this->tpl('escape.php', '<p>{{ $name }}</p>');
        self::assertSame('<p>&lt;b&gt;x&lt;/b&gt;</p>', $this->engine->render('escape', ['name' => '<b>x</b>']));
    }

    public function test_raw_echo(): void
    {
        $this->tpl('raw.php', '<p>{{{ $html }}}</p>');
        self::assertSame('<p><b>x</b></p>', $this->engine->render('raw', ['html' => '<b>x</b>']));
    }

    public function test_comment_stripped(): void
    {
        $this->tpl('comment.php', 'a{{-- secret --}}b');
        self::assertSame('ab', $this->engine->render('comment'));
    }

    public function test_foreach_loop(): void
    {
        $this->tpl('loop.php', '@foreach($items as $i)<x>{{ $i }}</x>@endforeach');
        self::assertSame('<x>a</x><x>b</x>', $this->engine->render('loop', ['items' => ['a', 'b']]));
    }

    public function test_if_elseif_else(): void
    {
        $this->tpl('cond.php', "@if(\$a)\nyes\n@elseif(\$b)\nmaybe\n@else\nno\n@endif");
        self::assertSame('yes', trim($this->engine->render('cond', ['a' => true])));
        self::assertSame('maybe', trim($this->engine->render('cond', ['a' => false, 'b' => true])));
        self::assertSame('no', trim($this->engine->render('cond', ['a' => false, 'b' => false])));
    }

    public function test_unless(): void
    {
        $this->tpl('unless.php', "@unless(\$active)\noff\n@endunless");
        self::assertSame('off', trim($this->engine->render('unless', ['active' => false])));
        self::assertSame('', trim($this->engine->render('unless', ['active' => true])));
    }

    public function test_for_loop(): void
    {
        $this->tpl('for.php', '@for($i=0;$i<3;$i++){{ $i }}@endfor');
        self::assertSame('012', $this->engine->render('for'));
    }

    public function test_while_loop(): void
    {
        $this->tpl('while.php', '@php $n=0; @endphp@while($n<3){{ $n }}@php $n++; @endphp@endwhile');
        self::assertSame('012', $this->engine->render('while'));
    }

    public function test_set_variable(): void
    {
        $this->tpl('set.php', '@set($t = 5)<p>{{ $t }}</p>');
        self::assertSame('<p>5</p>', $this->engine->render('set'));
    }

    public function test_filters_upper_trim(): void
    {
        $this->tpl('filters.php', '{{ $name|upper }}!{{ $name|trim }}');
        self::assertSame('  BOB !bob', $this->engine->render('filters', ['name' => '  bob ']));
    }

    public function test_filter_length(): void
    {
        $this->tpl('len.php', '{{ $x|length }}');
        self::assertSame('3', $this->engine->render('len', ['x' => 'abc']));
    }

    public function test_custom_filter(): void
    {
        Filters::add('excl', fn ($v) => $v.'!');
        $this->tpl('custom.php', '{{ $v|excl }}');
        self::assertSame('hi!', $this->engine->render('custom', ['v' => 'hi']));
    }

    public function test_include_inherits_data(): void
    {
        $this->tpl('partials/nav.php', '<nav>{{ $title }}</nav>');
        $this->tpl('withinclude.php', '@include("partials/nav")');
        self::assertSame('<nav>Home</nav>', $this->engine->render('withinclude', ['title' => 'Home']));
    }

    public function test_include_explicit_data(): void
    {
        $this->tpl('partials/nav.php', '<nav>{{ $title }}</nav>');
        $this->tpl('withinclude2.php', '@include("partials/nav", ["title" => "X"])');
        self::assertSame('<nav>X</nav>', $this->engine->render('withinclude2', ['title' => 'Home']));
    }

    public function test_layout_extends_section_yield_content(): void
    {
        $this->tpl('layouts/main.php', '<title>@yield("title","Def")</title><main>{{{ $content }}}</main>');
        $this->tpl('page.php', "@extends('layouts/main')@section('title')My Page@endsection<h1>Hi</h1>");
        self::assertSame(
            '<title>My Page</title><main><h1>Hi</h1></main>',
            trim($this->engine->render('page'))
        );
    }

    public function test_nested_if_foreach_with_count(): void
    {
        $this->tpl('nested.php', '@if(count($u) > 0)@foreach($u as $x)<i>{{ $x }}</i>@endforeach@else none@endif');
        self::assertSame('<i>a</i><i>b</i>', $this->engine->render('nested', ['u' => ['a', 'b']]));
    }

    public function test_state_isolation_between_renders(): void
    {
        $this->tpl('escape.php', '<p>{{ $name }}</p>');
        $this->tpl('page.php', "@extends('layouts/main')@section('title')T@endsection<body>");
        $this->tpl('layouts/main.php', '<main>{{{ $content }}}</main>');
        $this->engine->render('escape', ['name' => '<x>']);
        $this->engine->render('page'); // uses sections; must not leak into next render
        $third = $this->engine->render('escape', ['name' => '<y>']);
        self::assertSame('<p>&lt;y&gt;</p>', $third);
    }

    public function test_cache_dir_created(): void
    {
        $this->tpl('escape.php', '<p>{{ $name }}</p>');
        $this->engine->render('escape', ['name' => 'z']);
        self::assertDirectoryExists($this->dir.'/../storage/views');
    }

    public function test_missing_template_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->engine->render('does_not_exist_xyz');
    }

    public function test_compiler_resolves_elseif_inline(): void
    {
        $compiled = (new Compiler)->compileString('@if($a)yes@elseif($b)no@endif');
        self::assertStringContainsString('<?php elseif($b): ?>', $compiled);
        self::assertStringContainsString('<?php endif; ?>', $compiled);
    }

    public function test_include_inherits_loop_variable(): void
    {
        $this->tpl('partials/item.php', '<li>{{ $item }}</li>');
        $this->tpl('loop_include.php', '@foreach($items as $item)@include("partials/item")@endforeach');
        self::assertSame(
            '<li>a</li><li>b</li>',
            $this->engine->render('loop_include', ['items' => ['a', 'b']])
        );
    }

    public function test_include_explicit_data_overrides_scope(): void
    {
        $this->tpl('partials/nav.php', '<nav>{{ $title }}</nav>');
        $this->tpl('withinclude.php', '@include("partials/nav", ["title" => "Override"])');
        self::assertSame('<nav>Override</nav>', $this->engine->render('withinclude', ['title' => 'Original']));
    }
}
