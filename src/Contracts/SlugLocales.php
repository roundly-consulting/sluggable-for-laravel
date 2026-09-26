<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Contracts;

/**
 * The host's locale set as sluggable sees it. Bound with bindIf() to a config-driven default;
 * translatable-for-laravel rebinds it to its own supported-locales source, and a host binding wins
 * over both.
 */
interface SlugLocales
{
    /** @return list<string> */
    public function supported(): array;

    public function fallback(): ?string;

    public function current(): string;
}
