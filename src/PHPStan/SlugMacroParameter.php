<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\PHPStan;

use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\PassedByReference;
use PHPStan\Type\Type;

/**
 * One parameter of a sluggable macro (hand-rolled: PHPStan's DummyParameter is outside its BC
 * promise).
 */
final readonly class SlugMacroParameter implements ParameterReflection
{
    public function __construct(
        private string $name,
        private Type $type,
        private bool $optional,
        private ?Type $defaultValue = null,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function isOptional(): bool
    {
        return $this->optional;
    }

    public function getType(): Type
    {
        return $this->type;
    }

    public function passedByReference(): PassedByReference
    {
        return PassedByReference::createNo();
    }

    public function isVariadic(): bool
    {
        return false;
    }

    public function getDefaultValue(): ?Type
    {
        return $this->defaultValue;
    }
}
