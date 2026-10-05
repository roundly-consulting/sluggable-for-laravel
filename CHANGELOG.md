# Changelog

All notable changes to `sluggable-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

- A `ManualSlugPolicy::Strict` manual slug longer than the definition's `maxLength` now throws
  `SlugGenerationException` instead of being stored: such a slug could never route-bind and overflows
  the column on MySQL/PostgreSQL.
- `avoidHistoricalSlugs()` on a scoped definition (`uniqueWithin()` / `uniqueWhere()`) now only avoids
  slugs retired inside the same scope, so one tenant renaming a page no longer pushes another tenant's
  new page to `about-2`.
- A custom collision suffix (`suffixUsing()`) is now validated like custom slugger output, so it can no
  longer put `/`, `?`, whitespace or other URL syntax into a slug; it throws `SlugGenerationException`
  instead.
- Closure prefixes/suffixes and collision suffixes that leave no room for the slug body now throw
  `SlugGenerationException` instead of producing a slug longer than `maxLength`.
- On MySQL/MariaDB, `SlugIndexes` now sizes each locale-map generated column to the definition's
  `maxLength` (never below 255) instead of a fixed `varchar(255)`, so a longer localized slug no longer
  errors or truncates in its index; above 768 characters (the utf8mb4 index limit) planning throws
  `InvalidSlugDefinitionException`. `SlugIndexSpec` and `SlugIndexSpec::localeMap()` take an optional
  `maxLength`, which `SlugIndexes::specsFor()` fills in.

## 1.0.2 - 2026-10-04

### Fixed

- `SlugLockedException` now reads its message from the `sluggable::validation.locked` translation, so
  the locked-slug error is shown in the current locale (English and Slovak ship).

## 1.0.1 - 2026-10-04

### Changed

- Maintenance: `composer.json` `homepage` and `support.docs` now point to the documentation site.

### Fixed

- Slovak (`sk`) translations now ship alongside English for every language file.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Automatic slugs for Eloquent with the `HasSlug` trait — zero config generates `slug` from
  `name`, matching `Str::slug()` for ordinary input by default.
- Any number of slug columns per model, configured fluently (`SlugOptions` / `SlugDefinition`)
  or with the `#[Slug]` attribute.
- Multi-language slugs in `json` / `jsonb` locale-map columns, each locale transliterated in
  its own language.
- Update, manual-value and lock policies (`UpdatePolicy`, `ManualSlugPolicy`, `lockWhen()`),
  plus `Slugs::withoutGeneration()` for imports.
- Scoped, per-locale uniqueness backed by engine-native unique indexes on PostgreSQL, SQLite
  and MySQL / MariaDB (`SlugIndexes`), with automatic retry on concurrent-write collisions.
- Query scopes and finders: `whereSlug()`, `whereSlugInAnyLocale()`, `findBySlug()` and
  `findBySlugOrFail()`.
- Locale-aware route model binding, including scoped bindings and an optional primary-key
  fallback.
- Optional slug history with automatic 301 redirects from retired slugs.
- `ValidSlug` and `UniqueSlug` validation rules.
- A `Slugs` facade (`slugify()`, `generate()`, `apply()`, `recompute()`, `regenerate()`) and
  `SlugChanged`, `SlugCollisionRetried` and `SlugsRegenerated` events.
- `Slugs::model(Post::class)` for class-wide work: `regenerate()` (mode / dry run / history /
  columns / locales / chunk / force → `RegenerationReport`), `queueRegeneration()` (one
  `RegenerateSlugsJob` per chunk, via the new `QueueSlugRegenerationAction`), `duplicates()`,
  `indexes()`, `findInHistory()` (optionally `within` your own query) and `options()`.
  `model()` accepts a class name or a morph alias and refuses anything not sluggable.
- `sluggable:regenerate`, `sluggable:indexes` and `sluggable:duplicates` Artisan commands for
  backfills and adopting the package in an existing app.
