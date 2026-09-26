<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Definitions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;
use RoundlyConsulting\Sluggable\Support\SluggableConfig;

/**
 * The ordered set of slug definitions of one model.
 */
final class SlugOptions
{
    /** @var list<SlugDefinition> */
    private array $definitions = [];

    private function __construct() {}

    public static function make(SlugDefinition ...$definitions): self
    {
        $options = new self;

        foreach ($definitions as $definition) {
            $options->add($definition);
        }

        return $options;
    }

    /** One definition built from `sluggable.defaults.column` / `.source`. */
    public static function defaults(): self
    {
        return self::make(
            SlugDefinition::for(SluggableConfig::defaultColumn())->from(SluggableConfig::defaultSource()),
        );
    }

    public function add(SlugDefinition $definition): static
    {
        $this->definitions[] = $definition;

        return $this;
    }

    public function get(string $column): SlugDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->column() === $column) {
                return $definition;
            }
        }

        throw InvalidSlugDefinitionException::unknownColumn('slug options', $column);
    }

    /** @return list<string> */
    public function columns(): array
    {
        return array_map(static fn (SlugDefinition $definition): string => $definition->column(), $this->definitions);
    }

    /** @return list<SlugDefinition> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function resolve(Model $model): ResolvedSlugOptions
    {
        if ($this->definitions === []) {
            throw InvalidSlugDefinitionException::invalidOption('slugOptions', 'declare at least one slug definition');
        }

        $seen = [];
        $routeKeys = 0;
        $resolved = [];

        foreach ($this->definitions as $definition) {
            if (isset($seen[$definition->column()])) {
                throw InvalidSlugDefinitionException::duplicateColumn($model::class, $definition->column());
            }

            $seen[$definition->column()] = true;
            $routeKeys += $definition->isRouteKey() ? 1 : 0;

            if ($routeKeys > 1) {
                throw InvalidSlugDefinitionException::multipleRouteKeys($model::class);
            }

            $resolved[] = $definition->resolve($model);
        }

        return new ResolvedSlugOptions($model::class, $resolved);
    }
}
