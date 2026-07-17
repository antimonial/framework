# Changelog

All notable changes to the Antimonial framework are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

- Initial stable milestone: a minimal, expressive PHP MVC framework for server-rendered apps (no JS by default, PHP >= 8.1)
- Core (`App`/`Config`/`DotEnv`/`ErrorHandler`/helpers), HTTP (`Request`/`Response`), Routing, Database (`Connection`/`QueryBuilder`/`DB`/`Raw`), MVC (`Controller`/`Model`) and middleware support
