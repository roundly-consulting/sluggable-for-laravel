<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

/**
 * What a regeneration run did: rows scanned, rows changed, and a sample of the changes (with
 * the key of the row each belongs to).
 */
final readonly class RegenerationReport
{
    /** @param list<RegeneratedSlug> $samples */
    public function __construct(
        public int $scanned,
        public int $changed,
        public array $samples = [],
    ) {}
}
