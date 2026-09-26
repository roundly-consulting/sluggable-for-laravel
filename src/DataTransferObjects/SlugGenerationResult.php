<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

/**
 * The changes one generation pass made to a model's slug attributes.
 */
final readonly class SlugGenerationResult
{
    /** @param list<SlugChange> $changes */
    public function __construct(
        public array $changes = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }
}
