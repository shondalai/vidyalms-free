<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;

/**
 * Which shape one authored question actually has, asked of the provider in
 * one place so no caller invents its own precedence.
 *
 * Three declarations exist and they are in order of how much they know:
 * DefinitionAwareShape knows this item, ShapeDeclaringInterface and
 * DefinitionContractInterface know the type, and a type that declares none
 * is single/string, which is the documented default and what the generic
 * fallback renderer shows.
 *
 * It used to be spelled out at each call site as a match on the two type
 * level interfaces, which is why adding the third would otherwise have been
 * an edit in every one of them and a missed one somewhere.
 */
final class ShapeForDefinition
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function of(QuestionTypeInterface $provider, array $definition): ResponseShape
    {
        if ($provider instanceof DefinitionAwareShape) {
            return $provider->responseShapeFor($definition);
        }

        return self::ofType($provider) ?? ResponseShape::of(Cardinality::Single, BaseType::StringValue);
    }

    /**
     * The type's own declaration, with no definition in hand: what the
     * picker, the policy guard and the generated catalogue ask about. Null
     * when the type declares none at all.
     */
    public static function ofType(QuestionTypeInterface $provider): ?ResponseShape
    {
        return match (true) {
            $provider instanceof ShapeDeclaringInterface => $provider->responseShape(),
            $provider instanceof DefinitionContractInterface => $provider->responseShape(),
            default => null,
        };
    }
}
