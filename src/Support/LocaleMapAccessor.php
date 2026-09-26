<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use JsonException;
use RoundlyConsulting\Sluggable\Contracts\ProvidesLocaleMaps;
use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;

/**
 * Reads and writes locale-map attributes (`{"en": "…", "sk": "…"}`) either through the model's
 * {@see ProvidesLocaleMaps} contract or straight from the raw json attribute.
 *
 * Raw on purpose: every fleet translation trait overrides `getAttribute()` to return the
 * CURRENT-locale string, which would feed one locale's value into every locale's slug.
 */
final class LocaleMapAccessor
{
    private const JSON_CASTS = ['array', 'json', 'json:unicode', 'object', 'collection'];

    /**
     * How an attribute is stored. A model that exposes `isLocaleMapAttribute()` without the
     * contract is refused rather than silently read as a string.
     */
    public static function storageOf(Model $model, string $key): SlugStorage
    {
        if ($model instanceof ProvidesLocaleMaps) {
            if ($model->isLocaleMapAttribute($key)) {
                return SlugStorage::LocaleMap;
            }
        } elseif (method_exists($model, 'isLocaleMapAttribute') && $model->isLocaleMapAttribute($key) === true) {
            throw InvalidSlugDefinitionException::localeMapContractMissing($model::class, $key);
        }

        return self::hasJsonCast($model, $key) ? SlugStorage::LocaleMap : SlugStorage::String;
    }

    public static function isLocaleMap(Model $model, string $key): bool
    {
        return self::storageOf($model, $key) === SlugStorage::LocaleMap;
    }

    /** @return array<string, string> blank / null / non-scalar values dropped */
    public static function read(Model $model, string $key): array
    {
        if ($model instanceof ProvidesLocaleMaps && $model->isLocaleMapAttribute($key)) {
            return self::clean($model->getLocaleMap($key));
        }

        return self::decode($model->getAttributes()[$key] ?? null);
    }

    /**
     * The map as it was loaded from the database — always the raw original, for every storage
     * kind, because the contract has no "original" accessor.
     *
     * @return array<string, string>
     */
    public static function original(Model $model, string $key): array
    {
        return self::decode($model->getRawOriginal($key));
    }

    /**
     * Replace the map. Only the locales sluggable generated differ from what was read, so every
     * other key survives byte-for-byte.
     *
     * @param  array<string, string>  $map
     */
    public static function write(Model $model, string $key, array $map): void
    {
        if ($model instanceof ProvidesLocaleMaps && $model->isLocaleMapAttribute($key)) {
            $model->setLocaleMap($key, $map);

            return;
        }

        if (self::hasJsonCast($model, $key)) {
            $model->setAttribute($key, $map);

            return;
        }

        $model->setAttribute($key, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    public static function decode(mixed $raw): array
    {
        if (is_string($raw)) {
            if ($raw === '') {
                return [];
            }

            try {
                $raw = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [];
            }
        }

        return is_array($raw) ? self::clean($raw) : [];
    }

    /**
     * @param  array<mixed>  $map
     * @return array<string, string>
     */
    public static function clean(array $map): array
    {
        $clean = [];

        foreach ($map as $locale => $value) {
            if (! is_string($locale) || $locale === '' || ! (is_string($value) || is_int($value) || is_float($value))) {
                continue;
            }

            $value = (string) $value;

            if (trim($value) !== '') {
                $clean[$locale] = $value;
            }
        }

        return $clean;
    }

    private static function hasJsonCast(Model $model, string $key): bool
    {
        $cast = $model->getCasts()[$key] ?? null;

        if (! is_string($cast)) {
            return false;
        }

        $normalized = strtolower($cast);

        return in_array($normalized, self::JSON_CASTS, true)
            || str_starts_with($cast, AsArrayObject::class)
            || str_starts_with($cast, AsCollection::class);
    }
}
