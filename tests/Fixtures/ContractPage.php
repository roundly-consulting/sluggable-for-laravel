<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\ProvidesLocaleMaps;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

/**
 * Stands in for translatable-for-laravel: no casts, the model owns its locale maps through the
 * ProvidesLocaleMaps contract, and getAttributeValue() returns the current-locale string.
 */
class ContractPage extends Model implements ProvidesLocaleMaps, Sluggable
{
    use HasSlug;

    /** @var list<array{string, array<string, string>}> */
    public static array $writes = [];

    protected $table = 'contract_pages';

    protected $guarded = [];

    public function isLocaleMapAttribute(string $key): bool
    {
        return in_array($key, ['name', 'slug'], true);
    }

    public function getLocaleMap(string $key): array
    {
        $raw = $this->getAttributes()[$key] ?? null;
        $map = is_string($raw) ? json_decode($raw, true) : [];

        return array_filter(is_array($map) ? $map : [], static fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    public function setLocaleMap(string $key, array $map): static
    {
        static::$writes[] = [$key, $map];
        $this->attributes[$key] = json_encode($map, JSON_UNESCAPED_UNICODE);

        return $this;
    }

    public function setAttribute($key, $value): mixed
    {
        if ($this->isLocaleMapAttribute($key) && is_array($value)) {
            return $this->setLocaleMap($key, $value);
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttributeValue($key): mixed
    {
        if ($this->isLocaleMapAttribute($key)) {
            return $this->getLocaleMap($key)[app()->getLocale()] ?? null;
        }

        return parent::getAttributeValue($key);
    }

    public function slugOptions(): SlugOptions
    {
        return Configurable::for(static::class) ?? SlugOptions::make(SlugDefinition::for('slug')->from('name')->routeKey());
    }
}
