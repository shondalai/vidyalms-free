<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryContext;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyRegistry;

/**
 * The template's expiry setting, turned into an instant, in the one place
 * both issuance paths read.
 *
 * It exists for the reason IssuanceSnapshot exists: the issue path and the
 * reissue path had a byte-for-byte duplicate of the same three-mode if-chain,
 * and expiry was the one part of the freeze that stayed hand-built twice. It
 * resolves the registry rather than branching on literals, so a fourth shape
 * is a registration and the answer a certificate prints is the answer any
 * later sweep would compute.
 *
 * The miss direction is the registry's published one: an unresolved policy,
 * or one that is not configured, means the credential never expires. A
 * credential wrongly reported as lapsed is a credential wrongly refused; one
 * wrongly reported as in force is corrected by revoking it, which this design
 * already has a mechanism for.
 */
final class ResolveExpiry
{
    public function __construct(
        private readonly ExpiryPolicyRegistry $policies,
        private readonly string $siteTimezone = 'UTC',
    ) {
    }

    /**
     * @param array<string, mixed>|null $template the certificate_templates row
     */
    public function execute(
        ?array $template,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $completedAt = null,
        ?\DateTimeImmutable $validFrom = null,
    ): ?\DateTimeImmutable {
        if ($template === null) {
            return null;
        }

        $mode = \is_string($template['expiry_mode'] ?? null) && $template['expiry_mode'] !== ''
            ? $template['expiry_mode']
            : 'never';

        if (!$this->policies->has($mode)) {
            return null;
        }

        $context = ExpiryContext::create(
            $issuedAt,
            $completedAt,
            $validFrom,
            is_numeric($template['expiry_days'] ?? null) ? (int) $template['expiry_days'] : null,
            self::moment($template['expiry_at'] ?? null),
            $this->siteTimezone,
            is_numeric($template['id'] ?? null) ? (int) $template['id'] : null,
        );

        $policy = $this->policies->get($mode);

        return $policy->isConfigured($context) ? $policy->expiresAt($context) : null;
    }

    private static function moment(mixed $stored): ?\DateTimeImmutable
    {
        if (!\is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($stored, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
