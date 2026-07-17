# Antimonial

A minimal, expressive PHP framework for server-rendered apps.

## Requirements

- PHP >= 8.1

## Installation

```bash
composer require antimonial/framework
```

## Quick Start

### Entry point (`public/index.php`)

```php
<?php
define('ROOT_PATH', __DIR__ . '/..');
require ROOT_PATH . '/vendor/autoload.php';

Antimonial\Core\Config::load('app');
Antimonial\Core\Config::load('database');
Antimonial\Core\ErrorHandler::enableDebug(true);

$app = new Antimonial\Core\App();
$app->run();
```

### Routes (`app/Routes/web.php`)

```php
<?php
use Antimonial\Routing\Router;

/** @var Router $router */
$router->get('/', fn () => view('home'));
$router->get('/users/{id}', [App\Controllers\UserController::class, 'show']);
$router->post('/users', [App\Controllers\UserController::class, 'store']);
```

### Controller

```php
<?php
namespace App\Controllers;

use Antimonial\Controller\Controller;
use Antimonial\Http\Request;

class UserController extends Controller
{
    public function show(Request $request)
    {
        $id = $request->get('id');
        return $this->view('users/show', ['id' => $id]);
    }

    public function store(Request $request)
    {
        $data = $this->validate($request, [
            'name'  => 'required|min:2',
            'email' => 'required|email',
        ]);

        return $this->json(['created' => true], 201);
    }
}
```

### Views (`app/Views/home.php`)

```php
<h1>Welcome to Antimonial</h1>
```

### Layouts

```php
return $this->view('users/index', $data, 'layouts/main');
```

The layout receives `$content` with the inner view's output.

### Template Engine

Antimonial ships with a small built-in template engine (inspired by Blade/Twig
but ~200 lines). It is auto-registered on first render — no setup required.
Templates are plain `.php` files in `app/Views/` that compile to cached PHP.

**Auto-escaping by default.** `{{ }}` escapes output (XSS-safe); `{{{ }}}`
emits raw, trusted HTML.

```php
{{-- comment --}}

{{ $name }}              {{-- escaped echo --}}
{{ $name|upper }}        {{-- with filter --}}
{{{ $html }}}            {{-- raw (trusted) echo --}}

@if($count > 0)
  <p>{{ $count }} items</p>
@elseif($count === 1)
  <p>One item</p>
@else
  <p>None</p>
@endif

@unless($active)
  <p>Inactive</p>
@endunless

@foreach($users as $user)
  <li>{{ $user['name'] }} ({{ $user['name']|length }} chars)</li>
@endforeach

@for($i = 0; $i < 10; $i++)
  <span>{{ $i }}</span>
@endfor

@while($n > 0)
  {{ $n-- }}
@endwhile

@isset($user)
  <p>{{ $user['name'] }}</p>
@endisset

@empty($cart)
  <p>Your cart is empty.</p>
@endempty

@set($total = count($users))   {{-- @set($var = expr) --}}
@php echo time(); @endphp      {{-- raw PHP block --}}

@include('partials/nav')        {{-- inherits parent variables --}}
@include('item', ['id' => 1])   {{-- with explicit data --}}
```

**Layouts (inheritance):**

```php
{{-- users/index.php --}}
@extends('layouts/main')

@section('title')
Users
@endsection

<h1>Users</h1>   {{-- becomes $content in the layout --}}
```

```php
{{-- layouts/main.php --}}
<html>
<head><title>@yield('title', 'Default')</title></head>
<body>
  <main>{{{ $content }}}</main>   {{-- raw HTML from the child --}}
</body>
</html>
```

**Built-in filters:** `e`/`escape` (default for `{{ }}`), `raw`, `upper`,
`lower`, `trim`, `length`, `json`, `date`. Add your own:

```php
use Antimonial\View\Filters;

Filters::add('slug', fn ($v) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v))));
```

> The engine caches compiled templates in `app/storage/views/`. Force native
> PHP rendering with `View::setEngine(null)`.

### Query Builder

```php
use Antimonial\Database\DB;

$users = DB::table('users')
    ->select('id', 'name', 'email')
    ->where('active', 1)
    ->orderBy('name')
    ->limit(10)
    ->get();

$count = DB::table('users')->where('role', 'admin')->count();
```

### Config

```php
// app/Config/app.php
return ['timezone' => 'UTC', 'name' => 'My App'];

// Usage:
$timezone = config('app.timezone');
```

### Database Config

The DB facade reads `database.default` and `database.connections.{default}`:

