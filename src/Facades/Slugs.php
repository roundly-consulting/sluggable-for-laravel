<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Sluggable\SlugManager;

/**
 * Slugs for Eloquent. Deterministic and backed by the host's own database, so there is no fake:
 * assert on the rows, and use `Bus::fake()` around `Slugs::model()->queueRegeneration()`.
 *
 * @method static string slugify(string $text, ?string $language = null, ?string $separator = null)
 * @method static string generate(\Illuminate\Database\Eloquent\Model $model, ?string $column = null, ?string $locale = null)
 * @method static \RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationResult apply(\Illuminate\Database\Eloquent\Model $model, list<string>|null $columns = null, list<string>|null $locales = null)
 * @method static \RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationResult recompute(\Illuminate\Database\Eloquent\Model $model, list<string>|null $columns = null, list<string>|null $locales = null)
 * @method static \Illuminate\Database\Eloquent\Model regenerate(\Illuminate\Database\Eloquent\Model $model, list<string>|null $columns = null, list<string>|null $locales = null)
 * @method static \RoundlyConsulting\Sluggable\ModelSlugs model(string $model)
 * @method static mixed withoutGeneration(\Closure $callback)
 * @method static mixed unlocked(\Closure $callback)
 * @method static bool isGenerationEnabled()
 * @method static bool isUnlocked()
 * @method static \RoundlyConsulting\Sluggable\Contracts\SlugLocales locales()
 *
 * @see SlugManager
 */
final class Slugs extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SlugManager::class;
    }
}
