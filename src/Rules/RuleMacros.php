<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;

/**
 * `Rule::uniqueSlug(Model::class)` and `Rule::validSlug(Model::class)`.
 */
final class RuleMacros
{
    public static function register(): void
    {
        if (! Rule::hasMacro('uniqueSlug')) {
            Rule::macro('uniqueSlug', fn (string $modelClass, ?string $column = null): UniqueSlug => UniqueSlug::for(RuleMacros::model($modelClass), $column));
        }

        if (! Rule::hasMacro('validSlug')) {
            Rule::macro('validSlug', fn (string $modelClass, ?string $column = null): ValidSlug => ValidSlug::for(RuleMacros::model($modelClass), $column));
        }
    }

    /** @return class-string<Model> */
    public static function model(string $class): string
    {
        if (! is_subclass_of($class, Model::class)) {
            throw InvalidSlugDefinitionException::invalidOption('modelClass', "[{$class}] is not an Eloquent model");
        }

        return $class;
    }
}
