# Changelog

All notable changes to the Antimonial framework are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.17.0] - 2026-07-18

### Added

- **File logging.** New `Antimonial\Core\Logger` static facade with `write(string $level, string $message, string $directory)` that appends timestamped, single-line entries to a per-day `YYYY-MM-DD.log` file in a caller-supplied directory (created recursively if missing). Accepts the standard RFC 5424 levels and throws on an unknown level.
- `ErrorHandler` now file-logs uncaught exceptions via `Logger` (best-effort, in addition to `error_log`). The log directory is set with `ErrorHandler::setLogDirectory(string $directory)` or falls back to the `app.log_dir` config value, then to `storage/logs` under `ROOT_PATH`.

## [0.16.0] - 2026-07-18

### Added

- **CSRF header fallback.** `CsrfMiddleware` now accepts the token from the `X-CSRF-TOKEN` or `X-XSRF-TOKEN` request header when the `_token` form field is absent, in addition to the existing `_token` POST field — so AJAX clients can send the token without a form body. The form field still takes precedence.

## [0.15.0] - 2026-07-18

### Added

- **Form re-population.** New global helpers `old(string $key, mixed $default = '')` and `errors()` read the validation errors and submitted input flashed by a failed form submission, for use inside views (e.g. `<input value="<?= e(old('email')) ?>">`).
- `App` now handles `ValidationException` contextually: JSON / XHR clients (or when sessions are disabled) receive the errors as a `422` JSON body; browser form submissions with sessions enabled get the errors and input flashed and a `303` redirect back to the `Referer` (defaulting to `/`) so the form can be re-populated.

## [0.14.0] - 2026-07-18

### Added

- **Authentication.** New `Antimonial\Security\Auth` static facade: `useModel(string $modelClass)` points it at your user `Model`; `attempt(array $credentials)` verifies a `password` column via `password_verify()` (bcrypt) and logs the user in on success; `login(object $user)` / `logout()` store or clear the user id in the session and regenerate the session id (session-fixation protection); `check()`, `id()`, and `user()` expose the current auth state. Auth reuses the framework's opt-in `Session` layer — no separate auth service to wire up.
- `Antimonial\Middleware\AuthMiddleware` blocks unauthenticated requests, redirecting browsers to `/login` (302) and returning `401` JSON for `Accept: application/json` / `X-Requested-With: XMLHttpRequest` clients.
- `Antimonial\Middleware\GuestMiddleware` is the inverse: it redirects already-authenticated browsers to `/` (302) and returns `403` JSON for XHR/JSON clients, for use on login/register pages.

## [0.13.0] - 2026-07-18

### Added

- **Database migrations.** New `Antimonial\Database\Migrator` accepts a `Connection` and a migrations directory path in its constructor. `run(): array` executes all pending migrations (lexically sorted filenames) and returns the filenames it ran; `rollback(): array` reverts the most recent batch and returns the names reverted. Applied migrations are tracked in a `migrations` table (`id`, `migration`, `batch`, `ran_at`) created automatically if missing. Each migration file returns an object implementing the new `Antimonial\Database\Migration` interface (`up(Connection $db): void` / `down(Connection $db): void`) and runs raw SQL through the `Connection` — there is no schema builder / column DSL.
- `Connection::getDriver()` exposes the configured driver name (e.g. `mysql`, `sqlite`) for driver-specific DDL.

## [0.12.0] - 2026-07-18

### Added

- **File uploads.** New `Antimonial\Http\UploadedFile` wraps a single `$_FILES` entry with `isValid()`, `error()`, `errorMessage()`, `size()`, `clientName()`, `clientExtension()`, `mimeType()` (detected from the temp file via `mime_content_type()`, never the client-supplied type), and `store(string $directory, string $name)` (creates the directory if missing, moves via `move_uploaded_file()`, returns the final path). The filename is always caller-supplied — no implicit naming convention.
- `Request::file(string $key)` now returns an `UploadedFile` instance (or `null` if the key is absent) instead of the raw `$_FILES` array.
- `Controller::validate()` now supports file rules: `file`, `image`, `mimes:ext1,ext2`, and `max_size:kilobytes`. A field carrying any file rule is validated against its `UploadedFile` (via `Request::file()`), never through the string-based path. Absence is left to the `required` rule, consistent with the existing rule-composition convention.

### Changed

- `Request::file()` return type changed from `?array` to `?UploadedFile`. Any code reading `$_FILES` data directly from `Request::file()` must now use the `UploadedFile` API (e.g. `->clientName()` instead of `['name']`).

## [0.11.1] - 2026-07-17

### Changed

