<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Actions;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;
use RoundlyConsulting\Sluggable\DataTransferObjects\RegenerateSlugsData;
use RoundlyConsulting\Sluggable\Jobs\RegenerateSlugsJob;

/**
 * Split a regeneration run into one {@see RegenerateSlugsJob} per chunk of primary keys (every
 * row, incl. trashed and rows a global scope hides) and dispatch them. Jobs carry keys and the
 * run options — never models. Returns the number of jobs dispatched.
 */
final readonly class QueueSlugRegenerationAction
{
    public function __construct(
        private Dispatcher $bus,
    ) {}

    public function execute(RegenerateSlugsData $data): int
    {
        $prototype = new ($data->modelClass);
        $keyName = $prototype->getKeyName();
        $jobs = 0;

        $query = $prototype->newQueryWithoutScopes()->toBase()->select($keyName);

        if ($data->keys !== null) {
            $query->whereIn($keyName, $data->keys);
        }

        $query->chunkById($data->chunk, function (Collection $rows) use ($data, $keyName, &$jobs): void {
            $keys = [];

            foreach ($rows as $row) {
                $key = ((array) $row)[$keyName] ?? null;

                if (is_int($key) || is_string($key)) {
                    $keys[] = $key;
                }
            }

            $this->bus->dispatch(new RegenerateSlugsJob($data, $keys));
            $jobs++;
        }, $keyName);

        return $jobs;
    }
}
