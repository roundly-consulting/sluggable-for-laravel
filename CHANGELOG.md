# Changelog

All notable changes to `sluggable-for-laravel` will be documented in this file.

## 1.0.0 — unreleased

- Initial release: single-language (string) and multi-language (json/jsonb locale-map) slugs for
  Eloquent with any number of slug columns per model (`HasSlug`, `SlugDefinition`, `#[Slug]`).
- Formatting pipeline byte-identical to `Str::slug()` by default, with separators, length/word
  caps, transliteration languages, dictionary, unicode mode, reserved words, affixes and custom
  sluggers.
- Uniqueness none/global/scoped (columns or closure), trashed-aware, per-locale or across locales;
  bounded batched probing; engine-native unique indexes (`SlugIndexes`, Blueprint macros) with a
  savepoint-safe collision retry.
- Update, manual and lock policies; restore and scope-change re-checks.
- Query scopes, finders and locale-aware route model binding (implicit, custom field, scoped,
  `withTrashed()`), opt-in primary-key fallback.
- Opt-in slug history with automatic 301 redirects, reclaim and pruning.
- `UniqueSlug` / `ValidSlug` rules, `sluggable:regenerate`, `sluggable:indexes`,
  `sluggable:duplicates`, `SlugChanged` / `SlugCollisionRetried` / `SlugsRegenerated` events.
