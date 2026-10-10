<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ComplianceRule;

use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceSubject;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\ComplianceContext;

/**
 * @extends AbstractProviderRegistry<ComplianceRuleInterface>
 */
final class ComplianceRuleRegistry extends AbstractProviderRegistry
{
    /**
     * The key a requirement carries when nothing tracks it, which is every free
     * requirement.
     *
     * It is a stored word rather than a null column so the question "which rule
     * decided this" always has an answer, and so the free console can render
     * "assigned, untracked" from the row instead of from an absence.
     */
    public const NONE = 'none';

    protected function providerInterface(): string
    {
        return ComplianceRuleInterface::class;
    }

    /**
     * The evaluation, with the published miss direction applied here rather
     * than in each caller.
     *
     * **An unresolved rule is never compliant.** This registry is the one whose
     * obvious reading is the dangerous one: a person with no rule looks like a
     * person with nothing outstanding, and an organisation that reads that as
     * compliant discovers at an audit that its evidence was an empty registry.
     * So a miss answers assigned and untracked, carrying the reason, and the
     * free build takes that answer for every assignment it holds.
     */
    public function evaluateThrough(
        ?string $key,
        ComplianceSubject $subject,
        ComplianceContext $context,
    ): ComplianceState {
        if ($key === null || $key === '' || $key === self::NONE) {
            return ComplianceState::untracked();
        }

        if (!$this->has($key)) {
            return ComplianceState::untracked('vidyalms.compliance.rule_missing:' . $key);
        }

        return $this->get($key)->evaluate($subject, $context);
    }
}
