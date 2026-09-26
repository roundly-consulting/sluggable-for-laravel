<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Definitions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Suffixes\ClosureSuffix;
use RoundlyConsulting\Sluggable\Suffixes\RandomSuffix;
use RoundlyConsulting\Sluggable\Suffixes\SequentialSuffix;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\LocaleMapAccessor;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;
use RoundlyConsulting\Sluggable\Support\Slugger;
use RoundlyConsulting\Sluggable\Support\SlugHistoryModel;

/**
 * Fluent, per-column slug builder. Every option left unset falls back to the `sluggable.defaults`
 * config at resolve time, so a definition only names what it changes.
 */
final class SlugDefinition
{
    private const SEPARATOR = '/^[-._~]{1,3}$/';

    /** @var list<string>|null */
    private ?array $sourceNames = null;

    private ?Closure $sourceClosure = null;

    private ?string $sourceLocale = null;

    private ?string $separator = null;

    private ?int $maxLength = null;

    private ?int $maxWords = null;

    private bool $maxWordsSet = false;

    private string|Closure|null $language = null;

    /** @var array<string, string>|null */
    private ?array $dictionary = null;

    private ?bool $unicode = null;

    private ?bool $lowercase = null;

    private ?Closure $slugger = null;

    /** @var list<string> */
    private array $reserved = [];

    private string|Closure|null $prefix = null;

    private string|Closure|null $suffix = null;

    private ?Uniqueness $uniqueness = null;

    /** @var list<string> */
    private array $scopeColumns = [];

    private ?Closure $scopeClosure = null;

    private ?LocaleUniqueness $localeUniqueness = null;

    private ?bool $includeTrashed = null;

    private ?SuffixStrategy $suffixStrategy = null;

    private ?int $suffixStart = null;

    private SuffixGenerator|Closure|null $suffixGenerator = null;

    private ?int $randomLength = null;

    private ?bool $onCreate = null;

    private ?UpdatePolicy $onUpdate = null;

    private ?ManualSlugPolicy $manual = null;

    private ?EmptySourcePolicy $emptySource = null;

    private ?Closure $lockWhen = null;

    private bool $locked = false;

    private ?Closure $skipWhen = null;

    private ?SlugStorage $storage = null;

    /** @var TargetLocales|list<string>|Closure|null */
    private TargetLocales|array|Closure|null $locales = null;

    private ?LocaleFallback $fallback = null;

    private string|Closure|null $fallbackLocale = null;

    private bool $routeKey = false;

    private ?bool $bindByKeyFallback = null;

    private ?bool $keepHistory = null;

    private ?bool $redirectFromHistory = null;

    private ?bool $avoidHistoricalSlugs = null;

    private ?int $retries = null;

    private function __construct(
        private readonly string $column,
    ) {}

    public static function for(string $column): self
    {
        return new self($column);
    }

    public function column(): string
    {
        return $this->column;
    }

    public function isRouteKey(): bool
    {
        return $this->routeKey;
    }

    /**
     * Attribute(s) — dot paths reach through relations — or a closure receiving the model and the
     * locale being generated.
     *
     * @template TModel of Model
     *
     * @param  string|list<string>|(Closure(TModel, ?string): (string|null))  $source
     */
    public function from(string|array|Closure $source): static
    {
        if ($source instanceof Closure) {
            $this->sourceClosure = $source;
            $this->sourceNames = null;

            return $this;
        }

        $this->sourceClosure = null;
        $this->sourceNames = is_string($source) ? [$source] : $source;

        return $this;
    }

    /** Which locale of a locale-map source feeds a STRING slug (default: fallback → current → first). */
    public function sourceLocale(string $locale): static
    {
        $this->sourceLocale = $locale;

        return $this;
    }

    public function separator(string $separator): static
    {
        $this->separator = $separator;

        return $this;
    }

    public function maxLength(int $chars): static
    {
        $this->maxLength = $chars;

        return $this;
    }

    public function maxWords(?int $words): static
    {
        $this->maxWords = $words;
        $this->maxWordsSet = true;

        return $this;
    }

    /**
     * Transliteration language. `null` on a locale map = each locale's own language.
     *
     * @param  string|(Closure(?string): (string|null))|null  $language
     */
    public function language(string|Closure|null $language): static
    {
        $this->language = $language;

        return $this;
    }

    /** @param array<string, string> $map */
    public function dictionary(array $map): static
    {
        $this->dictionary = $map;

        return $this;
    }

    public function unicode(bool $on = true): static
    {
        $this->unicode = $on;

        return $this;
    }

    public function lowercase(bool $on = true): static
    {
        $this->lowercase = $on;

        return $this;
    }

