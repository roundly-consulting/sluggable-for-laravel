<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\PHPStan;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Validation\Rule;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Definitions\SlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Sluggable\Rules\UniqueSlug;

/**
 * Static-analysis fixture (never executed): uses HasSlug so PHPStan analyses the trait, and calls
 * the macros / typed closures a consumer writes, which must pass level 7 as consumers write them.
 *
 * @property string|null $slug
 * @property bool $published
 * @property int $tenant_id
 */
final class AnalysedArticle extends Model implements Sluggable
{
    use HasSlug;

    public function slugOptions(): SlugOptions
    {
        return SlugOptions::make(
            SlugDefinition::for('slug')
                ->from(fn (AnalysedArticle $article, ?string $locale): string => (string) $article->getKey())
                ->lockWhen(fn (AnalysedArticle $article): bool => $article->published)
                ->skipWhen(fn (AnalysedArticle $article): bool => $article->tenant_id === 0)
                ->uniqueWhere(fn (Builder $query, AnalysedArticle $article): Builder => $query->where('tenant_id', $article->tenant_id))
                ->fallbackLocale(fn (): string => (string) config('app.fallback_locale', 'en'))
                ->routeKey(),
        );
    }

    public static function schema(Blueprint $table): void
    {
        $table->slug();
        $table->localizedSlug('title_slug')->nullable();
        $table->uniqueSlug('slug', ['tenant_id']);
    }

    public static function rule(): UniqueSlug
    {
        $found = self::query()->whereSlug('x')->whereSlugIn(['a', 'b'])->orderBySlugPreference('x')->first();

        return Rule::uniqueSlug(self::class)->ignore($found);
    }
}
