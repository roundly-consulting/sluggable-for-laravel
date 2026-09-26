<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

final class UnsupportedDriverException extends SluggableException
{
    public static function forIndexes(string $driver): self
    {
        return new self("Slug index DDL is not supported on the [{$driver}] driver; create the unique index by hand.");
    }
}
