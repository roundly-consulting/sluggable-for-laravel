<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * Scan existing rows for slugs a unique index would reject.
 */
final readonly class DuplicateScan
{
    /** @param class-string<Model> $modelClass */
    public function __construct(
        public string $modelClass,
        public ?string $column = null,
        public ?string $locale = null,
    ) {}
}
