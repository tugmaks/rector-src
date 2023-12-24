<?php

declare(strict_types=1);

namespace Rector\Core\Reflection;

use PhpParser\Node;
use PHPStan\Reflection\ClassReflection;

final class ClassModifierChecker
{
    public function __construct(
        private readonly ReflectionResolver $reflectionResolver
    ) {
    }

    public function isInsideFinalClass(Node $node): bool
    {
        $classReflection = $this->reflectionResolver->resolveClassReflection($node);
        if (! $classReflection instanceof ClassReflection) {
            return false;
        }

        return $classReflection->isFinalByKeyword();
    }

    public function isInsideAbstractClass(Node $node): bool
    {
        $classReflection = $this->reflectionResolver->resolveClassReflection($node);
        if (! $classReflection instanceof ClassReflection) {
            return false;
        }

        return $classReflection->isAbstract();
    }
}