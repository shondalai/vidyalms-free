<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceStatus;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceSubject;
use Shondalai\VidyaLMS\Core\Domain\Compliance\RenewalStatus;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\ComplianceContext;

/**
 * A third-party rule that computes everything from the expiry, which is the
 * natural shape and the one the renewal machine refuses.
 *
 * It answers `compliant` and `renewal_overdue` together from a row standing at
 * `not_due`, which is what a rule reasoning from dates alone says about a
 * certification that lapsed while nobody swept. `not_due -> renewal_overdue`
 * is not an edge, so the renewal word is refused; the compliance word is not,
 * and used to be discarded with it, for ever, on every later run.
 */
final class BandSkippingRule implements ComplianceRuleInterface
{
    public const KEY = 'acme.skips-the-notice-band';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'acme.compliance_rule.skips_band';
    }

    public function currentShape(): int
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function paramsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false];
    }

    public function evaluate(ComplianceSubject $subject, ComplianceContext $context): ComplianceState
    {
        if ($subject->completedAt === null) {
            return ComplianceState::of(
                ComplianceStatus::ASSIGNED,
                RenewalStatus::NOT_DUE,
                null,
                $context->now->modify('+30 days'),
            );
        }

        return ComplianceState::of(
            ComplianceStatus::COMPLIANT,
            RenewalStatus::RENEWAL_OVERDUE,
            null,
            $context->now->modify('+30 days'),
        );
    }
}
