<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Schema\Drivers;

use Illuminate\Database\Connection;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugIndexSpec;

/**
 * SQLite: expression / partial unique indexes; the locale expression is byte-identical to the
 * grammar's `json_extract("slug", '$."en"')`.
 */
final class SqliteSlugIndexes implements SlugIndexDriver
{
    public function plan(SlugIndexSpec $spec, Connection $connection, array $locales): array
    {
        return ExpressionIndexes::plan($spec, $connection, $locales, static fn (string $path): string => $path);
    }
}