- **Codebase simplification and idiom upgrade (no API or behavior changes).** Internal cleanup only; all 166 tests, PHPStan `--level=max`, and Pint continue to pass.
  - `Response`: replaced `finfo_*` with native `mime_content_type()`; extracted `serveFile()` to deduplicate `download()`/`file()`; moved security headers to a `SECURITY_HEADERS` class constant.
  - `Router`: `matchParameters()` now uses `array_filter(..., ARRAY_FILTER_USE_KEY)`; group prefix concatenation uses a plain loop; extracted `matchResult()` to remove duplicated dispatch arrays.
  - `Request`: query-string stripping uses `parse_url(..., PHP_URL_PATH)`.
  - `Config`: single-pass dot-notation lookup via `strtok()` (removed the redundant private `dotGet()` method).
  - `QueryBuilder`: shared `ALLOWED_OPERATORS` constant for `join()`/`having()`.
  - `Compiler`: shared `ESC` constant for HTML-escaping.
  - `Connection`: config normalized once and typed as a shape, removing redundant re-casts in `connect()`.
  - `Helpers::route()`: `strtr()` single-pass replacement instead of a `str_replace` loop.
  - `Session::forget()`: dropped an unused loop variable.
  - `Controller::applyRule()`: extracted `passesLength()` to remove duplicated min/max logic.
  - `Filters`: removed a redundant `is_string` cast.
  - `App::run()`: extracted `notFoundResponse()`/`validationErrorResponse()`; removed an unreachable JSON fallback.

## [0.11.0] - 2026-07-17

### Changed

- **`View` no longer assumes a view directory.** Removed the silent fallback to `ROOT_PATH.'/app/Views'`. If `View::setViewPath()` was never called, rendering now throws `RuntimeException` telling you to configure it. This removes the last implicit directory convention in the backend — you declare paths instead of the framework guessing them (consistent with the explicit `$table` requirement on models).

### BREAKING CHANGE

- Applications must call `View::setViewPath($path)` before rendering. The skeleton already does this in `public/index.php`; existing apps need to add that one call in their bootstrap.

## [0.10.0] - 2026-07-17

### Changed

- **`Model` no longer guesses the table name.** Removed `Model::guessTableName()`. A model without a declared `protected string $table` now throws `RuntimeException` at construction. You must declare the table explicitly — the framework does not infer it from the class name (which also avoided wrong pluralization like `Category` → `categorys`).
- `Model` docblock corrected: it now states plainly that table names are never inferred.

### BREAKING CHANGE

- Every model must declare a `protected string $table` property. Models that relied on auto-detection need a `$table` declaration (e.g. `protected string $table = 'users';`).

## [0.9.4] - 2026-07-17

### Fixed

- Removed the hardcoded `"version"` field from `composer.json`. That field caused Packagist to skip tags `v0.9.1`, `v0.9.2` and `v0.9.3` (their `composer.json` carried a stale `0.9.0` version, so Composer ignored them).

## [0.9.3] - 2026-07-17

### Changed

- PHPStan analysis now passes at `--level=max` with zero `@phpstan-ignore` comments; all 25 errors and 4 ignores eliminated through proper type fixes:
  - `Request` constructor changed from `private` to `protected` with `@phpstan-consistent-constructor` so `new static()` in `fromGlobals()` is type-safe
  - `Filters::escape()`/`raw()` rewritten with `match` narrowing instead of `(string)` casts
  - `Compiler` directive callbacks typed `(array $m): string` with `@var array<int, string> $m`
  - `View::render()` signature changed `array<string, mixed>|null $capturedVars` to `array<string, mixed> $capturedVars = []`
  - `ViewEngine::evaluate()` state-restoration refactored through a `popEvalState(): ?array` helper
  - `App::loadRoutes()` `require` path resolved via `ROOT_PATH` stub constant
- `phpstan.neon` updated: `scanFiles` replaced with `bootstrapFiles` so the `ROOT_PATH` stub is defined during analysis
- Added `tests/_stubs/constants.php` (defines `ROOT_PATH`) and `tests/_stubs/app/Routes/web.php`

## [0.9.2] - 2026-07-17

### Added

- `Router::patch()` HTTP PATCH verb method
- `Response::download()` streams a file as an attachment (Content-Disposition, MIME, Content-Length)
- `Response::file()` serves a file inline with detected MIME type

### Removed

- `QueryBuilder::toSql()` removed; it was a duplicate of `getSql()`. Canonical name is now `getSql()`

## [0.9.1] - 2026-07-17

### Documentation

