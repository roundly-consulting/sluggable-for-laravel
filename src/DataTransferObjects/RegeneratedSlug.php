<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

/**
 * One changed slug of a regeneration run, tied to its row.
 */
final readonly class RegeneratedSlug
{
    public function __construct(
        public int|string $key,
        public SlugChange $change,
    ) {}
}
