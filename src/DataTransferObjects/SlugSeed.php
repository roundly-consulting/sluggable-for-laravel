<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

/**
 * The body a written slug was built from — kept so a collision retry re-suffixes the base
 * (`chair-3`) instead of suffixing the suffix (`chair-2-2`).
 */
final readonly class SlugSeed
{
    public function __construct(
        public string $body,
        public bool $withAffixes,
    ) {}
}
