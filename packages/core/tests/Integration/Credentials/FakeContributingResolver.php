<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\ContributesIssuanceParameters;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * A token family that supplies its own inputs, which is the whole point of
 * the seam: nothing in the core knows this prefix exists.
 */
final class FakeContributingResolver implements TokenResolverInterface, ContributesIssuanceParameters
{
    public int $creditCount = 0;

    public function key(): string
    {
        return 'fake';
    }

    public function prefix(): string
    {
        return 'fake';
    }

    public function label(): string
    {
        return 'Fake';
    }

    public function tokens(): array
    {
        return ['fake.echo'];
    }

    public function parametersFor(IssuanceParameterContext $context): array
    {
        $this->creditCount = \count($context->credits);

        return ['fake_echo' => ($context->courseTitle ?? '') . '/' . ($context->userId ?? 0)];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        if ($token !== 'fake.echo') {
            return null;
        }

        $value = $context->parameters['fake_echo'] ?? null;

        return \is_string($value) ? $value : null;
    }
}
