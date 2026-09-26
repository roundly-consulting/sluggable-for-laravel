<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Enums\GenerationTrigger;
use RoundlyConsulting\Sluggable\Enums\RegenerationMode;

/**
 * Ask for one generation pass over a model.
 */
final readonly class SlugGenerationRequest
{
    /**
     * @param  list<string>|null  $columns  null = every definition
     * @param  list<string>|null  $locales  null = the definition's target locales
     * @param  bool  $force  bypass locks (lockWhen / locked)
     * @param  RegenerationMode|null  $mode  for Manual/Command triggers; null = recompute (no churn)
     */
    public function __construct(
        public Model $model,
        public GenerationTrigger $trigger,
        public ?array $columns = null,
        public ?array $locales = null,
        public bool $force = false,
        public ?RegenerationMode $mode = null,
    ) {}
}
