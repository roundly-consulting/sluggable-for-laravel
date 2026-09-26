<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Schema\Drivers;

use Illuminate\Database\Connection;
use RoundlyConsulting\Sluggable\DataTransferObjects\PlannedIndex;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;

/**
 * Engine-specific DDL for slug unique indexes.
 */
interface SlugIndexDriver
{
    /**
     * @param  list<string>  $locales  validated, collision-free
     * @return list<PlannedIndex>
     */
    public function plan(SlugIndexSpec $spec, Connection $connection, array $locales): array;
}
