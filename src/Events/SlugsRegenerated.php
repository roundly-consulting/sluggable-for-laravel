<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Events;

use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerationReport;

/**
 * A regeneration run (command or queued chunk) finished.
 */
final readonly class SlugsRegenerated
{
    /** @param class-string $modelClass */
    public function __construct(
        public string $modelClass,
        public RegenerationReport $report,
    ) {}
}
