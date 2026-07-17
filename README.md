# Antimonial

A PHP MVC framework where what you read is what runs.

## Requirements

- PHP >= 8.1

## Installation

```bash
composer require antimonial/framework
```

## Quick Start

```php
// public/index.php
define('ROOT_PATH', __DIR__ . '/..');
require ROOT_PATH . '/vendor/autoload.php';

Antimonial\Core\Config::load('app');
Antimonial\Core\Config::load('database');

$app = new Antimonial\Core\App();
$app->run();
```

```php
// app/Routes/web.php
$router->get('/', [App\Controllers\HomeController::class, 'index']);
$router->get('/posts/{slug}', fn (Request $r) => "Post: {$r->get('slug')}");
```

```php
// app/Controllers/HomeController.php
class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        $users = DB::table('users')->where('active', true)->get();
        return $this->view('home', ['users' => $users]);
    }
}
```

```php
// app/Views/home.php
<h1>Users</h1>
@foreach($users as $user)
  <p>{{ $user->name }}</p>
@endforeach
```

## What's Included

| Component | Description |
|-----------|-------------|
| **Routing** | GET/POST/PUT/PATCH/DELETE, params, groups, named routes, middleware |
| **Controllers** | View rendering, JSON responses, redirects, validation (8 rules) |
| **Query Builder** | Fluent SQL builder — select, where (all variants), join, aggregates, paginate, insert/update/delete, transactions |
| **Model** | Base model with CRUD, timestamps, table name guessing |
| **Response** | HTML, JSON, redirects, cookies, file downloads (`download()`, `file()`) |
| **Template Engine** | Blade-style directives, layouts (`@extends`/`@section`/`@yield`), pipe filters, auto-escaping, compiled cache |
| **Session** | Native PHP session wrapper with flash data (opt-in) |
| **CSRF** | Token generation, verification, middleware (opt-in) |
| **Config** | Dot-notation config loader from `app/Config/` |
| **.env** | Minimal `.env` file parser |
| **Error Handling** | Debug mode with stack traces, production error pages |
| **Helpers** | `view()`, `redirect()`, `e()`, `env()`, `route()`, `config()`, `dd()`, `ddj()` |

## Security

- Auto-escaped output (`{{ }}`) prevents XSS; only `{{{ }}}` emits raw HTML
- SQL identifiers validated against a strict whitelist; values always bound via prepared statements
- CSRF protection with timing-safe `hash_equals()` comparison (opt-in)
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`) sent by default

## Quality & Static Analysis

The framework is analyzed with **PHPStan at `--level=max`** with **zero `@phpstan-ignore` comments** — all types are resolved through proper fixes (typed closures, `match` narrowing, `@phpstan-consistent-constructor` contracts), not suppression.

```bash
vendor/bin/phpstan analyse --level=max src/
```

The `phpstan.neon` uses a `bootstrapFiles` stub (`tests/_stubs/constants.php`) that defines `ROOT_PATH` for analysis, since the constant is set by the front controller at runtime.

## Documentation

Full documentation is available on the [Wiki](https://github.com/antimonial/framework/wiki).

## Running an application

```bash
composer create-project antimonial/antimonial my-app
cd my-app
```

## License

MIT
