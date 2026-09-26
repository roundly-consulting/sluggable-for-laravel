<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugDefinition;
use RoundlyConsulting\Sluggable\Definitions\ResolvedSlugOptions;

/**
 * Recognises a unique violation that is about a slug — by every index / generated-column name
 * sluggable can emit, the common host names, or the bare column in the engine's key detail. A
 * violation naming none of them (an unrelated `email` unique) is never retried.
 */
final class UniqueViolationMatcher
{
    /** @param list<string> $locales */
    public function match(UniqueConstraintViolationException $exception, Model $model, ResolvedSlugOptions $options, array $locales): ?ResolvedSlugDefinition
    {
        $message = $this->driverMessage($exception);

        foreach ($options->definitions as $definition) {
            if (! $definition->isUnique()) {
                continue;
            }

            foreach ($this->names($model->getTable(), $definition, $locales) as $name) {
                if (str_contains($message, $name)) {
                    return $definition;
                }
            }

            // The bare column, as a whole word: `articles.slug`, `Key (slug)=`, `(slug ->> 'en')`.
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($definition->column, '/').'(?![A-Za-z0-9_])/', $message) === 1) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Only the engine's own error text: the QueryException message also carries the SQL (which
     * names every column), and a key detail carries the offending VALUES — neither says which
     * constraint failed.
     */
    private function driverMessage(UniqueConstraintViolationException $exception): string
    {
        $message = $exception->getPrevious()?->getMessage() ?? $exception->getMessage();
        $message = (string) preg_replace('/\s\(Connection: .*$/s', '', $message);

        // `Key (slug)=(value)` → `Key (slug)`; `Duplicate entry 'value' for key` → `for key`.
        $message = (string) preg_replace('/\)=\(.*?\)(?= already exists)/s', ')', $message);

        return (string) preg_replace("/Duplicate entry '.*?' for key/s", 'Duplicate entry for key', $message);
    }

    /**
     * @param  list<string>  $locales
     * @return list<string>
     */
    private function names(string $table, ResolvedSlugDefinition $definition, array $locales): array
    {
        $column = $definition->column;

        $names = [
            IndexNames::index($table, $column),
            IndexNames::liveColumn($column),
            "{$table}_{$column}_unique",
        ];

        // Laravel's default name for a scoped `unique([...scope, column])`: MySQL's message
        // carries only the key name, never the columns, so the bare-column match cannot find it.
        if ($definition->hasScopeColumns()) {
            $names[] = strtolower($table.'_'.implode('_', $definition->scopeColumns).'_'.$column.'_unique');
        }

        foreach ($locales as $locale) {
            $names[] = IndexNames::index($table, $column, $locale);
            $names[] = IndexNames::localeColumn($column, $locale);
            $names[] = "{$table}_{$column}_{$locale}_unique";
        }

        return $names;
    }
}
