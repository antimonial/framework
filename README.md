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
| **Controllers** | View rendering, JSON responses, redirects, validation (string, file, and database rules) |
| **Query Builder** | Fluent SQL builder — select, where (all variants), join, aggregates, paginate, insert/update/delete, transactions |
| **Model** | Base model with CRUD, timestamps, explicit table name (no guessing) |
| **Response** | HTML, JSON, redirects, cookies, file downloads (`download()`, `file()`) |
| **Template Engine** | Blade-style directives, layouts (`@extends`/`@section`/`@yield`), pipe filters, auto-escaping, compiled cache |
| **Session** | Native PHP session wrapper with flash data (opt-in) |
| **CSRF** | Token generation, verification, middleware (opt-in) |
| **Authentication** | `Auth` facade (`attempt`/`login`/`logout`/`check`/`id`/`user`) + `AuthMiddleware`/`GuestMiddleware` (opt-in) |
| **File Uploads** | `UploadedFile` wrapper, `Request::file()`, validation rules `file`/`image`/`mimes`/`max_size` |
| **Database Migrations** | `Migrator` + `Migration` interface (run/rollback, tracked in a `migrations` table) |
| **Form Re-population** | `old()` / `errors()` helpers + flash-and-redirect on validation failure |
| **File Logging** | `Logger` facade + `ErrorHandler` integration |
| **Config** | Dot-notation config loader from `app/Config/` |
| **.env** | Minimal `.env` file parser |
| **Error Handling** | Debug mode with stack traces, production error pages |
| **Helpers** | `view()`, `redirect()`, `e()`, `env()`, `route()`, `config()`, `dd()`, `ddj()`, `old()`, `errors()` |

## Security

- Auto-escaped output (`{{ }}`) prevents XSS; only `{{{ }}}` emits raw HTML
- SQL identifiers validated against a strict whitelist; values always bound via prepared statements
- CSRF protection with timing-safe `hash_equals()` comparison (opt-in)
- Security headers (`X-Content-Type-Options`, `X-Frame-Options`) sent by default
- File uploads validated by real content for `image` (reads the binary's MIME type); `mimes` checks the client-declared extension only
- Authentication stores the user id in the session and regenerates the session id on login/logout (session-fixation protection)

## Usage Examples

### File uploads

```php
// In a controller
$data = $this->validate($request, [
    'avatar' => 'required|image|max_size:2048', // image = real content check
    'doc'    => 'required|mimes:pdf,txt',        // mimes = extension check only
]);

$path = $request->file('avatar')->store('storage/uploads');
```

### Database migrations

```php
$migrator = new \Antimonial\Database\Migrator($connection, __DIR__ . '/migrations');

// 2026_01_01_000001_create_users.php
return new class implements \Antimonial\Database\Migration {
    public function up(\Antimonial\Database\Connection $db): void
    {
        $db->execute('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');
    }
    public function down(\Antimonial\Database\Connection $db): void
    {
        $db->execute('DROP TABLE users');
    }
};

$ran = $migrator->run();        // applies pending migrations
$reverted = $migrator->rollback(); // reverts the most recent batch
```

### Authentication

```php
Auth::useModel(User::class);

if (Auth::attempt(['email' => $request->post('email'), 'password' => $request->post('password')])) {
    return $this->redirect('/dashboard');
}

// Protect a route with middleware
$router->get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(\Antimonial\Middleware\AuthMiddleware::class);
```

### Form re-population

```php
// In a view, after a failed validation (errors + input are flashed)
<input value="<?= e(old('email')) ?>">
<?php foreach (errors()['email'] ?? [] as $msg): ?>
  <p class="error"><?= e($msg) ?></p>
<?php endforeach; ?>
```

### CSRF header fallback

```js
// AJAX clients may send the token in a header instead of a form field
fetch('/posts', {
  method: 'POST',
  headers: { 'X-CSRF-TOKEN': token },
  body: JSON.stringify({ title: 'Hi' }),
});
```

### File logging

```php
\Antimonial\Core\Logger::write('error', 'Payment failed', __DIR__ . '/storage/logs');
// Uncaught exceptions are logged automatically by ErrorHandler:
\Antimonial\Core\ErrorHandler::setLogDirectory(__DIR__ . '/storage/logs');
```

### Database validation rules

```php
$data = $this->validate($request, [
    'email' => 'required|email|unique:users,email', // column defaults to field name
    'role_id' => 'exists:roles',                     // exists:roles,role_id
]);
```

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
