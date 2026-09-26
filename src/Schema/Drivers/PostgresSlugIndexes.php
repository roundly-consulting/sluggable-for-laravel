<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Schema\Drivers;

use Illuminate\Database\Connection;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;

/**
 * Postgres: plain / partial unique indexes; locale maps use the expression `("slug"->>'en')` —
 * exactly what the query grammar emits for `slug->en`, so lookups can use the index too.
 */
final class PostgresSlugIndexes implements SlugIndexDriver
{
    public function plan(SlugIndexSpec $spec, Connection $connection, array $locales): array
    {
        return ExpressionIndexes::plan($spec, $connection, $locales, static fn (string $path): string => '('.$path.')');
    }
}
