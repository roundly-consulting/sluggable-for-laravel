<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class SlugLockedException extends SluggableException
{
    public static function forColumn(Model $model, string $column, ?string $locale): self
    {
        $message = trans('sluggable::validation.locked', [
            'attribute' => $locale === null ? $column : "{$column}.{$locale}",
            'model' => $model::class,
        ]);

        return new self(is_string($message) ? $message : 'sluggable::validation.locked');
    }
}
