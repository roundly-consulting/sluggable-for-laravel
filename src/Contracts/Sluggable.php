<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Contracts;

use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

/**
 * Implemented by every model that uses HasSlug (the trait supplies all methods).
 */
interface Sluggable
{
    /** Map key used by slugMap() for a string (non-locale) column; fails the locale regex on purpose. */
    public const SINGLE_KEY = '*';

    public function slugOptions(): SlugOptions;

    /** @return array<string, string> locale => slug (locale map), or [SINGLE_KEY => slug] / [] (string column) */
    public function slugMap(?string $column = null): array;

    public function currentSlug(?string $column = null): ?string;

    public function slugFor(string $locale, ?string $column = null): ?string;

    /**
     * @param  list<string>|null  $columns
     * @param  list<string>|null  $locales
     */
    public function regenerateSlugs(?array $columns = null, ?array $locales = null): static;
}
