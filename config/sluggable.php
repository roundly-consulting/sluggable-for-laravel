<?php

declare(strict_types=1);

use RoundlyConsulting\Sluggable\Models\SlugHistory;

return [

    /*
    |--------------------------------------------------------------------------
    | Definition defaults
    |--------------------------------------------------------------------------
    |
    | Every option a SlugDefinition leaves unset falls back to these values.
    | A model with no definition at all gets one slug column (`column`)
    | generated from one source attribute (`source`). With the formatting
    | defaults below, generated slugs are byte-identical to Str::slug().
    |
    */

    'defaults' => [
        'column' => 'slug',
        'source' => 'name',
        'separator' => '-',
        'max_length' => 255,
        'max_words' => null,

        // Transliteration language for STRING slugs; locale-map slugs use each locale.
        'language' => env('SLUGGABLE_LANGUAGE', 'en'),

        'dictionary' => ['@' => 'at'],
        'lowercase' => true,
        'unicode' => false,

        // none | global | scoped
        'uniqueness' => 'global',

        // per_locale | across_locales
        'locale_uniqueness' => 'per_locale',

        // Soft-deleted rows keep their slug reserved (safe restores, matches a plain unique index).
        'include_trashed' => true,

        'on_create' => true,

        // never | if_empty | when_source_changes | always
        'on_update' => env('SLUGGABLE_ON_UPDATE', 'if_empty'),

        // normalize | verbatim | strict
        'manual' => 'normalize',

        // random | skip | fail
        'empty_source' => 'random',

        // sequential | random  (custom needs a definition-level suffixUsing())
        'suffix' => 'sequential',
        'suffix_start' => 2,
        'random_length' => 8,

        // source | supported | current
        'target_locales' => 'source',

        // none | fallback | any
        'locale_fallback' => 'any',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserved slugs
    |--------------------------------------------------------------------------
    |
    | Values no slug may take (compared case-insensitively on the final value),
    | e.g. route segments such as `create`, `edit` or `api`.
    |
    */

    'reserved' => [],

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Used when no other package (e.g. translatable-for-laravel) binds the
    | SlugLocales contract. `supported` null = app.locale + app.fallback_locale.
    |
    */

    'locales' => [
        'supported' => null,
        'fallback' => env('SLUGGABLE_FALLBACK_LOCALE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route binding
    |--------------------------------------------------------------------------
    |
    | `key_fallback`: try the primary key after a slug misses (only for values
    | shaped like the model's key). Per-definition bindByKeyFallback() wins.
    |
    */

    'binding' => [
        'key_fallback' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Bounds on the work a single slug may cost: source characters considered,
    | sequential candidates probed (in batches), then random attempts.
    |
    */

    'limits' => [
        'max_source_length' => 2000,
        'sequential_probes' => 50,
        'probe_batch' => 10,
        'random_attempts' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency
    |--------------------------------------------------------------------------
    |
    | Retries after the database's unique index rejects a slug another writer
    | took in the meantime (0 disables).
    |
    */

    'concurrency' => [
        'retries' => env('SLUGGABLE_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slug history
    |--------------------------------------------------------------------------
    |
    | Opt-in record of retired slugs, used to 301-redirect old URLs to the
    | current one. Requires the published `sluggable-migrations`.
    |
    */

    'history' => [
        'enabled' => env('SLUGGABLE_HISTORY', false),
        'redirect' => env('SLUGGABLE_HISTORY_REDIRECT', true),
        'redirect_status' => 301,
        'avoid_reuse' => false,
        'table' => env('SLUGGABLE_HISTORY_TABLE', 'slug_history'),
        'model' => SlugHistory::class,
        'prune_after_days' => env('SLUGGABLE_HISTORY_PRUNE_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Key type
    |--------------------------------------------------------------------------
    |
    | Primary-key type of the SLUGGED models (bigint | uuid | ulid); decides the
    | slug_history morph id column.
    |
    */

    'key_type' => env('SLUGGABLE_KEY_TYPE', 'bigint'),

];
