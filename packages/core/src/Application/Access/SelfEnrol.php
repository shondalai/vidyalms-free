<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Events\EntitlementGranted;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\EnrolmentContext;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\ContextAwareEnrolmentRule;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentDecision;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleRegistry;

/**
 * A learner enrols themselves in a published free course, gated by the "open"
 * enrolment rule. Paid courses point the learner to the shop instead; the
 * shop's callback lands in GrantEntitlement.
 *
 * Idempotent: an existing active or completed enrolment is returned unchanged
 * and no event is emitted.
 */
final class SelfEnrol
{
    /**
     * What a course with no rule named falls back to, and what the column
     * defaults to.
     */
    public const DEFAULT_RULE_KEY = 'open';

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly DatabasePort $database,
        private readonly EntitlementRepository $entitlements,
        private readonly EnrolmentRepository $enrolments,
        private readonly EnrolLearner $enrolLearner,
        private readonly EnrolmentRuleRegistry $enrolmentRules,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly ?\Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine $availability = null,
        private readonly ?\Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryVisibility $categoryVisibility = null,
    ) {
    }

    /**
     * Whether this principal could self-enrol in this course right now, asked
     * of the same row and the same registered rule that execute() consults.
     *
     * A surface that decides whether to offer an enrol control must ask this
     * rather than re-deriving the answer, because a rule replaced by a site
     * policy or a Pro package may read any column of the row, and a caller
     * that hand-builds a partial row would offer a button the POST refuses.
     */
    public function isAvailableFor(Principal $principal, int $courseId): bool
    {
        if ($principal->isGuest()) {
            return false;
        }

        $course = $this->database->selectOne(Query::from('courses')->where('id', '=', $courseId));

        if ($course === null) {
            return false;
        }

        $existing = $this->enrolments->findByUserAndCourse($principal->userId, $courseId);

        if ($existing !== null) {
            return false;
        }

        if ($course['state'] !== 'published' || $course['price_mode'] !== 'free') {
            return false;
        }

        return $this->decide($principal, $course)->allowed;
    }

    /**
     * The one resolution of a course's enrolment rule, shared by the button
     * and the POST.
     *
     * Before the course carried a rule key this consulted one hardcoded
     * provider, so no registered rule was reachable at all from the free
     * self-enrol path: the seam existed and nothing could enter it. The
     * lookup is guarded, and its miss is a documented refusal rather than an
     * exception escaping into a route, because a course naming a rule whose
     * package was removed must close rather than open.
     *
     * A provider implementing the context-aware interface answers through it,
     * so a refusal can say why; one that does not answers through the shipped
     * boolean method, which is what its default when absent says.
     *
     * @param array<string, mixed> $course
     */
    private function decide(Principal $principal, array $course): EnrolmentDecision
    {
        if ($course['category_id'] !== null && $this->categoryVisibility !== null
            && !in_array((int) $course['category_id'], $this->categoryVisibility->visibleCategoryIds($principal->userId), true)) {
            return EnrolmentDecision::refuse('vidyalms.enrolment.refused', ['rule' => 'category_access']);
        }
        if ($this->availability !== null && !$this->availability->permitsEnrolment($principal, $course)) {
            return EnrolmentDecision::refuse('vidyalms.enrolment.refused', ['rule' => 'enrolment_conditions']);
        }
        if (!CoursePrerequisites::completed($this->database, $principal->userId, (int) $course['id'])) {
            return EnrolmentDecision::refuse('vidyalms.enrolment.refused', ['rule' => 'prerequisites']);
        }
        // Private access must be granted by an authorised course manager.
        // Apply this before any provider, for both the control and the POST.
        if (!\in_array($course['visibility'], [CourseVisibility::Public->value, CourseVisibility::Unlisted->value], true)) {
            return EnrolmentDecision::refuse('vidyalms.enrolment.refused', ['rule' => 'visibility']);
        }

        $ruleKey = $course['enrolment_rule'] ?? null;
        $ruleKey = \is_string($ruleKey) && $ruleKey !== '' ? $ruleKey : self::DEFAULT_RULE_KEY;

        if (!$this->enrolmentRules->has($ruleKey)) {
            return EnrolmentDecision::refuse('vidyalms.enrolment.rule_missing', ['rule' => $ruleKey]);
        }

        $rule = $this->enrolmentRules->get($ruleKey);

        if ($rule instanceof ContextAwareEnrolmentRule) {
            return $rule->allowsSelfEnrolmentIn(EnrolmentContext::create(
                $principal,
                $course,
                $this->clock->now(),
                $this->database,
            ));
        }

        return $rule->allowsSelfEnrolment($principal, $course)
            ? EnrolmentDecision::allow()
            : EnrolmentDecision::refuse('vidyalms.enrolment.refused', ['rule' => $ruleKey]);
    }

    public function execute(Principal $principal, int $courseId): Enrolment
    {
        $this->guard->requireAuthenticated($principal);

        $course = $this->database->selectOne(Query::from('courses')->where('id', '=', $courseId));

        if ($course === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Course', $courseId);
        }

        $existing = $this->enrolments->findByUserAndCourse($principal->userId, $courseId);

        if ($existing !== null && ($existing->isActive() || $existing->isCompleted())) {
            return $existing;
        }

        if ($existing !== null) {
            throw new ConflictException('Ask the course manager to restore your access.');
        }

        if ($course['state'] !== 'published') {
            throw new ConflictException('The course is not open for enrolment.');
        }

        if ($course['price_mode'] !== 'free') {
            throw new ConflictException('This course is paid. Purchase it to enrol.');
        }

        if (!$this->decide($principal, $course)->allowed) {
            throw new ConflictException('Self-enrolment is not available for this course.');
        }

        $entitlement = $this->entitlements->add(
            Entitlement::grant($principal->userId, $courseId, Entitlement::SOURCE_OPEN),
        );

        $this->events->dispatch(new EntitlementGranted(
            $this->clock->now(),
            $entitlement->id(),
            $principal->userId,
            $courseId,
            Entitlement::SOURCE_OPEN,
        ));

        return $this->enrolLearner->execute($principal->userId, $courseId, $entitlement->id());
    }
}
