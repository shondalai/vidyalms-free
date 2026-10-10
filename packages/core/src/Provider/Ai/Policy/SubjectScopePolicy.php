<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Application\Ai\AiSubjectCourse;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * The subject has to be inside the scope the caller was authorised for.
 *
 * ContentScopePolicy asks whether this person may open the course the run
 * NAMES. That is a necessary question and it is not sufficient, because a run
 * carries two independent halves: the scope it was authorised under and the
 * subject it is pointed at. Nothing bound them, and a fact provider cannot
 * bind them either: it is handed a subject id and narrows on that id, so
 * `question 1 of another teacher's course` and `question 1 of my own` are the
 * same read to every one of the eighteen.
 *
 * The consequence was a real read across a tenancy boundary rather than a
 * theoretical one. A teacher of one course naming a question of another got a
 * successful run, three learners' answer text in a draft, and the draft
 * stamped with the course they were authorised for, so the draft list handed
 * it straight back to them.
 *
 * So this policy resolves the subject's own course and denies when it is not
 * the course the run names, and denies again when it is a course this person
 * could not open on their own. **It runs before any fact resolves**, which is
 * what makes it a gate rather than a filter: nothing is read, nothing is
 * redacted and nothing is drafted.
 *
 * A subject whose type names no course binds nothing and is allowed. That is
 * the honest answer for a site-scope report subject and for a vendor subject
 * type the core cannot map, and the front door's own capability still applies:
 * a run with no course in hand is manage, never signed in.
 */
final class SubjectScopePolicy implements AiPolicyInterface, DeclaresRefusalKind
{
    public const KEY = 'subject_scope';

    public const REASON_OUT_OF_SCOPE = 'ai.policy.subject_out_of_scope';

    public function __construct(
        private readonly AiSubjectCourse $subjects,
        private readonly CourseAccess $courses,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.policy.subject_scope.label';
    }

    public function refusalKindFor(string $reason): ?RefusalKind
    {
        return $reason === self::REASON_OUT_OF_SCOPE ? RefusalKind::InputRejected : null;
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        $subjectCourse = $this->subjects->of($intent->subjectType, $intent->subjectId);

        if ($subjectCourse === null) {
            return PolicyDecision::allow();
        }

        $named = $this->courseNamedBy($intent);

        if ($named !== null && $named !== $subjectCourse) {
            return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_OUT_OF_SCOPE);
        }

        // The second arm covers the run that names no course at all, which the
        // front door admitted on a site-wide capability. A site manager passes
        // it; anybody else naming somebody else's item does not.
        $mayRead = $this->courses->can($context->principal, $subjectCourse, Capability::TEACH)
            || $this->courses->can($context->principal, $subjectCourse, Capability::MANAGE);

        return $mayRead
            ? PolicyDecision::allow()
            : PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_OUT_OF_SCOPE);
    }

    /**
     * The course the run names, taken from the request's own course and from
     * the scope when the scope is a course, and from nowhere else.
     */
    private function courseNamedBy(AiRunIntent $intent): ?int
    {
        if ($intent->courseId !== null && $intent->courseId > 0) {
            return $intent->courseId;
        }

        if (\in_array($intent->scopeType, ContentScopePolicy::COURSE_SCOPES, true) && $intent->scopeId !== null) {
            return $intent->scopeId;
        }

        return null;
    }
}
