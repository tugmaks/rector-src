<?php

declare(strict_types=1);

namespace Rector\NodeTypeResolver\TypeComparator;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\ConstantScalarType;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use Rector\BetterPhpDocParser\ValueObject\PhpDocAttributeKey;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PHPStan\Type\TypeFactory;
use Rector\NodeTypeResolver\PHPStan\TypeHasher;
use Rector\StaticTypeMapper\StaticTypeMapper;
use Rector\StaticTypeMapper\ValueObject\Type\AliasedObjectType;
use Rector\TypeDeclaration\TypeNormalizer;

final class TypeComparator
{
    public function __construct(
        private readonly TypeHasher $typeHasher,
        private readonly TypeNormalizer $typeNormalizer,
        private readonly StaticTypeMapper $staticTypeMapper,
        private readonly ArrayTypeComparator $arrayTypeComparator,
        private readonly ScalarTypeComparator $scalarTypeComparator,
        private readonly TypeFactory $typeFactory,
    ) {
    }

    public function areTypesEqual(Type $firstType, Type $secondType): bool
    {
        $firstTypeHash = $this->typeHasher->createTypeHash($firstType);
        $secondTypeHash = $this->typeHasher->createTypeHash($secondType);

        if ($firstTypeHash === $secondTypeHash) {
            return true;
        }

        if ($this->scalarTypeComparator->areEqualScalar($firstType, $secondType)) {
            return true;
        }

        // aliases and types
        if ($this->areAliasedObjectMatchingFqnObject($firstType, $secondType)) {
            return true;
        }

        if ($this->areArrayUnionConstantEqualTypes($firstType, $secondType)) {
            return true;
        }

        $firstType = $this->typeNormalizer->normalizeArrayOfUnionToUnionArray($firstType);
        $secondType = $this->typeNormalizer->normalizeArrayOfUnionToUnionArray($secondType);

        if ($this->typeHasher->areTypesEqual($firstType, $secondType)) {
            return true;
        }

        // is template of
        return $this->areArrayTypeWithSingleObjectChildToParent($firstType, $secondType);
    }

    public function arePhpParserAndPhpStanPhpDocTypesEqual(
        Node $phpParserNode,
        TypeNode $phpStanDocTypeNode,
        Node $node
    ): bool {
        $phpParserNodeType = $this->staticTypeMapper->mapPhpParserNodePHPStanType($phpParserNode);
        $phpStanDocType = $this->staticTypeMapper->mapPHPStanPhpDocTypeNodeToPHPStanType(
            $phpStanDocTypeNode,
            $node
        );

        // normalize bool union types
        $phpParserNodeType = $this->normalizeConstantBooleanType($phpParserNodeType);
        $phpStanDocType = $this->normalizeConstantBooleanType($phpStanDocType);

        // is scalar replace by another - remove it?
        $areDifferentScalarTypes = $this->scalarTypeComparator->areDifferentScalarTypes(
            $phpParserNodeType,
            $phpStanDocType
        );

        if (! $areDifferentScalarTypes && ! $this->areTypesEqual($phpParserNodeType, $phpStanDocType)) {
            return false;
        }

        if ($this->isTypeSelfAndDocParamTypeStatic($phpStanDocType, $phpParserNodeType, $phpStanDocTypeNode)) {
            return false;
        }

        if ($this->areTypesSameWithLiteralTypeInPhpDoc($areDifferentScalarTypes, $phpStanDocType, $phpParserNodeType)) {
            return false;
        }

        return $this->isThisTypeInFinalClass($phpStanDocType, $phpParserNodeType, $node);
    }

    public function isSubtype(Type $checkedType, Type $mainType): bool
    {
        if ($mainType instanceof MixedType) {
            return false;
        }

        if (! $mainType instanceof ArrayType) {
            return $mainType->isSuperTypeOf($checkedType)
                ->yes();
        }

        if (! $checkedType instanceof ArrayType) {
            return $mainType->isSuperTypeOf($checkedType)
                ->yes();
        }

        return $this->arrayTypeComparator->isSubtype($checkedType, $mainType);
    }

    private function areAliasedObjectMatchingFqnObject(Type $firstType, Type $secondType): bool
    {
        if ($firstType instanceof AliasedObjectType && $secondType instanceof ObjectType && $firstType->getFullyQualifiedName() === $secondType->getClassName()) {
            return true;
        }

        if (! $secondType instanceof AliasedObjectType) {
            return false;
        }

        if (! $firstType instanceof ObjectType) {
            return false;
        }

        return $secondType->getFullyQualifiedName() === $firstType->getClassName();
    }

