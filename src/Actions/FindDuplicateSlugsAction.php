<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Actions;

use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Sluggable\DataTransferObjects\DuplicateScan;
use RoundlyConsulting\Sluggable\DataTransferObjects\SlugDuplicate;
use RoundlyConsulting\Sluggable\Support\IdentifierGuard;
use RoundlyConsulting\Sluggable\Support\LocaleMapAccessor;
use RoundlyConsulting\Sluggable\Support\SlugState;
use RoundlyConsulting\Sluggable\Support\UniquenessChecker;

/**
 * Audit existing rows before adding a unique index: slug values shared by several rows of one
 * scope (and locale), plus values longer than the definition allows.
 */
final readonly class FindDuplicateSlugsAction
{
    public function __construct(
        private SlugState $state,
    ) {}

    /** @return list<SlugDuplicate> */
    public function execute(DuplicateScan $scan): array
    {
        $prototype = new ($scan->modelClass);
        $locale = $scan->locale === null ? null : IdentifierGuard::locale($scan->locale);
        $keyName = $prototype->getKeyName();
        $findings = [];

        foreach ($this->state->options($prototype)->definitions as $definition) {
            if ($scan->column !== null && $definition->column !== $scan->column) {
                continue;
            }

            $query = $prototype->newQueryWithoutScopes()->toBase()
                ->select([$keyName, $definition->column, ...$definition->scopeColumns]);

            if (! $definition->includeTrashed && in_array(SoftDeletes::class, class_uses_recursive($prototype), true)) {
                $query->whereNull(method_exists($prototype, 'getDeletedAtColumn') ? (string) $prototype->getDeletedAtColumn() : 'deleted_at');
            }

            // A string column on MySQL/MariaDB groups the way its collation (and so its unique
            // index) compares; JSON paths compare binary everywhere.
            $loosely = ! $definition->isLocalized() && UniquenessChecker::comparesLoosely($prototype);

            /** @var array<string, SlugDuplicate> $groups */
            $groups = [];

            foreach ($query->lazyById(1000, $keyName) as $row) {
                $attributes = (array) $row;
                $key = $attributes[$keyName] ?? null;

                if (! is_int($key) && ! is_string($key)) {
                    continue;
                }

                $scope = [];

                foreach ($definition->scopeColumns as $column) {
                    $value = $attributes[$column] ?? null;
                    $scope[$column] = is_int($value) || is_string($value) ? $value : null;
                }

                $values = $definition->isLocalized()
                    ? LocaleMapAccessor::decode($attributes[$definition->column] ?? null)
                    : (is_string($attributes[$definition->column] ?? null) && $attributes[$definition->column] !== '' ? ['' => $attributes[$definition->column]] : []);

                foreach ($values as $valueLocale => $slug) {
                    $slugLocale = $valueLocale === '' ? null : $valueLocale;

                    if ($locale !== null && $slugLocale !== $locale) {
                        continue;
                    }

                    if (mb_strlen($slug) > $definition->format->maxLength) {
                        $findings[] = new SlugDuplicate($definition->column, $slugLocale, $slug, $scope, [$key], true);
                    }

                    $group = json_encode([$scope, $slugLocale, UniquenessChecker::comparable($slug, $loosely)], JSON_THROW_ON_ERROR);
                    $existing = $groups[$group] ?? null;

                    $groups[$group] = new SlugDuplicate(
                        $definition->column,
                        $slugLocale,
                        $existing->slug ?? $slug,
                        $scope,
                        [...($existing->keys ?? []), $key],
                    );
                }
            }

            foreach ($groups as $group) {
                if (count($group->keys) > 1) {
                    $findings[] = $group;
                }
            }
        }

        return $findings;
    }
}
