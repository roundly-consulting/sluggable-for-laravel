<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class SlugLockedException extends SluggableException
{
    public static function forColumn(Model $model, string $column, ?string $locale): self
    {
        $where = $locale === null ? "[{$column}]" : "[{$column}.{$locale}]";

        return new self(sprintf(
            'The slug %s on [%s] is locked and cannot be changed; wrap the change in Slugs::unlocked() to override.',
            $where,
            $model::class,
        ));
    }
}
