<?php

declare(strict_types=1);

namespace RoundlyConsulting\Sluggable\PHPStan;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Fluent;
use Illuminate\Validation\Rule;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Type\ArrayType;
use PHPStan\Type\ClassStringType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\UnionType;
use RoundlyConsulting\Sluggable\Rules\UniqueSlug;
use RoundlyConsulting\Sluggable\Rules\ValidSlug;

/**
 * Makes sluggable's macros visible to PHPStan: `Blueprint::slug()/localizedSlug()/uniqueSlug()`
 * and `Rule::uniqueSlug()/validSlug()`. The toolkit already claims Blueprint with a stub (one stub
 * per class is honoured), so these arrive through a reflection extension instead.
 */
final class BlueprintSlugMacrosExtension implements MethodsClassReflectionExtension
{
    private const BLUEPRINT = ['slug', 'localizedSlug', 'uniqueSlug'];

    private const RULE = ['uniqueSlug', 'validSlug'];

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return match ($classReflection->getName()) {
            Blueprint::class => in_array($methodName, self::BLUEPRINT, true),
            Rule::class => in_array($methodName, self::RULE, true),
            default => false,
        };
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $column = new SlugMacroParameter('column', new StringType, true, new ConstantStringType('slug'));

        if ($classReflection->getName() === Rule::class) {
            return new SlugMacroMethodReflection($classReflection, $methodName, true, [
                new SlugMacroParameter('modelClass', new ClassStringType, false),
                new SlugMacroParameter('column', new UnionType([new StringType, new NullType]), true, new NullType),
            ], new ObjectType($methodName === 'uniqueSlug' ? UniqueSlug::class : ValidSlug::class));
        }

        return match ($methodName) {
            'slug' => new SlugMacroMethodReflection($classReflection, $methodName, false, [
                $column,
                new SlugMacroParameter('length', new IntegerType, true, new ConstantIntegerType(255)),
            ], new ObjectType(ColumnDefinition::class)),
            'localizedSlug' => new SlugMacroMethodReflection($classReflection, $methodName, false, [$column], new ObjectType(ColumnDefinition::class)),
            default => new SlugMacroMethodReflection($classReflection, $methodName, false, [
                $column,
                new SlugMacroParameter('scope', new ArrayType(new MixedType, new StringType), true, new ConstantArrayType([], [])),
            ], new ObjectType(Fluent::class)),
        };
    }
}
