<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class SlugAlreadyTakenException extends SluggableException
{
    public static function forManual(Model $model, string $column, ?string $locale, string $value): self
    {
        $where = $locale === null ? "[{$column}]" : "[{$column}.{$locale}]";

        return new self(sprintf('The slug [%s] for %s on [%s] is already taken.', $value, $where, $model::class));
    }
}
