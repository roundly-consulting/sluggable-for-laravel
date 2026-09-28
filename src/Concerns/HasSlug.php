<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Concerns;

use Closure;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use ReflectionObject;
use RoundlyConsulting\PackageToolkit\Support\RawExpression;
use RoundlyConsulting\Sluggable\Contracts\Sluggable;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugOptions;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Sluggable\Enums\LocaleFallback;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Exceptions\SlugMovedException;
use RoundlyConsulting\Sluggable\SlugManager;
use RoundlyConsulting\Sluggable\Support\CollisionRetrier;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\JsonPath;
use RoundlyConsulting\Sluggable\Support\LocaleMapAccessor;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;
use RoundlyConsulting\Sluggable\Support\SlugLifecycle;
use RoundlyConsulting\Sluggable\Support\SlugOptionsRegistry;
use RoundlyConsulting\Sluggable\Support\SlugState;

/**
 * Slugs for an Eloquent model: generation hooks, reading helpers, query scopes, finders,
 * locale-aware route binding (with history redirects) and race-safe inserts/updates.
 *
 * The trait stays thin — hooks delegate to container services — and declares no helper that a
 * fleet translation trait already uses (`currentLocale`, `fallbackLocale`, `readMap`, …): every
 * non-API helper is prefixed `sluggable`, so it composes with them without `insteadof`.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-require-implements Sluggable
 */
trait HasSlug
{
    public static function bootHasSlug(): void
    {
        static::creating(static function (Model $model): void {
            app(SlugLifecycle::class)->creating($model);
        });

        static::updating(static function (Model $model): void {
            app(SlugLifecycle::class)->updating($model);
        });

        // `static::restoring()` exists only on SoftDeletes models; registering the raw event is
        // valid on any model and fires only for SoftDeletes restores.
        static::registerModelEvent('restoring', static function (Model $model): void {
            app(SlugLifecycle::class)->restoring($model);
        });

        static::saved(static function (Model $model): void {
            app(SlugLifecycle::class)->saved($model);
        });

        static::deleted(static function (Model $model): void {
            app(SlugLifecycle::class)->deleted($model);
        });
    }

    /** Default definitions: #[Slug] attributes, else `slug` generated from `name` (config). */
    public function slugOptions(): SlugOptions
    {
        return SlugOptionsRegistry::optionsFor($this);
    }

    public function slugDefinition(?string $column = null): ResolvedSlugDefinition
    {
        return $this->sluggableOptions()->get($column);
    }

    /** @return array<string, string> */
    public function slugMap(?string $column = null): array
    {
        $definition = $this->slugDefinition($column);

        if ($definition->isLocalized()) {
            return LocaleMapAccessor::read($this, $definition->column);
        }

        $value = $this->sluggableString($this->getAttribute($definition->column));

        return $value === null ? [] : [Sluggable::SINGLE_KEY => $value];
    }

    /** The slug for the current locale, following the definition's fallback chain. */
    public function currentSlug(?string $column = null): ?string
    {
        $definition = $this->slugDefinition($column);

        if (! $definition->isLocalized()) {
            return $this->sluggableString($this->getAttribute($definition->column));
        }

        $map = LocaleMapAccessor::read($this, $definition->column);

        foreach ($definition->chain(app(SlugLocales::class)) as $locale) {
            if (isset($map[$locale])) {
                return $map[$locale];
            }
        }

        if ($definition->fallback === LocaleFallback::Any && $map !== []) {
            ksort($map);

            return reset($map);
        }

        return null;
    }

    /** The slug for exactly one locale — no fallback. */
    public function slugFor(string $locale, ?string $column = null): ?string
    {
        $definition = $this->slugDefinition($column);

        if (! $definition->isLocalized()) {
            throw InvalidSlugDefinitionException::localeOptionOnStringColumn($definition->column, 'locale');
        }

        return LocaleMapAccessor::read($this, $definition->column)[IdentifierGuard::locale($locale)] ?? null;
    }

