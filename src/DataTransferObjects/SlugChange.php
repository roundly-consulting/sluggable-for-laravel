<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use RoundlyConsulting\Sluggable\Enums\ChangeReason;

/**
 * One slug value that changed: which column (and locale), from what, to what, and why.
 */
final readonly class SlugChange
{
    public function __construct(
        public string $column,
        public ?string $locale,
        public ?string $previous,
        public string $current,
        public ChangeReason $reason,
    ) {}
}
