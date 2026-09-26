<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

/**
 * A slug value shared by several rows of one scope (or, with `overLength`, a single value longer
 * than the definition's maxLength).
 */
final readonly class SlugDuplicate
{
    /**
     * @param  array<string, int|string|null>  $scope  scope column => value
     * @param  list<int|string>  $keys
     */
    public function __construct(
        public string $column,
        public ?string $locale,
        public string $slug,
        public array $scope,
        public array $keys,
        public bool $overLength = false,
    ) {}
}
