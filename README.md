# Antimonial

A minimal, expressive PHP framework for server-rendered apps — no JavaScript dependencies by default.

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

## Directory Structure

```
framework/
├── public/             # Entry point
│   └── index.php
├── src/
│   ├── Core/           # App, Autoloader, Config, ErrorHandler, Helpers, Exceptions
│   ├── Http/           # Request, Response
│   ├── Routing/        # Router, Route
│   ├── Controller/     # Base Controller with validation
│   ├── View/           # PHP view renderer with layout support
│   ├── Middleware/     # MiddlewareInterface
│   ├── Database/       # Connection, QueryBuilder, DB, Raw
│   └── Model/          # Base Model with CRUD
├── composer.json
└── README.md
```

## License

MIT
