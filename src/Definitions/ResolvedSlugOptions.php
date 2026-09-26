<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Definitions;

use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;

/**
 * Every resolved definition of one model, in declaration order.
 */
final readonly class ResolvedSlugOptions
{
    /**
     * @param  class-string  $model
     * @param  list<ResolvedSlugDefinition>  $definitions
     */
    public function __construct(
        public string $model,
        public array $definitions,
    ) {}

    /** The routeKey() column, else the first definition. */
    public function default(): ResolvedSlugDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->routeKey) {
                return $definition;
            }
        }

        return $this->definitions[0];
    }

    public function routeKey(): ?ResolvedSlugDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->routeKey) {
                return $definition;
            }
        }

        return null;
    }

    public function has(string $column): bool
    {
        return $this->find($column) !== null;
    }

    public function find(string $column): ?ResolvedSlugDefinition
    {
        foreach ($this->definitions as $definition) {
            if ($definition->column === $column) {
                return $definition;
            }
        }

        return null;
    }

    public function get(?string $column = null): ResolvedSlugDefinition
    {
        if ($column === null) {
            return $this->default();
        }

        return $this->find($column) ?? throw InvalidSlugDefinitionException::unknownColumn($this->model, $column);
    }

    /** @return list<string> */
    public function columns(): array
    {
        return array_map(static fn (ResolvedSlugDefinition $definition): string => $definition->column, $this->definitions);
    }
}
