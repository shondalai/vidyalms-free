<?php

declare(strict_types=1);

/**
 * VidyaLMS: the site owner's self enrolment switch, expressed as a provider.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Provider;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\Context\EnrolmentContext;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\ContextAwareEnrolmentRule;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentDecision;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleInterface;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleRegistry;
use Shondalai\VidyaLMS\WordPress\Setup\Settings;

defined('ABSPATH') || exit;

/**
 * The "learners may enrol themselves" site setting, sitting in front of the
 * every registered self-enrolment rule, including context-aware Pro rules.
 *
 * A site policy is not a learning rule, but it has to be enforced where the
 * learning rules are, because the rule is the one thing every caller passes
 * through: the course page, the form POST behind it, POST /enrolments on the
 * REST mount, WP-CLI, and another plugin calling the SelfEnrol use case. This
 * adapter used to guard the switch at the entry points it happened to own, in
 * three separate places, and an adapter guard only ever covers the routes that
 * adapter wrote.
 *
 * It decorates rather than reimplements. install() keeps whatever rule owned
 * the key and delegates to it whenever the switch is on, so the free build's
 * OpenEnrolmentRule goes on deciding published and free, and a Pro rule
 * installed ahead of this one goes on deciding whatever it decides. This class
 * only ever subtracts.
 *
 * Each key stays unchanged because stored rows name provider keys and the core
 * keeps every key resolvable. Switching a capability off is a provider that
 * refuses, never a provider that was removed.
 */
final class SiteSelfEnrolmentRule implements EnrolmentRuleInterface, ContextAwareEnrolmentRule
{
    /**
     * The default rule; install() returns its wrapper for callers inspecting it.
     */
    public const KEY = 'open';

    /**
     * Whether learners may enrol themselves. Read here and nowhere else, so a
     * third party answering it changes every path rather than only the page
     * that renders the button.
     */
    public const FILTER_ENABLED = 'vidyalms/self_enrolment_enabled';

    /**
     * @param \Closure(int): bool $enabled reads the switch for one course id
     */
    private function __construct(
        private readonly EnrolmentRuleInterface $previous,
        private readonly \Closure $enabled,
    )
    {
    }

    /**
     * Puts the switch in front of whichever rule currently owns the key.
     *
     * replace() answers with the rule it displaced, and that rule becomes the
     * delegate, which is what lets this class gate a rule without naming it or
     * knowing what it decides.
     *
     * @param (callable(int): bool)|null $enabled the switch, defaulting to the
     *                                            site setting and its filter
     */
    public static function install(EnrolmentRuleRegistry $rules, ?callable $enabled = null): ?self
    {
        $gate = $enabled === null
            ? static fn (int $courseId): bool => self::siteAllows($courseId)
            : \Closure::fromCallable($enabled);
        $open = null;
        foreach ($rules->all() as $previous) {
            $rule = $previous instanceof self ? $previous : new self($previous, $gate);
            if ($previous !== $rule) {
                $rules->replace($rule);
            }
            if ($rule->key() === self::KEY) {
                $open = $rule;
            }
        }
        return $open;
    }

    public function key(): string
    {
        return $this->previous->key();
    }

    public function label(): string
    {
        return $this->previous->label();
    }

    /**
     * @param array<string, mixed> $course the course row the displaced rule inspects
     */
    public function allowsSelfEnrolment(Principal $principal, array $course): bool
    {
        if (!($this->enabled)((int) ($course['id'] ?? 0))) {
            return false;
        }

        return $this->previous->allowsSelfEnrolment($principal, $course);
    }

    public function allowsSelfEnrolmentIn(EnrolmentContext $context): EnrolmentDecision
    {
        if (!$context->siteAllowsSelfEnrolment || !($this->enabled)($context->courseId())) {
            return EnrolmentDecision::refuse('vidyalms.enrolment.refused', ['rule' => 'site_policy']);
        }
        if ($this->previous instanceof ContextAwareEnrolmentRule) {
            return $this->previous->allowsSelfEnrolmentIn($context);
        }
        return $this->previous->allowsSelfEnrolment($context->principal, $context->course)
            ? EnrolmentDecision::allow()
            : EnrolmentDecision::refuse('vidyalms.enrolment.refused', ['rule' => $this->key()]);
    }

    private static function siteAllows(int $courseId): bool
    {
        /**
         * Filters whether learners may enrol themselves.
         *
         * The site setting is the default. Answering false refuses self
         * enrolment everywhere: the enrol button is not rendered, the form POST
         * comes back as a conflict, and POST /enrolments answers 409, because
         * all three ask the same enrolment rule.
         *
         * @param bool $enabled  Whether learners may enrol themselves.
         * @param int  $courseId Course being enrolled into, 0 when unknown.
         */
        return (bool) apply_filters('vidyalms/self_enrolment_enabled', Settings::selfEnrolmentEnabled(), $courseId);
    }

}
