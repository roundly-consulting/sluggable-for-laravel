<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

final class SlugGenerationException extends SluggableException
{
    public static function exhausted(Model $model, string $column, ?string $locale): self
    {
        return new self(sprintf('Could not find a free slug for %s on [%s]; every probed candidate is taken.', self::where($column, $locale), $model::class));
    }

    public static function emptySource(Model $model, string $column, ?string $locale): self
    {
        return new self(sprintf('The sources of %s on [%s] produced an empty slug.', self::where($column, $locale), $model::class));
    }

    public static function retriesExhausted(Model $model, string $column, UniqueConstraintViolationException $previous): self
    {
        return new self(sprintf('The slug [%s] on [%s] kept colliding in the database after every retry.', $column, $model::class), 0, $previous);
    }

    public static function constraintMismatch(Model $model, string $column, ?string $locale, UniqueConstraintViolationException $previous): self
    {
        return new self(sprintf(
            'The database rejected %s on [%s] although the slug definition considers it free: the unique index disagrees with the definition. Run `php artisan sluggable:indexes "%s" --dry-run` and compare.',
            self::where($column, $locale),
            $model::class,
            $model::class,
        ), 0, $previous);
    }

    public static function invalidCustomOutput(string $output): self
    {
        return new self(sprintf('The custom slugger returned [%s], which is not a URL-safe slug.', mb_substr($output, 0, 80)));
    }

    public static function unsafeValue(Model $model, string $column, ?string $locale): self
    {
        return new self(sprintf('The slug given for %s on [%s] contains whitespace, a control character, "/", "?", "#" or "%%".', self::where($column, $locale), $model::class));
    }

    private static function where(string $column, ?string $locale): string
    {
        return $locale === null ? "[{$column}]" : "[{$column}.{$locale}]";
    }
}
