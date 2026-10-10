<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityGrant;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityGrantRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The auditable override of the tree for one learner.
 *
 * Nothing in this design latches. A downward regrade retracts access, which
 * is correct and surprising, and the remedy is a row somebody signed rather
 * than a condition that quietly stops re-evaluating: the documented failure
 * elsewhere is a learner regraded from seventy-two down to fifty-five who
 * keeps access forever with no record of why.
 */
final class ManageAvailabilityGrants
{
    public function __construct(
        private readonly SqlAvailabilityGrantRepository $grants,
        private readonly AccessGuard $guard,
        private readonly CourseAccess $courseAccess,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(Principal $principal, int $courseId, ?int $userId, int $page, int $perPage): array
    {
        $this->courseAccess->require($principal, $courseId, Capability::TEACH);

        $offset = max(0, ($page - 1) * $perPage);
        // Every scope that bears on the course, not the course scope alone: a
        // cohort-scoped deny is the narrowest row and therefore the last word,
        // and it was invisible on every screen that could have shown it.
        $result = $this->grants->page($courseId, $userId, $perPage, $offset, $this->subjectIdsOf($courseId));

        return [
            'items' => array_map($this->present(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'scope_order' => AvailabilityGrant::scopeOrder(),
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function create(Principal $principal, int $courseId, array $body): array
    {
        $scopeType = (string) ($body['scope_type'] ?? AvailabilityGrant::SCOPE_COURSE);
        $scopeId = (int) ($body['scope_id'] ?? $courseId);
        $mode = (string) ($body['mode'] ?? AvailabilityGrant::MODE_GRANT);
        $subjectType = (string) ($body['subject_type'] ?? '');
        $subjectId = (int) ($body['subject_id'] ?? 0);
        $userId = (int) ($body['user_id'] ?? 0);

        $errors = [];

        if (!\in_array($scopeType, AvailabilityGrant::scopeOrder(), true) && $scopeType !== AvailabilityGrant::SCOPE_SITE) {
            $errors['scope_type'] = 'Unknown scope.';
        }

        if (!GateSubject::isKnownType($subjectType)) {
            $errors['subject_type'] = 'Unknown subject type.';
        }

        if ($subjectId < 1) {
            $errors['subject_id'] = 'A subject is required.';
        }

        if ($userId < 1) {
            $errors['user_id'] = 'A learner is required.';
        }

        if (!\in_array($mode, AvailabilityGrant::modes(), true)) {
            $errors['mode'] = 'Unknown mode.';
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        // The subject's own course, not the one in the path. Authorizing on
        // the path course while storing whatever subject the body named let
        // an instructor of course 1 write a permanent, invisible deny against
        // a lesson of course 2 for any learner, and an open-ended deny takes
        // the item out of that learner's completion denominator, so it is a
        // lever on whether a certificate is ever issued.
        $subject = new GateSubject($subjectType, $subjectId);
        $subjectCourseId = $this->courseIdOf($subject);

        if ($subjectCourseId === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for(ucfirst($subjectType), $subjectId);
        }

        if ($subjectCourseId !== $courseId) {
            throw ValidationException::forField(
                'subject_id',
                'The subject belongs to another course.',
            );
        }

        // A site-scoped row reaches beyond the course, so it takes the
        // site-wide capability rather than the course one.
        if ($scopeType === AvailabilityGrant::SCOPE_SITE) {
            $this->guard->require($principal, Capability::MANAGE);
        } else {
            $this->courseAccess->require($principal, $subjectCourseId, Capability::TEACH);
        }

        $this->requireScopeBelongsToCourse($scopeType, $scopeId, $subjectCourseId);

        $expiresAt = $this->instant($body['expires_at'] ?? null);
        $reason = $body['reason'] ?? null;

        $saved = $this->grants->save(new AvailabilityGrant(
            null,
            $scopeType,
            $scopeType === AvailabilityGrant::SCOPE_SITE ? 0 : $scopeId,
            new GateSubject($subjectType, $subjectId),
            $userId,
            $mode,
            \is_string($reason) && $reason !== '' ? $reason : null,
            $principal->userId,
            $expiresAt,
        ));

        return $this->present($saved);
    }

    public function revoke(Principal $principal, int $id): void
    {
        $grant = $this->grants->findById($id);

        if ($grant === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AvailabilityGrant', $id);
        }

        if ($grant->scopeType === AvailabilityGrant::SCOPE_SITE) {
            $this->guard->require($principal, Capability::MANAGE);

            $this->grants->delete($id);

            return;
        }

        // The subject's course, and never the scope id. A cohort-scoped row
        // carries a cohort id in scope_id, so passing it to a course
        // authorization asked whether the caller teaches the course whose id
        // happens to equal a cohort id.
        $courseId = $this->courseIdOf($grant->subject);

        if ($courseId === null) {
            $this->guard->require($principal, Capability::MANAGE);
        } else {
            $this->courseAccess->require($principal, $courseId, Capability::TEACH);
        }

        $this->grants->delete($id);
    }

    /**
     * Every gated row of this course, by subject type.
     *
     * @return array<string, list<int>>
     */
    private function subjectIdsOf(int $courseId): array
    {
        $ids = [GateSubject::TYPE_COURSE => [$courseId]];

        foreach (
            [
                GateSubject::TYPE_MODULE => Query::from('modules')->select(['id'])->where('course_id', '=', $courseId),
                GateSubject::TYPE_LESSON => Query::from('lessons')->select(['id'])->where('course_id', '=', $courseId),
                GateSubject::TYPE_QUIZ => Query::from('quizzes')->select(['id'])->where('course_id', '=', $courseId),
                GateSubject::TYPE_ASSIGNMENT => Query::from('assignments', 'a')
                    ->select(['a.id'])
                    ->join('lessons', 'l', 'a.lesson_id', 'l.id')
                    ->where('l.course_id', '=', $courseId),
            ] as $type => $query
        ) {
            $ids[$type] = array_map(
                static fn (array $row): int => (int) $row['id'],
                $this->database->selectAll($query),
            );
        }

        return $ids;
    }

    /**
     * The course a gated subject belongs to, or null when the row is gone.
     */
    private function courseIdOf(GateSubject $subject): ?int
    {
        $row = match ($subject->type) {
            GateSubject::TYPE_COURSE => $this->database->selectOne(
                Query::from('courses')->select(['id'])->where('id', '=', $subject->id),
            ),
            GateSubject::TYPE_MODULE => $this->database->selectOne(
                Query::from('modules')->select(['course_id'])->where('id', '=', $subject->id),
            ),
            GateSubject::TYPE_LESSON => $this->database->selectOne(
                Query::from('lessons')->select(['course_id'])->where('id', '=', $subject->id),
            ),
            GateSubject::TYPE_QUIZ => $this->database->selectOne(
                Query::from('quizzes')->select(['course_id'])->where('id', '=', $subject->id),
            ),
            GateSubject::TYPE_ASSIGNMENT => $this->database->selectOne(
                Query::from('assignments', 'a')
                    ->select(['l.course_id'])
                    ->join('lessons', 'l', 'a.lesson_id', 'l.id')
                    ->where('a.id', '=', $subject->id),
            ),
            default => null,
        };

        if ($row === null) {
            return null;
        }

        $courseId = $subject->type === GateSubject::TYPE_COURSE ? ($row['id'] ?? null) : ($row['course_id'] ?? null);

        return \is_numeric($courseId) ? (int) $courseId : null;
    }

    /**
     * A narrower scope must name something inside the course being gated.
     *
     * Without this a cohort scope could name any integer, including a run of
     * another course or one that does not exist, and because cohort is the
     * narrowest scope the phantom row would be the last word for that
     * learner on that item forever.
     */
    private function requireScopeBelongsToCourse(string $scopeType, int $scopeId, int $courseId): void
    {
        if ($scopeType === AvailabilityGrant::SCOPE_COURSE) {
            if ($scopeId !== $courseId) {
                throw ValidationException::forField('scope_id', 'A course scope must name the subject\'s own course.');
            }

            return;
        }

        if ($scopeType !== AvailabilityGrant::SCOPE_COHORT) {
            return;
        }

        // The cohorts table is a paid one, so a free build has none. That is
        // a refusal rather than a pass: a cohort scope on a site with no
        // cohorts names nothing, and letting it through would store the
        // narrowest scope in the order against a run that cannot exist,
        // which is the row that beat every other grant and left the item out
        // of the learner's denominator for good.
        try {
            $cohort = $this->database->selectOne(
                Query::from('cohorts')->select(['course_id'])->where('id', '=', $scopeId),
            );
        } catch (\Throwable) {
            $cohort = null;
        }

        if ($cohort === null || (int) ($cohort['course_id'] ?? 0) !== $courseId) {
            throw ValidationException::forField('scope_id', 'A cohort scope must name a run of this course.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AvailabilityGrant $grant): array
    {
        return [
            'id' => $grant->id,
            'scope_type' => $grant->scopeType,
            'scope_id' => $grant->scopeId,
            'subject_type' => $grant->subject->type,
            'subject_id' => $grant->subject->id,
            'user_id' => $grant->userId,
            'mode' => $grant->mode,
            'reason' => $grant->reason,
            'granted_by' => $grant->grantedBy,
            'expires_at' => $grant->expiresAt?->format(\DateTimeInterface::ATOM),
            'created_at' => $grant->createdAt?->format(\DateTimeInterface::ATOM),
            'active' => $grant->isActiveAt($this->clock->now()),
            // Stated rather than inferred, because a client writing this row
            // without the confirmation dialog would otherwise not know that
            // an open-ended denial excuses the learner from the item.
            'excludes_from_completion' => $grant->excludesFromCompletion(),
        ];
    }

    private function instant(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw ValidationException::forField('expires_at', 'Not a valid instant.');
        }
    }
}
