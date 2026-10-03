# Changelog

All notable changes to `sluggable-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
