<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Models\SlugHistory;

/**
 * The one place the (possibly host-swapped) history model is resolved.
 */
final class SlugHistoryModel
{
    /** @return class-string<SlugHistory> */
    public static function class(): string
    {
        return SluggableConfig::historyModel();
    }

    public static function new(): SlugHistory
    {
        $class = self::class();

        return new $class;
    }

    /** @return Builder<SlugHistory> */
    public static function query(): Builder
    {
        return self::new()->newQuery();
    }

    public static function tableExists(): bool
    {
        $model = self::new();

        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }

    /**
     * History stores the slugged model's id in a column typed by `sluggable.key_type`; refuse a
     * model whose key cannot fit, before a save writes the row and the history insert fails.
     */
    public static function assertKeyType(Model $model): void
    {
        $configured = SluggableConfig::keyType();
        $modelKeyType = $model->getKeyType();

        $fits = match ($configured) {
            KeyType::BigInt => in_array($modelKeyType, ['int', 'integer'], true),
            KeyType::Uuid, KeyType::Ulid => $modelKeyType === 'string',
        };

        if (! $fits) {
            throw InvalidSlugDefinitionException::historyKeyTypeMismatch($model::class, $modelKeyType, $configured->value);
        }
    }
}
