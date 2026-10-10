<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryContext;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyInterface;

/**
 * An expiry shape the core's own vocabulary does not name, which is the case
 * the three-literal if-chain could never answer.
 */
final class FakeAnniversaryPolicy implements ExpiryPolicyInterface
{
    public const KEY = 'fake.anniversary';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Fake anniversary';
    }

    public function expiresAt(ExpiryContext $context): ?\DateTimeImmutable
    {
        // A policy is allowed to answer "never", which is the registry's
        // published miss direction expressed by a provider rather than by an
        // absence, so this really is a nullable return.
        return $context->templateId === null ? null : $context->issuedAt->modify('+1 year');
    }

    public function isConfigured(ExpiryContext $context): bool
    {
        return true;
    }
}
