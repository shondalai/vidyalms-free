<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The two reads the daily compliance walk makes across every organisation at
 * once.
 *
 * It exists for the same reason {@see SweptRecordStore} does. A sweep asks the
 * whole site a question and then recovers the tenant per row; a sweep narrowed
 * on a tenant would be a sweep that only ever ran for one. So the unanchored
 * reads live here, in the persistence home, enumerated where somebody signed
 * for them, rather than inside a task where they would look like an oversight.
 *
 * **The tenant is recovered per row and the write names it.** Every row this
 * hands back carries its own `organisation_id`, and the caller's update names
 * that organisation in the predicate as well as the row id, so the one sweep
 * that writes to an organisation-owned table from outside an organisation scope
 * still cannot write across one.
 */
final class ComplianceStore
{
    public function __construct(private readonly DatabasePort $database)
    {
    }

    /**
     * One batch of assignments a rule still has something to say about,
     * oldest first.
     *
     * @param list<string> $openStatuses
     *
     * @return list<array<string, mixed>>
     */
    public function openAssignments(array $openStatuses, int $limit): array
    {
        return $this->database->selectAll(
            Query::from('compliance_assignments', 'a')
                ->select(['a.id', 'a.requirement_id', 'a.organisation_id', 'a.user_id', 'a.cycle', 'a.assigned_at',
                    'a.completed_at', 'a.compliance_status', 'a.renewal_status', 'a.rule_state',
                    'a.rule_state_version'])
                ->whereIn('a.compliance_status', $openStatuses)
                ->orderBy('a.id')
                ->limit(max(1, $limit)),
        );
    }

    /**
     * The requirement one assignment hangs off, or an empty row.
     *
     * Read by id alone because the assignment that names it has already been
     * read under the same walk and carries the tenant; narrowing this on an
     * organisation the caller supplied would be asking the answer to supply the
     * question.
     *
     * @return array<string, mixed>
     */
    public function requirement(int $requirementId): array
    {
        return $this->database->selectOne(
            Query::from('compliance_requirements')->where('id', '=', $requirementId),
        ) ?? [];
    }

    /**
     * When this person finished this course, or null.
     *
     * The completion listener {@see \Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState::dateColumns()}
     * names never existed, so `compliance_assignments.completed_at` had no
     * writer at all and the compliant branch of every rule was unreachable on a
     * running site. This is that listener in its polling form: the daily walk
     * asks, once per open assignment whose requirement names a course, whether
     * the enrolment behind it has completed.
     *
     * It is not a tenancy widening. The assignment names the person and the
     * requirement names the course, so this reads one row about the same person
     * the caller already holds, and `enrolments` carries no organisation of its
     * own by design.
     */
    public function courseCompletion(int $userId, int $courseId): ?string
    {
        $row = $this->database->selectOne(
            Query::from('enrolments')
                ->select(['completed_at'])
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId),
        );
        $value = $row['completed_at'] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Writes one re-evaluated assignment back.
     *
     * The organisation is in the predicate beside the id, because an id alone
     * is a predicate that cannot be wrong in a way anybody would notice.
     *
     * @param array<string, mixed> $values
     */
    public function saveAssignment(int $id, int $organisationId, array $values): void
    {
        $this->database->update(
            'compliance_assignments',
            $values,
            ['id' => $id, 'organisation_id' => $organisationId],
        );
    }
}
