<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\Support;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use ReflectionMethod;
use RoundlyConsulting\Sluggable\Attributes\Slug;
use RoundlyConsulting\Sluggable\Concerns\HasSlug;
use RoundlyConsulting\Sluggable\Definitions\SlugOptions;
use RoundlyConsulting\Sluggable\Exceptions\InvalidSlugDefinitionException;

/**
 * Per-class reflection cache for #[Slug] attributes and slugOptions() overrides. The cache holds
 * only what the class source declares, so it is immutable per deploy (Octane-safe); flush() is for
 * tests.
 */
final class SlugOptionsRegistry
{
    /** @var array<class-string, list<Slug>> */
    private static array $attributes = [];

    /** @var array<class-string, bool> */
    private static array $overrides = [];

    /**
     * What a model gets when it does not override slugOptions(): its #[Slug] attributes, else the
     * config defaults.
     */
    public static function optionsFor(Model $model): SlugOptions
    {
        $attributes = self::attributesFor($model::class);

        if ($attributes === []) {
            return SlugOptions::defaults();
        }

        return SlugOptions::make(...array_map(
            static fn (Slug $attribute) => $attribute->toDefinition(),
            $attributes,
        ));
    }

    /**
     * @param  class-string  $class
     * @return list<Slug>
     */
    public static function attributesFor(string $class): array
    {
        if (! isset(self::$attributes[$class])) {
            $attributes = [];
            $reflection = new ReflectionClass($class);

            do {
                foreach ($reflection->getAttributes(Slug::class) as $attribute) {
                    $attributes[] = $attribute->newInstance();
                }

                $reflection = $attributes === [] ? $reflection->getParentClass() : false;
            } while ($reflection !== false);

            self::$attributes[$class] = $attributes;
        }

        return self::$attributes[$class];
    }

    /**
     * Whether the class overrides the trait's slugOptions(). PHP reports a trait method as
     * declared by the using class, so the declaring class cannot tell an override from the trait
     * default — the defining FILE can.
     *
     * @param  class-string  $class
     */
    public static function overridesSlugOptions(string $class): bool
    {
        return self::$overrides[$class] ??= (new ReflectionMethod($class, 'slugOptions'))->getFileName()
            !== (new ReflectionClass(HasSlug::class))->getFileName();
    }

    /**
     * Attributes AND an override on one class: the override wins. Loud in local/testing (the
     * first test catches it), silent in production (never a 500 for a harmless redundancy).
     *
     * @param  class-string  $class
     */
    public static function assertNoConflict(string $class): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        if (self::attributesFor($class) !== [] && self::overridesSlugOptions($class)) {
            throw InvalidSlugDefinitionException::conflictingSources($class);
        }
    }

    public static function flush(): void
    {
        self::$attributes = [];
        self::$overrides = [];
    }
}
