<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Tests\Fixtures;

use Closure;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;

/**
 * Lets one fixture model carry whatever slug definition a test needs.
 */
final class Configurable
{
    /** @var array<class-string, Closure(): SlugOptions> */
    private static array $options = [];

    /**
     * @param  class-string  $class
     * @param  Closure(): SlugOptions  $options
     */
    public static function set(string $class, Closure $options): void
    {
        self::$options[$class] = $options;
    }

    /** @param class-string $class */
    public static function for(string $class): ?SlugOptions
    {
        return isset(self::$options[$class]) ? (self::$options[$class])() : null;
    }

    public static function reset(): void
    {
        self::$options = [];
    }
}
