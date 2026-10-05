<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\DataTransferObjects\SourcePart;
use RoundlyConsulting\Sluggable\DataTransferObjects\SourceTarget;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Enums\TargetLocales;
use Stringable;

/**
 * Turns a definition's sources into the text a slug is built from, per locale.
 *
 * Locale-map sources are read RAW through {@see LocaleMapAccessor} (never `getAttribute()`, which
 * fleet translation traits resolve to the current-locale string); scalar sources go through
 * `getAttribute()` so host accessors and casts still apply.
 */
final readonly class SourceResolver
{
    public function __construct(
        private SlugLocales $locales,
    ) {}

    /** The joined source text for one locale (`null` = a string slug). */
    public function text(Model $model, ResolvedSlugDefinition $definition, ?string $locale): string
    {
        $parts = [];

        foreach ($definition->sources as $part) {
            $value = trim($this->part($model, $part, $definition, $locale));

            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Locales a locale-map slug is generated for — only resolvable ones
     * ({@see ResolvedSlugDefinition::resolvableLocales()}): a slug in a locale no lookup searches
     * would be a route key whose own URL 404s. Source text in such a locale still gets a slug, in
     * the current locale, rather than none.
     *
     * @return list<string>
     */
    public function targetLocales(Model $model, ResolvedSlugDefinition $definition): array
    {
        $locales = $definition->locales;

        $targets = match (true) {
            $locales instanceof Closure => $this->stringList($locales($model)),
            is_array($locales) => $locales,
            $locales === TargetLocales::Supported => $this->locales->supported(),
            $locales === TargetLocales::Current => [$this->locales->current()],
            default => $this->sourceLocales($model, $definition),
        };

        $targets = array_values(array_unique(array_map(IdentifierGuard::locale(...), $targets)));
        $resolvable = array_values(array_intersect($targets, $definition->resolvableLocales($this->locales)));

        return $resolvable === [] && $locales === TargetLocales::Source ? [$this->locales->current()] : $resolvable;
    }

    /**
     * Whether the sources changed in this save — per locale for locale-map sources. Closure and
     * relation sources cannot be tracked, so they always count as changed.
     */
    public function changed(Model $model, ResolvedSlugDefinition $definition, ?string $locale): bool
    {
        foreach ($definition->sources as $part) {
            if (! $part->isAttribute() || ! is_string($part->source)) {
                return true;
            }

            $attribute = $part->source;

            if (LocaleMapAccessor::isLocaleMap($model, $attribute)) {
                $original = LocaleMapAccessor::original($model, $attribute);
                $current = LocaleMapAccessor::read($model, $attribute);

                if ($locale === null ? $original !== $current : ($original[$locale] ?? null) !== ($current[$locale] ?? null)) {
                    return true;
                }

                continue;
            }

            if ($model->isDirty($attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Locales present in any locale-map source; `[current]` when there are none (scalar-only
     * sources, or every map empty — so an empty source still reaches the empty-source policy).
     * Keys come from stored data, so a malformed one (`EN`, `default`) is skipped, never thrown.
     *
     * @return list<string>
     */
    private function sourceLocales(Model $model, ResolvedSlugDefinition $definition): array
    {
        $locales = [];

        foreach ($definition->sources as $part) {
            if (! is_string($part->source)) {
                continue;
            }

            $target = $this->target($model, $part);

            if ($target->model !== null && LocaleMapAccessor::isLocaleMap($target->model, $target->attribute)) {
                $locales = [...$locales, ...array_filter(array_keys(LocaleMapAccessor::read($target->model, $target->attribute)), IdentifierGuard::isLocale(...))];
            }
        }

        return $locales === [] ? [$this->locales->current()] : array_values(array_unique($locales));
    }

    private function part(Model $model, SourcePart $part, ResolvedSlugDefinition $definition, ?string $locale): string
    {
        if ($part->source instanceof Closure) {
            return $this->stringify(($part->source)($model, $locale));
        }

        $target = $this->target($model, $part);

        if ($target->model === null) {
            return '';
        }

        if (LocaleMapAccessor::isLocaleMap($target->model, $target->attribute)) {
            return $this->pick(LocaleMapAccessor::read($target->model, $target->attribute), $definition, $locale);
        }

        return $this->stringify($target->model->getAttribute($target->attribute));
    }

    /**
     * Resolve a dot path through its relations (`loadMissing`, so `preventLazyLoading()` hosts
     * don't throw). A missing relation yields no target.
     */
    private function target(Model $model, SourcePart $part): SourceTarget
    {
        $path = is_string($part->source) ? $part->source : '';

        if (! $part->isRelationPath) {
            return new SourceTarget($model, $path);
        }

        $segments = explode('.', $path);
        $attribute = (string) array_pop($segments);
        $current = $model;

        foreach ($segments as $relation) {
            if (! $current->relationLoaded($relation)) {
                if (! method_exists($current, $relation)) {
                    return new SourceTarget(null, $attribute);
                }

                $current->loadMissing($relation);
            }

            $related = $current->getRelation($relation);

            if (! $related instanceof Model) {
                return new SourceTarget(null, $attribute);
            }

            $current = $related;
        }

        return new SourceTarget($current, $attribute);
    }

    /** @param array<string, string> $map */
    private function pick(array $map, ResolvedSlugDefinition $definition, ?string $locale): string
    {
        if ($map === []) {
            return '';
        }

        $fallback = $definition->fallbackLocaleFor($this->locales);

        $preferred = $locale !== null
            ? [$locale, $fallback]
            : [$definition->sourceLocale, $fallback, $this->locales->current()];

        foreach ($preferred as $candidate) {
            if ($candidate !== null && isset($map[$candidate])) {
                return $map[$candidate];
            }
        }

        ksort($map);

        return (string) reset($map);
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof Stringable => (string) $value,
            default => '',
        };
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
