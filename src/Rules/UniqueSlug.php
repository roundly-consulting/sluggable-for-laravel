<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RoundlyConsulting\Sluggable\Contracts\SlugLocales;
use RoundlyConsulting\Sluggable\DataTransferObjects\ProbeContext;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Enums\ManualSlugPolicy;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\SlugManager;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\Slugger;
use RoundlyConsulting\Sluggable\Support\UniquenessChecker;

/**
 * Fails when the slug is already taken — with exactly the semantics generation uses (scopes,
 * trashed rows, no global scopes, reserved words).
 *
 * Scope values are NEVER read from the request implicitly: that would let a client pass another
 * tenant's id and use the rule as a cross-tenant "is this slug taken?" oracle. Per scope column
 * the value comes from where(), then the within()/ignore() model, then — only for columns named
 * in scopeFromInput() — the validated data; otherwise the rule throws.
 */
final class UniqueSlug implements DataAwareRule, ValidationRule
{
    private int|string|null $ignoreKey = null;

    private ?Model $ignoreModel = null;

    private ?Model $within = null;

    /** @var array<string, mixed> */
    private array $wheres = [];

    /** @var list<string> */
    private array $fromInput = [];

    private ?string $locale = null;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @param class-string<Model> $modelClass */
    private function __construct(
        private readonly string $modelClass,
        private readonly ?string $column,
    ) {}

    /** @param class-string<Model> $modelClass */
    public static function for(string $modelClass, ?string $column = null): self
    {
        return new self($modelClass, $column);
    }

    public function ignore(Model|int|string|null $keyOrModel): self
    {
        if ($keyOrModel instanceof Model) {
            $this->ignoreModel = $keyOrModel;
            $this->ignoreKey = $keyOrModel->exists ? $this->key($keyOrModel) : null;

            return $this;
        }

        $this->ignoreKey = $keyOrModel;

        return $this;
    }

    public function where(string $column, mixed $value): self
    {
        $this->wheres[IdentifierGuard::identifier($column, 'scope column')] = $value;

        return $this;
    }

    public function within(Model $scopeModel): self
    {
        $this->within = $scopeModel;

        return $this;
    }

    /** Opt a scope column into being read from the validated data (authorise it in the FormRequest). */
    public function scopeFromInput(string ...$columns): self
    {
        $this->fromInput = [...$this->fromInput, ...array_values($columns)];

        return $this;
    }

    public function locale(string $locale): self
    {
        $this->locale = IdentifierGuard::locale($locale);

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $prototype = new ($this->modelClass);
        $definition = app(SlugManager::class)->options($prototype)->get($this->column);
        $context = $this->context($prototype, $definition);
        $checker = app(UniquenessChecker::class);

        if (! $definition->isLocalized()) {
            if (is_string($value) && $value !== '' && $this->isTaken($checker, $context, $definition, null, $value)) {
                $fail('sluggable::validation.unique')->translate();
            }

            return;
        }

        $locales = app(SlugLocales::class);

        $map = match (true) {
            is_string($value) => [$this->locale ?? $locales->current() => $value],
            is_array($value) => $value,
            default => [],
        };

        $supported = $locales->supported();

        foreach ($map as $locale => $slug) {
            if (! is_string($locale) || ! is_string($slug) || trim($slug) === '' || ! IdentifierGuard::isLocale($locale)) {
                continue;
            }

            if ($this->locale !== null ? $locale !== $this->locale : ! in_array($locale, $supported, true)) {
                continue;
            }

            if ($this->isTaken($checker, $context, $definition, $locale, $slug)) {
                $key = is_array($value) ? "{$attribute}.{$locale}" : $attribute;

                $fail($key, 'sluggable::validation.unique_locale')->translate(['locale' => $locale]);
            }
        }
    }

    private function context(Model $prototype, ResolvedSlugDefinition $definition): ProbeContext
    {
        $scopeModel = $this->within ?? $this->ignoreModel;
        $scope = [];

        foreach ($definition->scopeColumns as $column) {
            $scope[$column] = $this->scopeValue($column, $scopeModel);
        }

        if ($definition->scopeClosure !== null && $scopeModel === null) {
            throw InvalidSlugDefinitionException::missingScopeValue('uniqueWhere closure');
        }

        return new ProbeContext(
            model: $prototype,
            ignoreKey: $this->ignoreKey,
            scope: $scope,
            scopeModel: $scopeModel,
            locales: app(SlugLocales::class)->supported(),
        );
    }

    private function scopeValue(string $column, ?Model $scopeModel): mixed
    {
        if (array_key_exists($column, $this->wheres)) {
            return $this->wheres[$column];
        }

        if ($scopeModel !== null && array_key_exists($column, $scopeModel->getAttributes())) {
            return $scopeModel->getAttribute($column);
        }

        if (in_array($column, $this->fromInput, true) && Arr::has($this->data, $column)) {
            return Arr::get($this->data, $column);
        }

        throw InvalidSlugDefinitionException::missingScopeValue($column);
    }

    /**
     * Bytes that are not UTF-8 can never have been stored, so they are not taken — and they never
     * reach the query, where Postgres would reject them ("invalid byte sequence") with a 500.
     */
    private function isTaken(UniquenessChecker $checker, ProbeContext $context, ResolvedSlugDefinition $definition, ?string $locale, string $value): bool
    {
        $candidate = $this->normalize($definition, $value, $locale);

        return mb_check_encoding($candidate, 'UTF-8') && $checker->isTaken($context, $definition, $locale, $candidate);
    }

    /** Under the Normalize policy the stored value is the normalised one, so compare that. */
    private function normalize(ResolvedSlugDefinition $definition, string $value, ?string $locale): string
    {
        if ($definition->manual !== ManualSlugPolicy::Normalize) {
            return $value;
        }

        $normalized = Slugger::slugify($value, $definition->format, $definition->languageFor($locale), $locale);

        return $normalized === '' ? $value : $normalized;
    }

    private function key(Model $model): int|string|null
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
