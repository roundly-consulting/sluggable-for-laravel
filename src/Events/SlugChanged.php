<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Enums\ChangeReason;

/**
 * A persisted slug value changed — once per column (and locale). Dispatched after the surrounding
 * transaction commits, never for a rolled-back save.
 */
final readonly class SlugChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Model $model,
        public string $column,
        public ?string $locale,
        public ?string $previous,
        public string $current,
        public ChangeReason $reason,
    ) {}
}
