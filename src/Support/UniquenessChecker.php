<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use RoundlyConsulting\Sluggable\DataTransferObjects\ProbeContext;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Enums\LocaleUniqueness;

/**
 * Decides which slug candidates are taken — one semantics for generation, collision retries and
 * the UniqueSlug rule.
 *
 * The probe starts from `newQueryWithoutScopes()`: a global scope (tenancy, `published`,
 * SoftDeletes) must never hide a collision, because the unique index does not see scopes either.
 * Scope columns, trashed rows and the ignored key are then applied explicitly.
 */
final class UniquenessChecker
{
    /**
     * The candidates that are taken, in candidate order.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    public function taken(ProbeContext $context, ResolvedSlugDefinition $definition, ?string $locale, array $candidates): array
    {
        $taken = [];
        $remaining = [];

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (Slugger::isReserved($candidate, $definition->format) || in_array($candidate, $context->claimed, true)) {
                $taken[] = $candidate;
            } else {
                $remaining[] = $candidate;
            }
        }

        if ($remaining !== [] && $definition->isUnique()) {
            $taken = [...$taken, ...$this->existing($context, $definition, $locale, $remaining)];
        }

        if ($remaining !== [] && $definition->avoidHistoricalSlugs) {
            $taken = [...$taken, ...$this->historical($context, $definition, $locale, $remaining)];
        }

        return array_values(array_filter($candidates, static fn (string $candidate): bool => in_array($candidate, $taken, true)));
    }

    public function isTaken(ProbeContext $context, ResolvedSlugDefinition $definition, ?string $locale, string $candidate): bool
    {
        return $this->taken($context, $definition, $locale, [$candidate]) !== [];
    }

    /**
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function existing(ProbeContext $context, ResolvedSlugDefinition $definition, ?string $locale, array $candidates): array
    {
        $model = $context->model;
        $query = $this->baseQuery($context, $definition);
        $column = $model->qualifyColumn($definition->column);

        if (! $definition->isLocalized()) {
            $query->whereIn($column, $candidates);

            $found = $this->strings($query->toBase()->pluck($column)->all());

            return $this->matching($candidates, $found, $this->comparesLoosely($model));
        }

        $locales = $definition->localeUniqueness === LocaleUniqueness::AcrossLocales || $locale === null
            ? array_values(array_unique([...$context->locales, ...($locale === null ? [] : [$locale])]))
            : [$locale];

        $query->where(function (Builder $group) use ($locales, $column, $candidates): void {
            foreach ($locales as $probeLocale) {
                $group->orWhereIn(JsonPath::of($column, $probeLocale), $candidates);
            }
        });

        $found = [];

        foreach ($query->toBase()->pluck($column)->all() as $raw) {
            $map = LocaleMapAccessor::decode($raw);

            foreach ($locales as $probeLocale) {
                if (isset($map[$probeLocale])) {
                    $found[] = $map[$probeLocale];
                }
            }
        }

        // JSON paths compare binary on every engine (MySQL's JSON_UNQUOTE is utf8mb4_bin).
        return $this->matching($candidates, $found, false);
    }

    /**
     * @return Builder<Model>
     */
    private function baseQuery(ProbeContext $context, ResolvedSlugDefinition $definition): Builder
    {
        $model = $context->model;
        $query = $model->newQueryWithoutScopes();

        foreach ($definition->scopeColumns as $scopeColumn) {
            $value = $context->scope[$scopeColumn] ?? null;
            $qualified = $model->qualifyColumn($scopeColumn);

            $value === null ? $query->whereNull($qualified) : $query->where($qualified, $value);
        }

        if ($definition->scopeClosure !== null) {
            ($definition->scopeClosure)($query, $context->scopeModel ?? $model);
        }

        if (! $definition->includeTrashed && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->whereNull($model->qualifyColumn($this->deletedAtColumn($model)));
        }

        if ($context->ignoreKey !== null) {
            $query->whereKeyNot($context->ignoreKey);
        }

        if ($context->locking) {
            $query->sharedLock();
        }

        return $query;
    }

    /**
     * Retired slugs of other rows of the same type, when the definition avoids reusing them.
     *
     * @param  list<string>  $candidates
     * @return list<string>
     */
    private function historical(ProbeContext $context, ResolvedSlugDefinition $definition, ?string $locale, array $candidates): array
    {
        if (! SlugHistoryModel::tableExists()) {
            return [];
        }

        $query = SlugHistoryModel::query()
            ->where('sluggable_type', $context->model->getMorphClass())
            ->where('column', $definition->column)
            ->where('locale', $locale ?? '')
            ->whereIn('slug', $candidates);

        if ($context->ignoreKey !== null) {
            $query->where('sluggable_id', '!=', $context->ignoreKey);
        }

        return $this->strings($query->pluck('slug')->all());
    }

    /**
     * @param  list<string>  $candidates
     * @param  list<string>  $found
     * @return list<string>
     */
    private function matching(array $candidates, array $found, bool $loosely): array
    {
        if ($found === []) {
            return [];
        }

        $normalize = $loosely
            ? static fn (string $value): string => mb_strtolower(Str::ascii($value))
            : static fn (string $value): string => $value;

        $found = array_map($normalize, $found);

        return array_values(array_filter($candidates, static fn (string $candidate): bool => in_array($normalize($candidate), $found, true)));
    }

    /**
     * MySQL/MariaDB string columns compare in their (default case- and accent-insensitive)
     * collation, so the unique index rejects `Cafe` next to `café`; mirror that approximately.
     */
    private function comparesLoosely(Model $model): bool
    {
        return in_array($model->getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function deletedAtColumn(Model $model): string
    {
        return method_exists($model, 'getDeletedAtColumn') ? (string) $model->getDeletedAtColumn() : 'deleted_at';
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) || is_int($value)) {
                $strings[] = (string) $value;
            }
        }

        return $strings;
    }
}