    /**
     * E.g. class A extends B, class B → A[] is subtype of B[] → keep A[]
     */
    private function areArrayTypeWithSingleObjectChildToParent(Type $firstType, Type $secondType): bool
    {
        if (! $firstType instanceof ArrayType) {
            return false;
        }

        if (! $secondType instanceof ArrayType) {
            return false;
        }

        $firstArrayItemType = $firstType->getItemType();
        $secondArrayItemType = $secondType->getItemType();

        if ($this->isMutualObjectSubtypes($firstArrayItemType, $secondArrayItemType)) {
            return true;
        }

        if (! $firstArrayItemType instanceof GenericClassStringType) {
            return false;
        }

        if (! $secondArrayItemType instanceof GenericClassStringType) {
            return false;
        }

        // @todo resolve later better with template map, @see https://github.com/symplify/symplify/pull/3034/commits/4f6be8b87e52117b1aa1613b9b689ae958a9d6f4
        return $firstArrayItemType->getGenericType() instanceof ObjectType && $secondArrayItemType->getGenericType() instanceof ObjectType;
    }

    private function isMutualObjectSubtypes(Type $firstArrayItemType, Type $secondArrayItemType): bool
    {
        if ($firstArrayItemType instanceof ObjectType && $secondArrayItemType instanceof ObjectType) {
            if ($firstArrayItemType->isSuperTypeOf($secondArrayItemType)->yes()) {
                return true;
            }

            if ($secondArrayItemType->isSuperTypeOf($firstArrayItemType)->yes()) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSingleUnionType(Type $type): Type
    {
        if ($type instanceof UnionType) {
            $uniqueTypes = $this->typeFactory->uniquateTypes($type->getTypes());
            if (count($uniqueTypes) === 1) {
                return $uniqueTypes[0];
            }
        }

        return $type;
    }

    private function areArrayUnionConstantEqualTypes(Type $firstType, Type $secondType): bool
    {
        if (! $firstType instanceof ArrayType) {
            return false;
        }

        if (! $secondType instanceof ArrayType) {
            return false;
        }

        $firstKeyType = $this->normalizeSingleUnionType($firstType->getKeyType());
        $secondKeyType = $this->normalizeSingleUnionType($secondType->getKeyType());

        // mixed and integer type are mutual replaceable in practise
        if ($firstKeyType instanceof MixedType) {
            $firstKeyType = new IntegerType();
        }

        if ($secondKeyType instanceof MixedType) {
            $secondKeyType = new IntegerType();
        }

        if (! $this->areTypesEqual($firstKeyType, $secondKeyType)) {
            return false;
        }

        $firstArrayType = $this->normalizeSingleUnionType($firstType->getItemType());
        $secondArrayType = $this->normalizeSingleUnionType($secondType->getItemType());

        return $this->areTypesEqual($firstArrayType, $secondArrayType);
    }

    private function normalizeConstantBooleanType(Type $type): Type
    {
        return TypeTraverser::map($type, function (Type $type, callable $callable): Type {
            if ($type instanceof ConstantBooleanType) {
                return new BooleanType();
            }

            return $callable($type);
        });
    }

    private function isTypeSelfAndDocParamTypeStatic(
        Type $phpStanDocType,
        Type $phpParserNodeType,
        TypeNode $phpStanDocTypeNode
    ): bool {
        return $phpStanDocType instanceof StaticType
            && $phpParserNodeType instanceof ThisType
            && $phpStanDocTypeNode->getAttribute(PhpDocAttributeKey::PARENT) instanceof ParamTagValueNode;
    }

    private function areTypesSameWithLiteralTypeInPhpDoc(
        bool $areDifferentScalarTypes,
        Type $phpStanDocType,
        Type $phpParserNodeType
    ): bool
    {
        return $areDifferentScalarTypes
            && $phpStanDocType instanceof ConstantScalarType
            && $phpParserNodeType->isSuperTypeOf($phpStanDocType)->yes();
    }

    private function isThisTypeInFinalClass(Type $phpStanDocType, Type $phpParserNodeType, Node $node) : bool
    {
        // special case for non-final $this/self compare; in case of interface/abstract class, it can be another $this
        if ($phpStanDocType instanceof ThisType && $phpParserNodeType instanceof ThisType) {
            $scope = $node->getAttribute(AttributeKey::SCOPE);
            if ($scope instanceof Scope) {
                $classReflection = $scope->getClassReflection();
                if ($classReflection instanceof ClassReflection) {
                    return $classReflection->isFinal();
                }
            }
        }

        return true;
    }
}
