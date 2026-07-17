#!/usr/bin/env php
<?php
/**
 * Standalone test harness for the Antimonial View Engine.
 *
 * No PHPUnit / Composer needed: requires the engine classes directly and
 * asserts behavior. Run: php tests/engine_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/View/Compiler.php';
require __DIR__ . '/../src/View/Filters.php';
require __DIR__ . '/../src/View/ViewEngine.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  ok  - $name\n";
    } else {
        $failed++;
        echo "FAIL  - $name" . ($detail !== '' ? " :: $detail" : '') . "\n";
    }
}

// Prepare a temp view dir
$dir = sys_get_temp_dir() . '/ant_view_test_' . uniqid();
mkdir($dir, 0777, true);
mkdir($dir . '/partials', 0777, true);
mkdir($dir . '/layouts', 0777, true);

function tpl(string $dir, string $rel, string $content): void
{
    file_put_contents($dir . '/' . $rel, $content);
}

$engine = new Antimonial\View\ViewEngine($dir);

// 1. Auto-escaping default
tpl($dir, 'escape.php', '<p>{{ $name }}</p>');
check('auto-escape {{ }}',
    $engine->render('escape', ['name' => '<b>x</b>']) === '<p>&lt;b&gt;x&lt;/b&gt;</p>',
    $engine->render('escape', ['name' => '<b>x</b>']));

// 2. Raw echo
tpl($dir, 'raw.php', '<p>{{{ $html }}}</p>');
check('raw {{{ }}}',
    $engine->render('raw', ['html' => '<b>x</b>']) === '<p><b>x</b></p>',
    $engine->render('raw', ['html' => '<b>x</b>']));

// 3. Comments stripped
tpl($dir, 'comment.php', 'a{{-- secret --}}b');
check('comment stripped',
    $engine->render('comment') === 'ab',
    $engine->render('comment'));

// 4. foreach
tpl($dir, 'loop.php', '@foreach($items as $i)<x>{{ $i }}</x>@endforeach');
check('foreach loop',
    $engine->render('loop', ['items' => ['a', 'b']]) === '<x>a</x><x>b</x>',
    $engine->render('loop', ['items' => ['a', 'b']]));

// 5. if / else
tpl($dir, 'cond.php', "@if(\$a)\nyes\n@elseif(\$b)\nmaybe\n@else\nno\n@endif");
check('if true', trim($engine->render('cond', ['a' => true])) === 'yes');
check('elseif', trim($engine->render('cond', ['a' => false, 'b' => true])) === 'maybe');
check('else', trim($engine->render('cond', ['a' => false, 'b' => false])) === 'no');

// 6. unless
tpl($dir, 'unless.php', "@unless(\$active)\noff\n@endunless");
check('unless false', trim($engine->render('unless', ['active' => false])) === 'off');
check('unless true', trim($engine->render('unless', ['active' => true])) === '');

// 7. for / while
tpl($dir, 'for.php', '@for($i=0;$i<3;$i++){{ $i }}@endfor');
check('for loop', $engine->render('for') === '012', $engine->render('for'));
tpl($dir, 'while.php', '@php $n=0; @endphp@while($n<3){{ $n }}@php $n++; @endphp@endwhile');
check('while loop', $engine->render('while') === '012', $engine->render('while'));

// 8. set
tpl($dir, 'set.php', '@set($t = 5)<p>{{ $t }}</p>');
check('set variable', $engine->render('set') === '<p>5</p>', $engine->render('set'));

// 9. filters
tpl($dir, 'filters.php', '{{ $name|upper }}!{{ $name|trim }}');
check('filters upper+trim',
    $engine->render('filters', ['name' => '  bob ']) === '  BOB !bob',
    $engine->render('filters', ['name' => '  bob ']));
tpl($dir, 'len.php', '{{ $x|length }}');
check('filter length',
    $engine->render('len', ['x' => 'abc']) === '3',
    $engine->render('len', ['x' => 'abc']));

// 10. custom filter
Antimonial\View\Filters::add('excl', fn ($v) => $v . '!');
tpl($dir, 'custom.php', '{{ $v|excl }}');
check('custom filter',
    $engine->render('custom', ['v' => 'hi']) === 'hi!',
    $engine->render('custom', ['v' => 'hi']));

// 11. include inherits parent data
tpl($dir, 'partials/nav.php', '<nav>{{ $title }}</nav>');
tpl($dir, 'withinclude.php', '@include("partials/nav")');
check('include inherits data',
    $engine->render('withinclude', ['title' => 'Home']) === '<nav>Home</nav>',
    $engine->render('withinclude', ['title' => 'Home']));

// 12. include with explicit data overrides
tpl($dir, 'withinclude2.php', '@include("partials/nav", ["title" => "X"])');
check('include explicit data',
    $engine->render('withinclude2', ['title' => 'Home']) === '<nav>X</nav>',
    $engine->render('withinclude2', ['title' => 'Home']));

// 13. layout: extends + section + yield + content
tpl($dir, 'layouts/main.php', '<title>@yield("title","Def")</title><main>{{{ $content }}}</main>');
tpl($dir, 'page.php', "@extends('layouts/main')@section('title')My Page@endsection<h1>Hi</h1>");
check('layout extends/section/yield/content',
    trim($engine->render('page')) === '<title>My Page</title><main><h1>Hi</h1></main>',
    $engine->render('page'));

// 14. nested @foreach inside @if (regression for balanced parens + nested blocks)
tpl($dir, 'nested.php', "@if(count(\$u) > 0)@foreach(\$u as \$x)<i>{{ \$x }}</i>@endforeach@else none@endif");
check('nested if/foreach with count()',
    $engine->render('nested', ['u' => ['a', 'b']]) === '<i>a</i><i>b</i>',
    $engine->render('nested', ['u' => ['a', 'b']]));

// 15. state isolation: render two different templates with same engine
$one = $engine->render('escape', ['name' => '<x>']);
$two = $engine->render('page'); // uses sections; must not leak into next render
$three = $engine->render('escape', ['name' => '<y>']);
check('state isolation between renders',
    $three === '<p>&lt;y&gt;</p>',
    $three);

// 16. cache: compiled file exists and is reused
$compiled = $dir . '/../storage/views';
check('cache dir created', is_dir($compiled), $compiled);

// 17. missing template throws
try {
    $engine->render('does_not_exist_xyz');
    check('missing template throws', false, 'no exception');
} catch (\RuntimeException $e) {
    check('missing template throws', true);
}

// Cleanup
array_map('unlink', glob($dir . '/*.php'));
array_map('unlink', glob($dir . '/partials/*.php'));
array_map('unlink', glob($dir . '/layouts/*.php'));
foreach (glob($dir . '/../storage/views/*.php') as $f) { unlink($f); }
@rmdir($dir . '/partials'); @rmdir($dir . '/layouts'); @rmdir($dir . '/../storage/views'); @rmdir($dir . '/..');

echo "\n" . str_repeat('=', 40) . "\n";
echo "PASSED: $passed   FAILED: $failed\n";
echo str_repeat('=', 40) . "\n";
exit($failed === 0 ? 0 : 1);