    /**
     * Recompute slugs from the current sources (sets attributes; the caller saves). A value whose
     * base is unchanged is kept, so this never churns `-2` into `-3`.
     *
     * @param  list<string>|null  $columns
     * @param  list<string>|null  $locales
     */
    public function regenerateSlugs(?array $columns = null, ?array $locales = null): static
    {
        app(SlugManager::class)->recompute($this, $columns, $locales);

        return $this;
    }

    /**
     * Rows whose slug matches — exact locale when given, else the definition's chain. Adds a
     * predicate only (never an ORDER BY), so count()/paginate()/whereHas() keep working.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereSlug(Builder $query, string $slug, ?string $column = null, ?string $locale = null): void
    {
        $definition = $this->slugDefinition($column);

        $this->sluggableWhere($query, $definition, $this->qualifyColumn($definition->column), [$slug], $locale);
    }

    /**
     * @param  Builder<static>  $query
     * @param  list<string>  $slugs
     */
    public function scopeWhereSlugIn(Builder $query, array $slugs, ?string $column = null, ?string $locale = null): void
    {
        $definition = $this->slugDefinition($column);

        $this->sluggableWhere($query, $definition, $this->qualifyColumn($definition->column), $slugs, $locale);
    }

    /** @param Builder<static> $query */
    public function scopeWhereSlugInAnyLocale(Builder $query, string $slug, ?string $column = null): void
    {
        $definition = $this->slugDefinition($column);
        $qualified = $this->qualifyColumn($definition->column);

        if (! $definition->isLocalized()) {
            $query->where($qualified, $slug);

            return;
        }

        $locales = app(SlugLocales::class);
        $candidates = array_values(array_unique(array_filter([
            $locales->current(),
            $definition->fallbackLocaleFor($locales),
            ...$locales->supported(),
        ])));

        $query->where(function (Builder $group) use ($candidates, $qualified, $slug): void {
            foreach ($candidates as $locale) {
                $group->orWhere(JsonPath::of($qualified, $locale), $slug);
            }
        });
    }

    /**
     * Prefer the row whose CURRENT-locale slug matches, then the fallback locale's — an explicit
     * opt-in (finders and route binding add it themselves).
     *
     * @param  Builder<static>  $query
     */
    public function scopeOrderBySlugPreference(Builder $query, string $slug, ?string $column = null): void
    {
        $definition = $this->slugDefinition($column);

        $this->sluggableOrder($query, $definition, $this->qualifyColumn($definition->column), $slug);
    }

    public static function findBySlug(string $slug, ?string $column = null, ?string $locale = null): ?static
    {
        $query = static::query()->whereSlug($slug, $column, $locale);

        if ($locale === null) {
            $query->orderBySlugPreference($slug, $column);
        }

        return $query->first();
    }

