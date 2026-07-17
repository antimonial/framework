# Changelog

All notable changes to the Antimonial framework are documented here.

## [0.9.3] - 2026-07-17

### Changed
- **PHPStan analysis now passes at `--level=max` with zero `@phpstan-ignore` comments** — all 25 errors and 4 ignores eliminated through proper type fixes:
  - `Request` constructor changed from `private` to `protected` + `@phpstan-consistent-constructor` annotation so `new static()` in `fromGlobals()` is type-safe
  - `Filters::escape()`/`raw()` rewritten with `match` narrowing instead of `(string)` casts
  - `Compiler` directive callbacks: closures now typed `(array $m): string` with `@var array<int, string> $m` so capture-group access is `string`-safe (11 errors)
  - `View::render()` signature changed `array<string, mixed>|null $capturedVars` → `array<string, mixed> $capturedVars = []` (matches native type)
  - `ViewEngine` `evaluate()` state-restoration logic refactored: `array_pop` results routed through `popEvalState(): ?array` helper to avoid null/always-false false positives
  - `App::loadRoutes()` `require` path resolved via `ROOT_PATH` stub constant (no more `require.fileNotFound`)
- **phpstan.neon** — `scanFiles` replaced with `bootstrapFiles` so `ROOT_PATH` stub constant is actually defined during analysis
- **Tests** — added `tests/_stubs/constants.php` (defines `ROOT_PATH`) and `tests/_stubs/app/Routes/web.php` (stub routes file for static analysis)

## [0.9.2] - 2026-07-17

### Added
- **`Router::patch()`** — HTTP PATCH verb method on Router
- **`Response::download()`** — Stream a file as an attachment (sets Content-Disposition, MIME, Content-Length)
- **`Response::file()`** — Serve a file inline with detected MIME type

### Removed
- **`toSql()` from QueryBuilder** — The method was a duplicate of `getSql()`. Canonical name is now `getSql()`.

## [0.9.1] - 2026-07-17

### Documentation
- **Complete docblock audit** — All 26 source files reviewed and updated:
  - 21 methods that had no docblock now documented
  - ~95 methods completed with `@param`, `@return`, `@throws` tags
  - 7 duplicate docblock blocks merged (Router::group, Controller::view, Connection::select/insert, QueryBuilder::get)
  - Corrected `@return $this` → `@return static` (14 methods across QueryBuilder, Route)
  - Fixed `@csrf` tag-parsing bug in Csrf.php class docblock
  - Updated QueryBuilder class docblock from "~230 lines" to "~1,050 lines"
- **README reduced** from ~400 lines to ~120 lines — concise overview with links to Wiki
- **CHANGELOG expanded** — v0.7.1 entry completed (was incomplete)

## [0.9.0] - 2026-07-17

### Added
- **Test suite expanded from 27 to 166 tests** — 7 new test files:
  - `QueryBuilderTest` (~40 tests): SQL compilation, where/join/order/group/limit, aggregates, paginate, security, reset
  - `RouterTest` (~25 tests): registration, exact/parameterized dispatch, regex constraints, groups, named routes, middleware
  - `RequestTest` (~15 tests): URI, method, query/post/input/header/cookie/file, attributes, method override
  - `ResponseTest` (~12 tests): status, headers, body, json, redirect, fluent API
  - `ConfigTest` (~6 tests): load, get, dot notation nested, default values, missing file
  - `HelpersTest` (~8 tests): `e()`, `env()` type-casting, `redirect()`
  - `ModelTest` (~11 tests): table name guessing, find/all/insert/update/delete, timestamps, query builder delegation

### Documentation
- Added docblock to `orWhereRaw()` (only public method missing one)
- README updated with: paginate(), named routes, Routing section, validate() return behavior
- Skeleton README updated with named routes mention

## [0.8.1] - 2026-07-17

### Fixed
- `validate()` now returns only the fields that have validation rules, instead of the entire request input

### Added
- `QueryBuilder::paginate(int $perPage, int $page)` — returns `{items, total, perPage, currentPage, totalPages}` using `clone` to avoid builder state loss
- Named routes with `->name('name')` fluent method and global `route()` helper for reverse URL generation

## [0.8.0] - 2026-07-17

### Added
- `@switch`/`@case`/`@default`/`@break` directives (rewritten to `@if`/`@elseif`/`@else` internally)
- `@end` universal closer — closes the most recently opened block
- Filter pipes in directive expressions (`@if($x|length > 0)`, `@for($i = 0; $i < $items|count; $i++)`)
- `@csrf` directive emits a hidden CSRF token field
- Atomic write (temp file + rename) for compiled templates prevents partial reads
- `opcache_invalidate()` on recompile ensures cache freshness
- `Compiler::VERSION` constant embedded in cache path for automatic cache busting

### Changed
- Compiler rewritten to use `token_get_all()` — separates PHP tokens from template HTML, making directive compilation fully nesting-safe
- ViewEngine uses a state stack in `evaluate()` for correct nesting when evaluating compiled templates
- README expanded with new directive examples

### Fixed
- Nested `@if` inside `@if` no longer breaks — each directive is compiled independently
- `@empty` now closes with `@endempty` (not `@endif`)
- `@isset` now closes with `@endisset` (not `@endif`)

## [0.7.1] - 2026-06-23

### Added
- `orWhereRaw()` method on QueryBuilder for raw WHERE clauses with OR logic
- Project controller for the skeleton application's project listing feature
