<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Provider;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleInterface;

/**
 * The site owner's self enrolment switch, expressed as an enrolment rule.
 *
 * It decorates whichever rule held the key before it (the free build's
 * OpenEnrolmentRule) rather than restating what that rule decides: with the
 * switch on it delegates untouched, with the switch off it refuses. Every
 * learning rule stays in the core, and this class holds exactly one thing the
 * core cannot know, which is what the administrator ticked.
 *
 * It is a provider and not a guard in a controller because a guard covers only
 * the routes the adapter that wrote it happens to own. The server-rendered
 * page, the enrol task and POST /enrolments all reach SelfEnrol, which resolves
 * this provider from the registry when it runs, so there is one enforcement
 * point and no second copy to drift from it.
 *
 * The switch is read on every question, not captured at boot, so a change in
 * the component options takes effect on the next request without a cache flush.
 *
 * @since 1.0.0
 */
final class SiteSelfEnrolmentRule implements EnrolmentRuleInterface
{
    /**
     * @param   EnrolmentRuleInterface  $previous  The rule this one displaced, and the only thing that
     *                                             decides whether the course itself is joinable.
     * @param   \Closure(): bool        $enabled   Reads the site switch when asked.
     */
    public function __construct(
        private readonly EnrolmentRuleInterface $previous,
        private readonly \Closure $enabled,
    ) {
    }

    /**
     * The displaced rule's key, because a decorator that answered anything else
     * would leave the key it replaced pointing at nothing.
     */
    public function key(): string
    {
        return $this->previous->key();
    }

    public function label(): string
    {
        return $this->previous->label();
    }

    /**
     * @param   array<string, mixed>  $course  The course row the wrapped rule inspects.
     */
    public function allowsSelfEnrolment(Principal $principal, array $course): bool
    {
        if (!($this->enabled)()) {
            return false;
        }

        return $this->previous->allowsSelfEnrolment($principal, $course);
    }
}
