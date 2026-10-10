<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\EnrolmentRule;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleInterface;

/**
 * Staff-only access: learners never self-enrol, an admin grants the
 * entitlement through GrantEntitlement.
 */
final class ManualEnrolmentRule implements EnrolmentRuleInterface
{
    public function __construct(
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.enrolment_rule.manual');
    }

    public function allowsSelfEnrolment(Principal $principal, array $course): bool
    {
        return false;
    }
}
