<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Actions\GenerateSlugsAction;
use RoundlyConsulting\Sluggable\Actions\ResolveSlugFromHistoryAction;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\DataTransferObjects\HistoryLookup;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugFormat;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationRequest;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugGenerationResult;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugSeed;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugOptions;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\GenerationTrigger;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Exceptions\SlugGenerationException;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;
use RoundlyConsulting\Sluggable\Support\SlugGenerator;
use RoundlyConsulting\Sluggable\Support\Slugger;
use RoundlyConsulting\Sluggable\Support\SlugState;

/**
 * The `Slugs` facade root: pure slugify, on-demand generation, generation/lock toggles, history
 * lookups and introspection. Toggles are try/finally depth counters — nestable, exception-safe,
 * and never left set between Octane requests.
 */
final class SlugManager
{
    private int $withoutGeneration = 0;

    private int $unlocked = 0;

    /** Slugify text with the configured defaults (optionally another language/separator). */
    public function slugify(string $text, ?string $language = null, ?string $separator = null): string
    {
        $separator ??= SluggableConfig::separator();

        if (preg_match('/^[-._~]{1,3}$/', $separator) !== 1) {
            throw InvalidSlugDefinitionException::invalidSeparator($separator);
        }

        return Slugger::slugify($text, new SlugFormat(
            separator: $separator,
            maxLength: SluggableConfig::maxLength(),
            maxWords: SluggableConfig::maxWords(),
            dictionary: SluggableConfig::dictionary(),
            lowercase: SluggableConfig::lowercase(),
            unicode: SluggableConfig::unicode(),
            maxSourceLength: SluggableConfig::maxSourceLength(),
        ), $language ?? SluggableConfig::language());
    }

    /**
     * A unique slug for a model's column (and locale) as it would be generated now. Sets nothing,
     * saves nothing.
     */
    public function generate(Model $model, ?string $column = null, ?string $locale = null): string
    {
        $definition = $this->options($model)->get($column);

        if ($definition->isLocalized()) {
            $locale = IdentifierGuard::locale($locale ?? $this->locales()->current());
        } elseif ($locale !== null) {
            throw InvalidSlugDefinitionException::localeOptionOnStringColumn($definition->column, 'locale');
        }

        $generator = app(SlugGenerator::class);
        $seed = new SlugSeed($generator->body($model, $definition, $locale), true);

        if ($seed->body === '') {
            if ($definition->emptySource !== EmptySourcePolicy::Random) {
                throw SlugGenerationException::emptySource($model, $definition->column, $locale);
            }

            $seed = new SlugSeed(Slugger::random($definition->randomLength, $definition->format->lowercase), true);
        }

        return $generator->unique($generator->context($model, $definition, $locale), $definition, $locale, $seed);
    }

    /**
     * Run generation now — e.g. before `saveQuietly()` or a bulk importer, which fire no model
     * events. The upcoming save treats the values as sluggable's own.
     *
     * @param  list<string>|null  $columns
     * @param  list<string>|null  $locales
     */
    public function apply(Model $model, ?array $columns = null, ?array $locales = null): SlugGenerationResult
    {
        return app(GenerateSlugsAction::class)->execute(new SlugGenerationRequest(
            model: $model,
            trigger: $model->exists ? GenerationTrigger::Updating : GenerationTrigger::Creating,
            columns: $columns,
            locales: $locales,
        ));
    }

    /**
     * Recompute and save (history and events as for any save).
     *
     * @param  list<string>|null  $columns
     * @param  list<string>|null  $locales
     */
    public function regenerate(Model $model, ?array $columns = null, ?array $locales = null): Model
    {
        if (! $model instanceof Sluggable) {
            throw InvalidSlugDefinitionException::missingContract($model::class);
        }

        $model->regenerateSlugs($columns, $locales)->save();

        return $model;
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function withoutGeneration(Closure $callback): mixed
    {
        $this->withoutGeneration++;

        try {
            return $callback();
        } finally {
            $this->withoutGeneration--;
        }
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function unlocked(Closure $callback): mixed
    {
        $this->unlocked++;

        try {
            return $callback();
        } finally {
            $this->unlocked--;
        }
    }

    public function isGenerationEnabled(): bool
    {
        return $this->withoutGeneration === 0;
    }

    public function isUnlocked(): bool
    {
        return $this->unlocked > 0;
    }

    /**
     * The model that retired a slug, through its default query (global scopes applied).
     *
     * @param  class-string<Model>  $modelClass
     */
    public function findInHistory(string $modelClass, string $slug, ?string $column = null, ?string $locale = null): ?Model
    {
        $model = new $modelClass;
        $definition = $this->options($model)->get($column);

        return app(ResolveSlugFromHistoryAction::class)->execute(new HistoryLookup(
            modelClass: $modelClass,
            slug: $slug,
            column: $definition->column,
            locale: $definition->isLocalized() ? ($locale === null ? null : IdentifierGuard::locale($locale)) : '',
            scopeQuery: $model->newQuery(),
        ));
    }

    /**
     * The resolved slug definitions of a model class (or instance).
     *
     * @param  class-string<Model>|Model  $model
     */
    public function options(string|Model $model): ResolvedSlugOptions
    {
        return app(SlugState::class)->options(is_string($model) ? new $model : $model);
    }

    public function locales(): SlugLocales
    {
        return app(SlugLocales::class);
    }
}
