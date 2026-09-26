<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * Where a source attribute lives after its relation path is resolved (no model = a missing relation).
 */
final readonly class SourceTarget
{
    public function __construct(
        public ?Model $model,
        public string $attribute,
    ) {}
}
