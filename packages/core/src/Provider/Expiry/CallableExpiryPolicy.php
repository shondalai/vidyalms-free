<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Expiry;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryContext;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyInterface;

/**
 * One expiry rule, expressed as the closure it is.
 *
 * A shipped class per one-line rule would be several files of ceremony, and
 * the ceremony is what stops a reader seeing that the rules differ by one
 * expression each. The key, the label and the configured test are still per
 * provider, so nothing about the registration is weaker than a hand-written
 * class would be.
 */
final class CallableExpiryPolicy implements ExpiryPolicyInterface
{
    /**
     * @param callable(ExpiryContext): ?\DateTimeImmutable $rule
     * @param callable(ExpiryContext): bool                $configured
     */
    public function __construct(
        private readonly string $key,
        private readonly string $labelKey,
        private readonly TranslatorPort $translator,
        private readonly mixed $rule,
        private readonly mixed $configured,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->translator->translate($this->labelKey);
    }

    public function expiresAt(ExpiryContext $context): ?\DateTimeImmutable
    {
        /** @var callable(ExpiryContext): ?\DateTimeImmutable $rule */
        $rule = $this->rule;

        return $rule($context);
    }

    public function isConfigured(ExpiryContext $context): bool
    {
        /** @var callable(ExpiryContext): bool $configured */
        $configured = $this->configured;

        return $configured($context);
    }
}