- Complete PHPDoc audit across all 26 source files: every method now has `@param`, `@return` and `@throws`
- 14 chain methods changed `@return \` to `@return static` for better type inference
- Fixed the `@csrf` tag-parsing bug in `Csrf.php` class docblock
- GitHub Wiki published with 10 pages covering the full framework
- README reduced to a ~120 line concise overview
- Pint code-style fixes across 18 files

## [0.9.0] - 2026-07-17

### Added

- Test suite expanded from 27 to 166 tests across 7 new test files (QueryBuilder, Router, Request, Response, Config, Helpers, Model)
- `QueryBuilder::paginate()` using clone-based count + data fetch
- Named routes via `Route::name()` and the global `route()` helper

### Fixed

- `validate()` now returns only validated fields instead of the entire request input
- Corrected `ModelTest` table-name assertions for `guessTableName`
- `test_group_by_having` now uses a valid column name

## [0.8.1] - 2026-07-17

### Fixed

- `validate()` now returns only the fields that have validation rules, instead of the entire request input

### Added

- `QueryBuilder::paginate(int $perPage, int $page)` returning `{items, total, perPage, currentPage, totalPages}` using `clone` to avoid builder state loss
- Named routes with `->name('name')` fluent method and the global `route()` helper for reverse URL generation

## [0.8.0] - 2026-07-17

### Added

- `@switch`/`@case`/`@default`/`@break` directives (rewritten to `@if`/`@elseif`/`@else` internally)
- `@end` universal closer for the most recently opened block
- Filter pipes in directive expressions (`@if($x|length > 0)`, `@for($i = 0; $i < $items|count; $i++)`)
- `@csrf` directive emitting a hidden CSRF token field
- Atomic write (temp file + rename) for compiled templates
- `opcache_invalidate()` on recompile for cache freshness
- `Compiler::VERSION` constant embedded in the cache path for automatic cache busting

### Changed

- Compiler rewritten to use `token_get_all()` for fully nesting-safe directive compilation
- `ViewEngine` uses a state stack in `evaluate()` for correct nesting

### Fixed

- Nested `@if` inside `@if` no longer breaks
- `@empty` now closes with `@endempty` and `@isset` with `@endisset`

## [0.7.1] - 2026-07-17

### Fixed

- Fixed a PHP parse error in `Session::forget()` caused by an invalid union type
- Ran Laravel Pint across the whole codebase so the Pint `--test` CI step passes
- Resolved 30 PHPStan level-max errors by tightening types in `Connection`, `DB`, `QueryBuilder`, `Session` and `Filters`

## [0.7.0] - 2026-07-17

### Added

- GitHub Actions workflow running Pint, PHPStan (level max) and PHPUnit across PHP 8.1, 8.2 and 8.3
- `pint.json` with the Laravel preset and strict typing rules
- `tests/_stubs/constants.php` so PHPStan can resolve runtime constants

### Changed

- Tightened type safety across Core, Database, Http, Routing, Session and View without breaking the public API
- Defensive guards in `App`/`Router` handler resolution

## [0.6.0] - 2026-07-16

### Added

- `Antimonial\Session\Session` with `get`/`put`/`pull`/`has`/`forget`/`flush`, one-request `flash` data and `regenerate()` (opt-in via `Config::get('app.session')`)
- `Antimonial\Security\Csrf` with a session-stored token and timing-safe `hash_equals` verification
- `Antimonial\Security\TokenMismatchException` typed exception
- `Antimonial\Middleware\CsrfMiddleware` verifying `POST`/`PUT`/`DELETE`/`PATCH` and returning `419` on mismatch
- `@csrf` view directive emitting a hidden `_token` input
- `tests/session_test.php` standalone harness

## [0.5.1] - 2026-07-16

### Fixed

- Robust conditional compilation following Blade's atomic-directive model; `@else`/`@elseif`/`@endif`/`@endunless`/`@endisset`/`@endempty` are now standalone directives replaced in the iteration loop
- `@unless`/`@isset`/`@empty` now close with their own `@end*` keyword

### Added

- `tests/engine_test.php` zero-dependency test harness (22 assertions) for the template engine

## [0.5.0] - 2026-07-16

### Added

- Built-in, expressive template engine (~860 LOC), auto-registered on first render
- Auto-escaping by default (`{{ }}` is XSS-safe; `{{{ }}}` is raw)
- Directives: `@if`/`@elseif`/`@else`/`@endif`, `@unless`, `@foreach`, `@for`, `@while`, `@set`, `@php`
- Twig-style filters (`|upper`, `|trim`, `|length`, `|date`, `|json`, `|raw`, `|escape`) extensible via `Filters::add()`
- Layouts via `@extends` + `@section`/`@yield`; includes via `@include`
- Compiled and cached templates in `storage/views/`, recompiled only when the source changes

## [0.4.0] - 2026-07-16

### Added

- View variable capture: variables set in a child view (e.g. `$title`) propagate to the layout via `View::renderWithLayout()`
- Custom 404 error view: `App::run()` renders `errors/404` with the `layouts/main` layout when no route matches, falling back to a hardcoded string

## [0.3.0] - 2026-07-16

### Added

- Regex route parameters via `{param:regex}` syntax (`{id}` matches one segment, `{path:.+}` matches many)
- Group middleware: `Router::group()` accepts a third parameter with middleware applied to all routes in the group

## [0.2.0] - 2026-07-16

### Added

- Nested grouped `WHERE` conditions via `Closure`: both `where()` and `orWhere()` accept a closure to build parenthesized groups

## [0.1.2] - 2026-07-16

### Fixed

- `Response::json()` now preserves an explicitly set status code instead of overriding it with the default `200`

## [0.1.1] - 2026-07-16

### Fixed

- `DB::connection()` referenced `Config` without importing `Antimonial\Core\Config`, causing a "Class not found" error on any database query. All DB operations now work.

## [0.1.0] - 2026-06-24

### Added

- Initial stable milestone: a PHP MVC framework where what you read is what runs (PHP >= 8.1)
- Core (`App`/`Config`/`DotEnv`/`ErrorHandler`/helpers), HTTP (`Request`/`Response`), Routing, Database (`Connection`/`QueryBuilder`/`DB`/`Raw`), MVC (`Controller`/`Model`) and middleware support
