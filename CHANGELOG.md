# Changelog

All notable changes to the Antimonial framework are documented here.

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

### Fixed
- `orWhereRaw()` added for raw WHERE clauses with OR logic
- Search controller compatibility
