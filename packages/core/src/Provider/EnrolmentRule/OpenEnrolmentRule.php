<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\EnrolmentRule;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleInterface;

/**
 * The free build's default gate for self-enrolment: any authenticated user
 * may join a published free course.
 */
final class OpenEnrolmentRule implements EnrolmentRuleInterface
{
    public function __construct(
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return 'open';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.enrolment_rule.open');
    }

    public function allowsSelfEnrolment(Principal $principal, array $course): bool
    {
        if ($principal->isGuest()) {
            return false;
        }

        return ($course['state'] ?? null) === 'published'
            && ($course['price_mode'] ?? null) === 'free';
    }
}