    /**
     * Replace the formatting steps with your own slugger; its output is still re-validated.
     *
     * @param  Closure(string, ?string): string  $slugger
     */
    public function using(Closure $slugger): static
    {
        $this->slugger = $slugger;

        return $this;
    }

    /** @param list<string> $words */
    public function reserved(array $words): static
    {
        $this->reserved = $words;

        return $this;
    }

    /**
     * @template TModel of Model
     *
     * @param  string|(Closure(TModel, ?string): (string|null))  $prefix
     */
    public function prefix(string|Closure $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * @template TModel of Model
     *
     * @param  string|(Closure(TModel, ?string): (string|null))  $suffix
     */
    public function suffix(string|Closure $suffix): static
    {
        $this->suffix = $suffix;

        return $this;
    }

    public function unique(): static
    {
        $this->uniqueness = Uniqueness::Global;

        return $this;
    }

    /** Unique among rows sharing these column values (tenant, parent, …) — DB-indexable. */
    public function uniqueWithin(string ...$columns): static
    {
        $this->uniqueness = Uniqueness::Scoped;
        $this->scopeColumns = array_values($columns);
        $this->scopeClosure = null;

        return $this;
    }

    /**
     * Unique among the rows a closure selects — app-level only (no DB index can express it).
     *
     * @template TModel of Model
     *
     * @param  Closure(Builder<TModel>, TModel): mixed  $scope
     */
    public function uniqueWhere(Closure $scope): static
    {
        $this->uniqueness = Uniqueness::Scoped;
        $this->scopeClosure = $scope;
        $this->scopeColumns = [];

        return $this;
    }

    public function notUnique(): static
    {
        $this->uniqueness = Uniqueness::None;

        return $this;
    }

    public function perLocaleUniqueness(): static
    {
        $this->localeUniqueness = LocaleUniqueness::PerLocale;

        return $this;
    }

    public function uniqueAcrossLocales(): static
    {
        $this->localeUniqueness = LocaleUniqueness::AcrossLocales;

        return $this;
    }

    public function includeTrashed(bool $on = true): static
    {
        $this->includeTrashed = $on;

        return $this;
    }

    public function excludeTrashed(): static
    {
        return $this->includeTrashed(false);
    }

    public function sequentialSuffix(int $start = 2): static
    {
        $this->suffixStrategy = SuffixStrategy::Sequential;
        $this->suffixStart = $start;

        return $this;
    }

    public function randomSuffix(?int $length = null): static
    {
        $this->suffixStrategy = SuffixStrategy::Random;

        if ($length !== null) {
            $this->randomLength = $length;
        }

        return $this;
    }

    /**
     * @param  SuffixGenerator|(Closure(string, int, ResolvedSlugDefinition): string)  $generator
     */
    public function suffixUsing(SuffixGenerator|Closure $generator): static
    {
        $this->suffixStrategy = SuffixStrategy::Custom;
        $this->suffixGenerator = $generator;

        return $this;
    }

    /** Length of random slugs (EmptySourcePolicy::Random) and random suffixes, 4–32. */
    public function randomLength(int $chars): static
    {
        $this->randomLength = $chars;

        return $this;
    }

    public function onCreate(bool $on = true): static
    {
        $this->onCreate = $on;

        return $this;
    }

    public function onUpdate(UpdatePolicy $policy): static
    {
        $this->onUpdate = $policy;

        return $this;
    }

    public function immutable(): static
    {
        return $this->onUpdate(UpdatePolicy::Never);
    }

    public function regenerateOnUpdate(): static
    {
        return $this->onUpdate(UpdatePolicy::WhenSourceChanges);
    }

    public function manual(ManualSlugPolicy $policy): static
    {
        $this->manual = $policy;

        return $this;
    }

    public function whenEmptySource(EmptySourcePolicy $policy): static
    {
        $this->emptySource = $policy;

        return $this;
    }

    /**
     * No automatic change while the closure returns true; a manual change throws.
     *
     * @template TModel of Model
     *
     * @param  Closure(TModel): bool  $when
     */
    public function lockWhen(Closure $when): static
    {
        $this->lockWhen = $when;

        return $this;
    }

    public function locked(bool $on = true): static
    {
        $this->locked = $on;

        return $this;
    }

    /**
     * Skip this column entirely for a save while the closure returns true.
     *
     * @template TModel of Model
     *
     * @param  Closure(TModel): bool  $when
     */
    public function skipWhen(Closure $when): static
    {
        $this->skipWhen = $when;

        return $this;
    }

    public function localized(bool $on = true): static
    {
        $this->storage = $on ? SlugStorage::LocaleMap : SlugStorage::String;

        return $this;
    }

    public function storage(SlugStorage $storage): static
    {
        $this->storage = $storage;

        return $this;
    }

    /**
     * @template TModel of Model
     *
     * @param  TargetLocales|list<string>|(Closure(TModel): list<string>)  $locales
     */
    public function locales(TargetLocales|array|Closure $locales): static
    {
        $this->locales = $locales;

        return $this;
    }

    public function fallback(LocaleFallback $chain): static
    {
        $this->fallback = $chain;

        return $this;
    }

    /**
     * Per-definition fallback locale, overriding SlugLocales::fallback().
     *
     * @param  string|(Closure(): (string|null))|null  $locale
     */
    public function fallbackLocale(string|Closure|null $locale): static
    {
        $this->fallbackLocale = $locale;

        return $this;
    }

    public function routeKey(bool $on = true): static
    {
        $this->routeKey = $on;

        return $this;
    }

    public function bindByKeyFallback(bool $on = true): static
    {
        $this->bindByKeyFallback = $on;

        return $this;
    }

    public function keepHistory(bool $on = true): static
    {
        $this->keepHistory = $on;

        return $this;
    }

    public function redirectFromHistory(bool $on = true): static
    {
        $this->redirectFromHistory = $on;

        return $this;
    }

    public function avoidHistoricalSlugs(bool $on = true): static
    {
        $this->avoidHistoricalSlugs = $on;

        return $this;
    }

    public function retries(int $times): static
    {
        $this->retries = $times;

        return $this;
    }

    /**
     * Fill every unset option from config, validate, and freeze the result for one model.
     */
    public function resolve(Model $model): ResolvedSlugDefinition
    {
        $column = IdentifierGuard::identifier($this->column, 'slug column');

        $separator = $this->separator ?? SluggableConfig::separator();

        if (preg_match(self::SEPARATOR, $separator) !== 1) {
            throw InvalidSlugDefinitionException::invalidSeparator($separator);
        }

        $storage = $this->storage ?? LocaleMapAccessor::storageOf($model, $column);
        $localized = $storage === SlugStorage::LocaleMap;

        $cast = $model->getCasts()[$column] ?? null;

        if (is_string($cast) && str_starts_with(strtolower($cast), 'encrypted')) {
            throw InvalidSlugDefinitionException::unqueryableCast($column, $cast);
        }

        if (! $localized) {
            if ($this->localeUniqueness === LocaleUniqueness::AcrossLocales) {
                throw InvalidSlugDefinitionException::localeOptionOnStringColumn($column, 'uniqueAcrossLocales');
            }

            if ($this->locales !== null) {
                throw InvalidSlugDefinitionException::localeOptionOnStringColumn($column, 'locales');
            }
        }

        if ($this->sourceLocale !== null) {
            IdentifierGuard::locale($this->sourceLocale);
        }

        $sources = $this->resolveSources($column);

        $uniqueness = $this->uniqueness ?? SluggableConfig::uniqueness();

        if ($uniqueness === Uniqueness::Scoped && $this->scopeColumns === [] && $this->scopeClosure === null) {
            throw InvalidSlugDefinitionException::scopedWithoutScope($column);
        }

        foreach ($this->scopeColumns as $scopeColumn) {
            IdentifierGuard::identifier($scopeColumn, 'scope column');
        }

        $randomLength = $this->randomLength ?? SluggableConfig::randomLength();

        if ($randomLength < 4 || $randomLength > 32) {
            throw InvalidSlugDefinitionException::invalidOption('randomLength', 'use 4-32 characters');
        }

        $retries = $this->retries ?? SluggableConfig::retries();

        if ($retries < 0 || $retries > 20) {
            throw InvalidSlugDefinitionException::invalidOption('retries', 'use 0-20');
        }

        $lowercase = $this->lowercase ?? SluggableConfig::lowercase();
        $strategy = $this->suffixStrategy ?? SluggableConfig::suffix();
        $suffixStart = $this->suffixStart ?? SluggableConfig::suffixStart();

        if ($suffixStart < 1 || $suffixStart > 1000) {
            throw InvalidSlugDefinitionException::invalidOption('sequentialSuffix', 'start at 1-1000');
        }

        $generator = match ($strategy) {
            SuffixStrategy::Sequential => new SequentialSuffix($suffixStart),
            SuffixStrategy::Random => new RandomSuffix($randomLength, $lowercase),
            SuffixStrategy::Custom => match (true) {
                $this->suffixGenerator instanceof SuffixGenerator => $this->suffixGenerator,
                $this->suffixGenerator instanceof Closure => new ClosureSuffix($this->suffixGenerator),
                default => throw InvalidSlugDefinitionException::missingSuffixGenerator($column),
            },
        };

        $format = new SlugFormat(
            separator: $separator,
            maxLength: $this->maxLength ?? SluggableConfig::maxLength(),
            maxWords: $this->maxWordsSet ? $this->maxWords : SluggableConfig::maxWords(),
            dictionary: $this->dictionary ?? SluggableConfig::dictionary(),
            lowercase: $lowercase,
            unicode: $this->unicode ?? SluggableConfig::unicode(),
            reserved: array_values(array_unique([
                ...SluggableConfig::reserved(),
                ...array_map(mb_strtolower(...), $this->reserved),
            ])),
            slugger: $this->slugger,
            maxSourceLength: SluggableConfig::maxSourceLength(),
        );

        $this->assertLength($column, $format);

        $historyEnabled = $this->keepHistory ?? SluggableConfig::historyEnabled();

        if ($historyEnabled) {
            SlugHistoryModel::assertKeyType($model);
            SlugHistoryModel::assertSlugFits($format->maxLength);
        }

        if ($this->maxWords !== null && $this->maxWords < 1) {
            throw InvalidSlugDefinitionException::invalidOption('maxWords', 'use at least one word');
        }

        return new ResolvedSlugDefinition(
            column: $column,
            storage: $storage,
            sources: $sources,
            sourceLocale: $this->sourceLocale,
            format: $format,
            language: $this->language ?? ($localized ? null : SluggableConfig::language()),
            prefix: $this->prefix,
            suffix: $this->suffix,
            uniqueness: $uniqueness,
            scopeColumns: $this->scopeColumns,
            scopeClosure: $this->scopeClosure,
            includeTrashed: $this->includeTrashed ?? SluggableConfig::includeTrashed(),
            localeUniqueness: $this->localeUniqueness ?? SluggableConfig::localeUniqueness(),
            suffixStrategy: $strategy,
            suffixGenerator: $generator,
            randomLength: $randomLength,
            onCreate: $this->onCreate ?? SluggableConfig::onCreate(),
            onUpdate: $this->onUpdate ?? SluggableConfig::onUpdate(),
            manual: $this->manual ?? SluggableConfig::manual(),
            emptySource: $this->emptySource ?? SluggableConfig::emptySource(),
            lockWhen: $this->lockWhen,
            locked: $this->locked,
            skipWhen: $this->skipWhen,
            locales: $this->locales ?? SluggableConfig::targetLocales(),
            fallback: $this->fallback ?? SluggableConfig::localeFallback(),
            fallbackLocale: $this->fallbackLocale,
            routeKey: $this->routeKey,
            bindByKeyFallback: $this->bindByKeyFallback ?? SluggableConfig::bindingKeyFallback(),
            keepHistory: $historyEnabled,
            redirectFromHistory: $historyEnabled && ($this->redirectFromHistory ?? SluggableConfig::historyRedirect()),
            avoidHistoricalSlugs: $historyEnabled && ($this->avoidHistoricalSlugs ?? SluggableConfig::historyAvoidReuse()),
            retries: $retries,
            sequentialProbes: SluggableConfig::sequentialProbes(),
            probeBatch: SluggableConfig::probeBatch(),
            randomAttempts: SluggableConfig::randomAttempts(),
        );
    }

    /** @return list<SourcePart> */
    private function resolveSources(string $column): array
    {
        if ($this->sourceClosure !== null) {
            return [new SourcePart($this->sourceClosure, false)];
        }

        $names = $this->sourceNames ?? [SluggableConfig::defaultSource()];
        $parts = [];

        foreach ($names as $name) {
            if ($name === $column) {
                throw InvalidSlugDefinitionException::selfReferencingSource($column);
            }

            $isPath = str_contains($name, '.');

            foreach (explode('.', $name) as $segment) {
                IdentifierGuard::identifier($segment, 'source attribute');
            }

            $parts[] = new SourcePart($name, $isPath);
        }

        return $parts;
    }

    private function assertLength(string $column, SlugFormat $format): void
    {
        if ($format->maxLength < 8 || $format->maxLength > 2048) {
            throw InvalidSlugDefinitionException::maxLengthTooSmall($column, $format->maxLength);
        }

        $affixes = 0;

        foreach ([$this->prefix, $this->suffix] as $affix) {
            if (is_string($affix) && $affix !== '') {
                $affixes += mb_strlen(Slugger::affix($affix, $format)) + mb_strlen($format->separator);
            }
        }

        if ($affixes >= $format->maxLength) {
            throw InvalidSlugDefinitionException::maxLengthTooSmall($column, $format->maxLength);
        }
    }
}
