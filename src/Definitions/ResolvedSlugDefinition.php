<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Definitions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\Contracts\SuffixGenerator;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugFormat;
use RoundlyConsulting\Sluggable\DataTransferObjects\SourcePart;
use RoundlyConsulting\Sluggable\Enums\EmptySourcePolicy;
use RoundlyConsulting\Sluggable\Enums\LocaleFallback;
use RoundlyConsulting\Sluggable\Enums\LocaleUniqueness;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Enums\SlugStorage;
use RoundlyConsulting\Sluggable\Enums\SuffixStrategy;
use RoundlyConsulting\Sluggable\Enums\TargetLocales;
use RoundlyConsulting\Sluggable\Enums\Uniqueness;
use RoundlyConsulting\Sluggable\Enums\UpdatePolicy;

/**
 * A fully resolved, validated slug definition for one column — every option typed and non-null.
 */
final readonly class ResolvedSlugDefinition
{
    /**
     * @param  list<SourcePart>  $sources
     * @param  list<string>  $scopeColumns
     * @param  TargetLocales|list<string>|Closure  $locales
     */
    public function __construct(
        public string $column,
        public SlugStorage $storage,
        public array $sources,
        public ?string $sourceLocale,
        public SlugFormat $format,
        public string|Closure|null $language,
        public string|Closure|null $prefix,
        public string|Closure|null $suffix,
        public Uniqueness $uniqueness,
        public array $scopeColumns,
        public ?Closure $scopeClosure,
        public bool $includeTrashed,
        public LocaleUniqueness $localeUniqueness,
        public SuffixStrategy $suffixStrategy,
        public SuffixGenerator $suffixGenerator,
        public int $randomLength,
        public bool $onCreate,
        public UpdatePolicy $onUpdate,
        public ManualSlugPolicy $manual,
        public EmptySourcePolicy $emptySource,
        public ?Closure $lockWhen,
        public bool $locked,
        public ?Closure $skipWhen,
        public TargetLocales|array|Closure $locales,
        public LocaleFallback $fallback,
        public string|Closure|null $fallbackLocale,
        public bool $routeKey,
        public bool $bindByKeyFallback,
        public bool $keepHistory,
        public bool $redirectFromHistory,
        public bool $avoidHistoricalSlugs,
        public int $retries,
        public int $sequentialProbes = 50,
        public int $probeBatch = 10,
        public int $randomAttempts = 10,
    ) {}

    public function isLocalized(): bool
    {
        return $this->storage === SlugStorage::LocaleMap;
    }

    public function isUnique(): bool
    {
        return $this->uniqueness !== Uniqueness::None;
    }

    /** The transliteration language for one locale (or for the string column when null). */
    public function languageFor(?string $locale): ?string
    {
        if ($this->language instanceof Closure) {
            $language = ($this->language)($locale);

            return is_string($language) && $language !== '' ? $language : ($locale !== null ? self::localeLanguage($locale) : null);
        }

        if ($this->language !== null) {
            return $this->language;
        }

        return $locale !== null ? self::localeLanguage($locale) : 'en';
    }

    public function fallbackLocaleFor(SlugLocales $locales): ?string
    {
        $fallback = $this->fallbackLocale instanceof Closure ? ($this->fallbackLocale)() : $this->fallbackLocale;

        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        // A host locale source may report "no fallback" as ''; that is not a locale.
        $fallback = $locales->fallback();

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * Every locale a slug of this definition can be looked up under: current, fallback, the
     * supported locales and an explicit `locales([...])` list. Generation stays inside it and
     * `LocaleFallback::Any` searches all of it, so no slug — and no route key — exists that its
     * own lookup cannot find.
     *
     * @return list<string>
     */
    public function resolvableLocales(SlugLocales $locales, ?string $current = null): array
    {
        return self::distinct([
            $current ?? $locales->current(),
            $this->fallbackLocaleFor($locales),
            ...$locales->supported(),
            ...(is_array($this->locales) ? $this->locales : []),
        ]);
    }

    /**
     * The reading/binding chain: current → fallback → (every resolvable locale) per
     * {@see LocaleFallback}.
     *
     * @return list<string>
     */
    public function chain(SlugLocales $locales, ?string $current = null): array
    {
        return match ($this->fallback) {
            LocaleFallback::Any => $this->resolvableLocales($locales, $current),
            LocaleFallback::Fallback => self::distinct([$current ?? $locales->current(), $this->fallbackLocaleFor($locales)]),
            LocaleFallback::None => self::distinct([$current ?? $locales->current()]),
        };
    }

    public function isLockedFor(Model $model): bool
    {
        if ($this->locked) {
            return true;
        }

        return $this->lockWhen !== null && ($this->lockWhen)($model) === true;
    }

    public function shouldSkip(Model $model): bool
    {
        return $this->skipWhen !== null && ($this->skipWhen)($model) === true;
    }

    /**
     * An existing row loaded without this column (`select('id')`): its stored slug is unknown, so
     * there is nothing to compare or merge with — generating would overwrite it. Eloquent's own
     * missing-attribute condition, so a model created in this request still gets its slug.
     */
    public function isUnloadedOn(Model $model): bool
    {
        return $model->exists
            && ! $model->wasRecentlyCreated
            && ! array_key_exists($this->column, $model->getAttributes());
    }

    public function hasScopeColumns(): bool
    {
        return $this->scopeColumns !== [];
    }

    /**
     * The regular expression a value matches when it is `base` plus a suffix this definition's
     * strategy could have produced — used so a recompute never churns `-2` into `-3`.
     */
    public function suffixPattern(string $base): string
    {
        $separator = preg_quote($this->format->separator, '/');
        $quoted = preg_quote($base, '/');

        // Random suffixes are also what every strategy falls back to once the sequential probes
        // run out, so a sequential definition must recognise them too.
        $random = ($this->format->lowercase ? '[a-z0-9]' : '[A-Za-z0-9]').'{'.$this->randomLength.'}';

        $suffix = match ($this->suffixStrategy) {
            SuffixStrategy::Sequential => '(?:\d+|'.$random.')',
            SuffixStrategy::Random => $random,
            SuffixStrategy::Custom => '.+',
        };

        return '/^'.$quoted.'(?:'.$separator.$suffix.')?$/u';
    }

    /**
     * @param  list<string|null>  $locales
     * @return list<string>
     */
    private static function distinct(array $locales): array
    {
        return array_values(array_unique(array_filter($locales, static fn (?string $locale): bool => $locale !== null && $locale !== '')));
    }

    /** `pt-BR` → `pt_BR`, the form the transliterator's language table uses. */
    private static function localeLanguage(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }
}