    /** @throws ModelNotFoundException<static> */
    public static function findBySlugOrFail(string $slug, ?string $column = null, ?string $locale = null): static
    {
        return static::findBySlug($slug, $column, $locale)
            ?? throw (new ModelNotFoundException)->setModel(static::class, [$slug]);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutSlugGeneration(Closure $callback): mixed
    {
        return app(SlugManager::class)->withoutGeneration($callback);
    }

    public function getRouteKeyName(): string
    {
        return $this->sluggableOptions()->routeKey()->column ?? parent::getRouteKeyName();
    }

    public function getRouteKey(): mixed
    {
        $routeKey = $this->sluggableOptions()->routeKey();

        if ($routeKey !== null && $routeKey->isLocalized()) {
            return $this->currentSlug($routeKey->column);
        }

        return parent::getRouteKey();
    }

    /**
     * Covers implicit binding, `{model:column}`, withTrashed() routes and scoped child bindings
     * (Laravel hands this a Model, a Builder or a Relation).
     *
     * @param  Model|Builder<*>|Relation<*, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return BuilderContract
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $field ??= $this->getRouteKeyName();
        $definition = $this->sluggableOptions()->find(Str::afterLast($field, '.'));

        if ($definition === null) {
            // Keep HasUuids/HasUlids' guard, which `insteadof` displaced: a malformed id never
            // reaches a uuid column (a pgsql 500), it is a 404.
            if (in_array($field, $this->uniqueIds(), true) && ! (is_string($value) && $this->sluggableIsKeyShaped($value))) {
                throw (new ModelNotFoundException)->setModel(static::class, [$value]);
            }

            return parent::resolveRouteBindingQuery($query, $value, $field);
        }

        $builder = $query instanceof Model ? $query->newQuery() : $query;

        if (! $this->sluggableIsBindable($value, $definition)) {
            $builder->whereRaw('1 = 0');

            return $builder;
        }

        $qualified = str_contains($field, '.') ? $field : $this->qualifyColumn($definition->column);

        $this->sluggableWhere($builder, $definition, $qualified, [(string) $value], null);
        $this->sluggableOrder($builder, $definition, $qualified, (string) $value);

        return $builder;
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        if ($this->sluggableRejectsBinding($value, $field)) {
            return null;
        }

        return $this->resolveRouteBindingQuery($this, $value, $field)->first()
            ?? $this->sluggableBindingFallback($this->newQuery(), $value, $field);
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveSoftDeletableRouteBinding($value, $field = null): ?Model
    {
        if ($this->sluggableRejectsBinding($value, $field)) {
            return null;
        }

        return $this->resolveRouteBindingQuery($this, $value, $field)->withoutGlobalScope(SoftDeletingScope::class)->first()
            ?? $this->sluggableBindingFallback($this->newQuery()->withoutGlobalScope(SoftDeletingScope::class), $value, $field);
    }

    /**
     * @param  string  $childType
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveChildRouteBinding($childType, $value, $field): ?Model
    {
        $child = parent::resolveChildRouteBinding($childType, $value, $field);

        if ($child !== null) {
            return $child;
        }

        $relationship = $this->{$this->childRouteBindingRelationshipName($childType)}();

        if (! $relationship instanceof Relation) {
            return null;
        }

        $related = $relationship->getRelated();

        return $related instanceof Sluggable && method_exists($related, 'sluggableBindingFallback')
            ? $related->sluggableBindingFallback($relationship, $value, $field)
            : null;
    }

    /**
     * After a slug miss: the primary key (opt-in, only for values shaped like this model's key),
     * then a history redirect (GET/HEAD, only to a target the same query can see).
     *
     * @internal
     *
     * @param  Builder<*>|Relation<*, *, *>  $scope
     */
    public function sluggableBindingFallback(Builder|Relation $scope, mixed $value, ?string $field): ?Model
    {
        $field ??= $this->getRouteKeyName();
        $definition = $this->sluggableOptions()->find(Str::afterLast($field, '.'));

        if ($definition === null || ! is_string($value) || ! $this->sluggableIsBindable($value, $definition)) {
            return null;
        }

        if ($definition->bindByKeyFallback && $this->sluggableIsKeyShaped($value)) {
            $found = (clone $scope)->whereKey($value)->first();

            if ($found instanceof Model) {
                return $found;
            }
        }

        if (! $definition->redirectFromHistory || ! $this->sluggableIsRedirectableRequest()) {
            return null;
        }

        $target = app(SlugManager::class)->model(static::class)->findInHistory($value, $definition->column, within: clone $scope);

        if ($target instanceof Sluggable) {
            $current = $target->currentSlug($definition->column);

            if ($current !== null && $current !== $value) {
                throw new SlugMovedException($target, $value, $current, SluggableConfig::historyRedirectStatus());
            }
        }

        return null;
    }

    /** @param Builder<static> $query */
    protected function performInsert(Builder $query): bool
    {
        return app(CollisionRetrier::class)->run($this, fn (): bool => parent::performInsert(clone $query));
    }

    /** @param Builder<static> $query */
    protected function performUpdate(Builder $query): bool
    {
        return app(CollisionRetrier::class)->run($this, fn (): bool => parent::performUpdate(clone $query));
    }

    protected function sluggableOptions(): ResolvedSlugOptions
    {
        return app(SlugState::class)->options($this);
    }

    /**
     * @param  Builder<*>|Relation<*, *, *>  $query
     * @param  list<string>  $slugs
     */
    protected function sluggableWhere(Builder|Relation $query, ResolvedSlugDefinition $definition, string $qualified, array $slugs, ?string $locale): void
    {
        if (! $definition->isLocalized()) {
            if ($locale !== null) {
                throw InvalidSlugDefinitionException::localeOptionOnStringColumn($definition->column, 'locale');
            }

            count($slugs) === 1 ? $query->where($qualified, $slugs[0]) : $query->whereIn($qualified, $slugs);

            return;
        }

        $locales = $locale !== null
            ? [IdentifierGuard::locale($locale)]
            : $definition->chain(app(SlugLocales::class));

        $query->where(function (Builder $group) use ($locales, $qualified, $slugs): void {
            foreach ($locales as $candidate) {
                count($slugs) === 1
                    ? $group->orWhere(JsonPath::of($qualified, $candidate), $slugs[0])
                    : $group->orWhereIn(JsonPath::of($qualified, $candidate), $slugs);
            }
        });
    }

    /**
     * `CASE WHEN <current path> = ? THEN 0 WHEN <fallback path> = ? THEN 1 ELSE 2 END` — values
     * bound, paths grammar-wrapped from allowlisted locales.
     *
     * @param  Builder<*>|Relation<*, *, *>  $query
     */
    protected function sluggableOrder(Builder|Relation $query, ResolvedSlugDefinition $definition, string $qualified, string $slug): void
    {
        if (! $definition->isLocalized()) {
            return;
        }

        $locales = app(SlugLocales::class);
        $preferred = array_values(array_unique(array_filter([$locales->current(), $definition->fallbackLocaleFor($locales)])));

        $base = $query->getQuery();
        $base = $base instanceof Builder ? $base->getQuery() : $base;

        $cases = '';
        $bindings = [];

        foreach ($preferred as $rank => $locale) {
            $cases .= ' when '.JsonPath::wrapped($base, $qualified, $locale).' = ? then '.$rank;
            $bindings[] = $slug;
        }

        $query->orderBy(new RawExpression('case'.$cases.' else '.count($preferred).' end'));
        $base->addBinding($bindings, 'order');
    }

    /** A slug field whose value can never match: answer without touching the database. */
    protected function sluggableRejectsBinding(mixed $value, ?string $field): bool
    {
        $definition = $this->sluggableOptions()->find(Str::afterLast($field ?? $this->getRouteKeyName(), '.'));

        return $definition !== null && ! $this->sluggableIsBindable($value, $definition);
    }

    protected function sluggableIsBindable(mixed $value, ResolvedSlugDefinition $definition): bool
    {
        return is_string($value)
            && $value !== ''
            && mb_check_encoding($value, 'UTF-8')
            && mb_strlen($value) <= $definition->format->maxLength
            && preg_match('/\p{Cc}/u', $value) === 0;
    }

    /** Only a value shaped like this model's key may reach whereKey() (a slug on a pgsql uuid column is a 500). */
    protected function sluggableIsKeyShaped(string $value): bool
    {
        if ($this->usesUniqueIds()) {
            // isValidUniqueId() is protected on HasUuids/HasUlids (and fleet key traits), and
            // not every model with unique ids declares it.
            $reflection = new ReflectionObject($this);

            return $reflection->hasMethod('isValidUniqueId')
                && $reflection->getMethod('isValidUniqueId')->invoke($this, $value) === true;
        }

        return in_array($this->getKeyType(), ['int', 'integer'], true)
            && ctype_digit($value)
            && strlen($value) <= 18;
    }

    protected function sluggableIsRedirectableRequest(): bool
    {
        return in_array(app('request')->getMethod(), ['GET', 'HEAD'], true);
    }

    protected function sluggableString(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
