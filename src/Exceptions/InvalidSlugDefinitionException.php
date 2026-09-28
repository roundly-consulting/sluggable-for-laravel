<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Exceptions;

/**
 * A slug definition (or a config value / call feeding one) is unusable. Thrown at resolve time so a
 * misconfiguration fails the first test instead of a production save.
 */
final class InvalidSlugDefinitionException extends SluggableException
{
    public static function duplicateColumn(string $model, string $column): self
    {
        return new self("[{$model}] declares the slug column [{$column}] more than once.");
    }

    public static function multipleRouteKeys(string $model): self
    {
        return new self("[{$model}] marks more than one slug column as routeKey().");
    }

    public static function scopedWithoutScope(string $column): self
    {
        return new self("The slug [{$column}] is scoped but names no scope column or closure.");
    }

    public static function missingSuffixGenerator(string $column): self
    {
        return new self("The slug [{$column}] uses the custom suffix strategy without suffixUsing().");
    }

    public static function invalidSeparator(string $separator): self
    {
        return new self(sprintf('The slug separator [%s] is invalid: use 1-3 characters from "-", ".", "_", "~".', mb_substr($separator, 0, 10)));
    }

    public static function maxLengthTooSmall(string $column, int $maxLength): self
    {
        return new self("The slug [{$column}] has maxLength {$maxLength}, which is below 8 or leaves no room next to its prefix/suffix.");
    }

    public static function invalidIdentifier(string $identifier, string $what = 'identifier'): self
    {
        return new self(sprintf('The %s [%s] is not a valid SQL identifier.', $what, mb_substr($identifier, 0, 64)));
    }

    public static function localeOptionOnStringColumn(string $column, string $option): self
    {
        return new self("The locale option [{$option}] applies to locale-map slugs only, but [{$column}] is a string column.");
    }

    public static function unqueryableCast(string $column, string $cast): self
    {
        return new self("The slug column [{$column}] uses the [{$cast}] cast, which cannot be queried.");
    }

    public static function selfReferencingSource(string $column): self
    {
        return new self("The slug [{$column}] uses itself as its source.");
    }

    public static function conflictingSources(string $model): self
    {
        return new self("[{$model}] declares #[Slug] attributes AND overrides slugOptions(); the override wins — remove one.");
    }

    public static function localeCollision(string $first, string $second): self
    {
        return new self("The locales [{$first}] and [{$second}] normalise to the same index name.");
    }

    public static function historyTableMissing(string $table): self
    {
        return new self("Slug history is enabled but the [{$table}] table does not exist: publish the migrations with `php artisan vendor:publish --tag=sluggable-migrations` and migrate.");
    }

    public static function historyKeyTypeMismatch(string $model, string $modelKeyType, string $configured): self
    {
        return new self("[{$model}] has a [{$modelKeyType}] primary key but sluggable.key_type is [{$configured}]; slug history cannot store its id.");
    }

    public static function localeMapContractMissing(string $model, string $column): self
    {
        return new self("[{$model}] reports [{$column}] as a locale map but does not implement ProvidesLocaleMaps (e.g. `implements Translatable`); sluggable would otherwise treat it as a string column.");
    }

    public static function missingScopeValue(string $column): self
    {
        return new self("The UniqueSlug rule has no value for the scope [{$column}]: pass where(), within(), ignore(\$model) or scopeFromInput().");
    }

    public static function unknownColumn(string $model, string $column): self
    {
        return new self("[{$model}] has no slug definition for the column [{$column}].");
    }

    public static function missingContract(string $model): self
    {
        return new self("[{$model}] uses HasSlug but does not implement the Sluggable contract.");
    }

    public static function notSluggableModel(string $model): self
    {
        return new self(sprintf('[%s] is not an Eloquent model (class or morph alias) implementing the Sluggable contract.', mb_substr($model, 0, 128)));
    }

    public static function invalidOption(string $option, string $reason): self
    {
        return new self("The slug option [{$option}] is invalid: {$reason}.");
    }
}
