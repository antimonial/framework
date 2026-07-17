# Changelog

All notable changes to the Antimonial framework are documented here.

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
