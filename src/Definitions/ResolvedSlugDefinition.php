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

        return $locales->fallback();
    }

    /**
     * The reading/binding chain: current → fallback → (any supported) per {@see LocaleFallback}.
     *
     * @return list<string>
     */
    public function chain(SlugLocales $locales, ?string $current = null): array
    {
        $chain = [$current ?? $locales->current()];

        if ($this->fallback !== LocaleFallback::None) {
            $fallback = $this->fallbackLocaleFor($locales);

            if ($fallback !== null) {
                $chain[] = $fallback;
            }
        }

        if ($this->fallback === LocaleFallback::Any) {
            $chain = [...$chain, ...$locales->supported()];
        }

        return array_values(array_unique($chain));
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

        $suffix = match ($this->suffixStrategy) {
            SuffixStrategy::Sequential => '\d+',
            SuffixStrategy::Random => ($this->format->lowercase ? '[a-z0-9]' : '[A-Za-z0-9]').'{'.$this->randomLength.'}',
            SuffixStrategy::Custom => '.+',
        };

        return '/^'.$quoted.'(?:'.$separator.$suffix.')?$/u';
    }

    /** `pt-BR` → `pt_BR`, the form the transliterator's language table uses. */
    private static function localeLanguage(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }
}
