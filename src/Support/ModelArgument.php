<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;

/**
 * Resolves a command's `{model}` argument — an FQCN or a morph alias — to a sluggable model class.
 */
final class ModelArgument
{
    /** @return class-string<Model>|null */
    public static function resolve(mixed $argument): ?string
    {
        if (! is_string($argument) || $argument === '') {
            return null;
        }

        $class = Relation::getMorphedModel($argument) ?? ltrim($argument, '\\');

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Sluggable::class)) {
            return null;
        }

        return $class;
    }
}
