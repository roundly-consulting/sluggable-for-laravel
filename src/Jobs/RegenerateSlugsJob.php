<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use RoundlyConsulting\Sluggable\Actions\RegenerateSlugsAction;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerateSlugsData;

/**
 * One chunk of a queued `sluggable:regenerate` run. Serialises ids and the run options — never
 * models.
 */
final class RegenerateSlugsJob implements ShouldQueue
{
    use Queueable;

    /** @param list<int|string> $keys */
    public function __construct(
        public readonly RegenerateSlugsData $data,
        public readonly array $keys,
    ) {}

    public function handle(RegenerateSlugsAction $action): void
    {
        $action->execute($this->data->forKeys($this->keys));
    }
}
