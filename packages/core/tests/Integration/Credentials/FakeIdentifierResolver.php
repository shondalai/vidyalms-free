<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\ContributesIssuanceParameters;
use Shondalai\VidyaLMS\Core\Registry\Token\DeclaresUnfrozenTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * A family that can answer a plaintext identifier and says which of its own
 * tokens must never reach the frozen snapshot.
 *
 * This is the shape the design's own credit_scheme_identities table exists
 * for: a certifying board id is answerable and is not a fact a credential
 * should carry for seven years.
 */
final class FakeIdentifierResolver implements
    TokenResolverInterface,
    ContributesIssuanceParameters,
    DeclaresUnfrozenTokens
{
    public const PREFIX = 'board';

    public function key(): string
    {
        return self::PREFIX;
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function label(): string
    {
        return 'Board';
    }

    public function tokens(): array
    {
        return ['board.name', 'board.member_id'];
    }

    public function neverFrozen(): array
    {
        return ['board.member_id'];
    }

    public function parametersFor(IssuanceParameterContext $context): array
    {
        return ['board_name' => 'Northlight Board', 'board_member_id' => 'MB-99001'];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        return match ($token) {
            'board.name' => $context->string('board_name'),
            'board.member_id' => $context->string('board_member_id'),
            default => null,
        };
    }
}