```php
// app/Config/database.php
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'host'     => env('DB_HOST', '127.0.0.1'),
            'port'     => env('DB_PORT', 3306),
            'database' => env('DB_NAME', 'myapp'),
            'username' => env('DB_USER', 'root'),
            'password' => env('DB_PASS', ''),
        ],
    ],
];
```

## Sessions & CSRF

Antimonial ships a tiny, dependency-free session + CSRF layer. It is
**opt-in** — the framework does not start a session for you. Enable it in
`app/Config/app.php`:

```php
return [
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'session'  => true,   // start a native PHP session on each request
];
```

### Session

A thin wrapper over PHP's native `$_SESSION` (no custom storage backend):

```php
use Antimonial\Session\Session;

Session::put('user_id', 42);
$id = Session::get('user_id');          // 42
$id = Session::pull('user_id');         // 42, then removed
Session::has('user_id');                // bool
Session::forget('user_id');
Session::flash('status', 'Saved!');     // readable on the next request only
Session::getFlash('status');
Session::regenerate();                  // new id (call after login)
```

### CSRF

`Csrf` keeps one token in the session. Render it in forms with the `@csrf`
view directive, and protect state-changing routes with `CsrfMiddleware`
(already supported by the Router's global/group middleware).

```php
// In a template:
<form method="post">@csrf
  <input name="name" value="">
</form>

// Register the middleware (global or per group):
$router->middleware(Antimonial\Middleware\CsrfMiddleware::class);
```

The middleware verifies `POST`/`PUT`/`DELETE`/`PATCH` requests against the
session token (timing-safe `hash_equals`) and returns a `419` on mismatch.
`GET`/`HEAD`/`OPTIONS` pass through.

> This covers form safety only. Real authentication (login, users, password
> hashing) is intentionally left to your application — the framework stays
> "no coupled services".

## Security

- **Escape output.** The built-in template engine auto-escapes `{{ }}` echos
  (XSS-safe) and only `{{{ }}}` emits raw, trusted HTML. When writing native
  PHP views (or using `View::setEngine(null)`), output is *not* auto-escaped —
  always escape user-facing data with the `e()` helper:
  ```php
  <?= e($user->name) ?>
  ```
- **Validation.** Controller `validate()` returns a `422` response (JSON with
  an `errors` object) when rules fail.
- **SQL identifiers.** Column/table names passed to `where()`, `orWhere()`,
  `join()`, `orderBy()`, `groupBy()`, `having()` and `increment()`/`decrement()`
  are validated against a strict whitelist (`^[a-zA-Z_][a-zA-Z0-9_]*`,
  optionally qualified as `table.column`). Values are always bound via
  prepared statements. Column
  lists in `select()` and aggregate expressions (`sum()`, `avg()`, …) are
  trusted — only pass developer-controlled values.

## Limitations

- **Configurable database driver.** The `Connection` builds its DSN from a
  `driver` config key (`mysql`, `pgsql`, `sqlite`); switching drivers is a
  config change, not a code change. Only MySQL is exercised by the skeleton.
- **No auto-escaping in views** (see Security above).
- **No ORM, auth, queues or cache** — by design, these are delegated to
  external services. A minimal, dependency-free **Session + CSRF** layer
  *is* included (opt-in, see below) for server-rendered form safety, but
  full auth is still left to your application.
- **Single-use query builder.** Terminal methods (`get`, `insert`, `update`,
  `delete`, `count`, …) reset the builder, so create a fresh instance per query.

## Running an application

This package is the framework *library*. To build an application, use the
official starter skeleton, which ships the front controller, `.htaccess`,
`.env.example` and an `app/` structure:

```bash
composer create-project antimonial/antimonial my-app
cd my-app
```

## Directory Structure

```
framework/
├── src/
│   ├── Core/           # App, Config, ErrorHandler, Helpers, DotEnv, Exceptions
│   ├── Http/           # Request, Response
│   ├── Routing/        # Router, Route
│   ├── Controller/     # Base Controller with validation
│   ├── View/           # View renderer + built-in template engine (Compiler, ViewEngine, Filters)
│   ├── Middleware/     # MiddlewareInterface, CsrfMiddleware
│   ├── Session/        # Opt-in native session wrapper (Session)
│   ├── Security/       # Csrf + TokenMismatchException
│   ├── Database/       # Connection, QueryBuilder, DB, Raw
│   └── Model/          # Base Model with CRUD
├── composer.json
├── README.md
├── LICENSE
└── .gitattributes
```

## License

MIT
