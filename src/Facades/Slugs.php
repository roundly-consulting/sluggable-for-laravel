<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Sluggable\SlugManager;

/**
 * @method static string slugify(string $text, ?string $language = null, ?string $separator = null)
 * @method static string generate(\Illuminate\Database\Eloquent\Model $model, ?string $column = null, ?string $locale = null)
 * @method static \RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationResult apply(\Illuminate\Database\Eloquent\Model $model, list<string>|null $columns = null, list<string>|null $locales = null)
 * @method static \Illuminate\Database\Eloquent\Model regenerate(\Illuminate\Database\Eloquent\Model $model, list<string>|null $columns = null, list<string>|null $locales = null)
 * @method static mixed withoutGeneration(\Closure $callback)
 * @method static mixed unlocked(\Closure $callback)
 * @method static bool isGenerationEnabled()
 * @method static bool isUnlocked()
 * @method static \Illuminate\Database\Eloquent\Model|null findInHistory(string $modelClass, string $slug, ?string $column = null, ?string $locale = null)
 * @method static \RoundlyConsulting\Sluggable\Definitions\ResolvedSlugOptions options(string|\Illuminate\Database\Eloquent\Model $model)
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
