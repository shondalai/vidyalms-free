<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Audience;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;

/**
 * The instructors of the subject course, read from the course's own
 * instructor rows.
 *
 * It is a generator consumed in batches, so a course with a large teaching
 * team is never materialised in memory, which is the rule every resolver
 * follows rather than an optimisation this one makes.
 *
 * It declares FiltersByVisibility and therefore honours the scope, which is
 * the promise that lets a scoped run use it at all. This is the shipped
 * example a third-party audience author copies, so the marker and the check
 * belong in the same file: a resolver that declares the one without the
 * other is a scoped screen mailing everybody.
 *
 * **No shipped path sets a scope yet, so the check narrows nothing today.**
 * NotificationFanout is the only place in either package that builds an
 * AudienceContext and it leaves visibilityScope at its default of null, which
 * visibleUnder() answers as "reaches everything". The check exists for the
 * scoped callers R1 introduces with ReportContext and AudienceContext
 * scoping, and it is written now because this is the file an author copies.
 * Read it as the contract a scoped caller will get, not as a narrowing the
 * free build performs.
 *
 * One decision is deliberately left to R1 rather than guessed here: a cohort
 * scope answers nobody, and a cohort belongs to a course, so a cohort-scoped
 * run arguably wants that course's teaching team. Narrowing wrongly is a
 * message not sent; widening wrongly is a message sent to people outside the
 * scope, so the narrow answer stands until a caller exists to decide.
 */
final class CourseInstructorsAudience implements AudienceResolverInterface, FiltersByVisibility
{
    public const KEY = 'course_instructors';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.audience.course_instructors';
    }

    public function resolve(AudienceContext $context): iterable
    {
        $courseId = $context->courseId;

        if ($courseId === null && $context->subjectType === 'course') {
            $courseId = $context->subjectId;
        }

        if ($courseId === null || $courseId < 1 || !self::visibleUnder($context, $courseId)) {
            return;
        }

        $rows = $context->database->selectAll(
            Query::from('course_instructors')
                ->select(['user_id'])
                ->where('course_id', '=', $courseId)
                ->orderBy('user_id'),
        );

        foreach ($rows as $row) {
            yield (int) $row['user_id'];
        }
    }

    /**
     * Whether the caller's visibility scope reaches this course.
     *
     * A site scope reaches everything and a course scope reaches its own
     * course. Anything else reaches nobody: a scope this resolver cannot
     * express is a scope it must not widen, and answering the whole teaching
     * team is the widening. Nobody is also the answer the audience registry
     * publishes for a miss, so a scoped run counts one suppression with a
     * reason rather than sending a message it cannot justify.
     */
    private static function visibleUnder(AudienceContext $context, int $courseId): bool
    {
        $scope = $context->visibilityScope;

        if ($scope === null || $scope->isSiteWide()) {
            return true;
        }

        return $scope->type === VisibilityScope::TYPE_COURSE && $scope->id === $courseId;
    }
}
