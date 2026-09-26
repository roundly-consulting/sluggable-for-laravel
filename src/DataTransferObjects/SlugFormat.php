<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\DataTransferObjects;

use Closure;

/**
 * The formatting slice of a resolved slug definition — everything the pure pipeline needs.
 */
final readonly class SlugFormat
{
    /**
     * @param  array<string, string>  $dictionary
     * @param  list<string>  $reserved  lowercased
     * @param  (Closure(string, ?string): mixed)|null  $slugger
     */
    public function __construct(
        public string $separator = '-',
        public int $maxLength = 255,
        public ?int $maxWords = null,
        public array $dictionary = ['@' => 'at'],
        public bool $lowercase = true,
        public bool $unicode = false,
        public array $reserved = [],
        public ?Closure $slugger = null,
        public int $maxSourceLength = 2000,
    ) {}
}
