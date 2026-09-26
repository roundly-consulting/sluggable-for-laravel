<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Contracts;

/**
 * A model that stores some attributes as locale maps (`{"en": "…", "sk": "…"}`) and owns their
 * (de)serialisation. translatable-for-laravel's `Translatable` contract extends this, so sluggable
 * reads and writes locale maps without ever referencing translatable.
 */
interface ProvidesLocaleMaps
{
    public function isLocaleMapAttribute(string $key): bool;

    /** @return array<string, string> blank values already dropped */
    public function getLocaleMap(string $key): array;

    /** @param array<string, string> $map */
    public function setLocaleMap(string $key, array $map): static;
}
