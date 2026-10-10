<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Compliance\AssignmentExceptionKind;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceSubject;
use Shondalai\VidyaLMS\Core\Domain\Compliance\RequirementLevel;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ComplianceContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Putting one requirement in front of one person, or saying why it cannot be
 * put there.
 *
 * **Assignment raises exceptions rather than assigning silently**, which is the
 * rule this class exists to hold. Totara raises an exception and emails the
 * administrator; the argument for copying that is arithmetic rather than taste,
 * because every person quietly skipped is a person missing from the denominator
 * of a report somebody will read as evidence.
 *
 * **`anchor_unresolved` is the one a relative-anchor design hits first**, so it
 * is written against the shipped `ScheduleAnchor` registry rather than against a
 * date column of its own. That registry already publishes the direction this
 * class needs: an unresolved anchor makes its condition blocked with no opening
 * instant, never immediately available. Here the equivalent is that a
 * requirement whose anchor resolves to null produces an exception row and no
 * assignment, so nobody is given a due date nothing supports and nobody is
 * passed over.
 *
 * **No rule is registered in the free build, and every free assignment is
 * therefore assigned and untracked.** That is the `ComplianceRule` miss
 * direction and not a gap: the registry answers
 * {@see ComplianceState::untracked()}, the row carries `rule_key` `none`, and
 * nothing anywhere reports the person compliant. A free rule guessing a due date
 * would be worse than none.
 */
final class ComplianceAssignments
{
    /**
     * Which anchor a requirement counts from when its parameters name none.
     *
     * The person's own enrolment instant, which is the answer that works for an
     * evergreen course and the one anchor every assignable requirement has a
     * source for. A requirement naming its own anchor overrides it.
     */
    public const DEFAULT_ANCHOR = 'enrolment.enrolled_at';

    /** Where a requirement stores the parameters of its own anchor, inside rule_params. */
    public const ANCHOR_PARAMS = 'anchor_params';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly ComplianceRuleRegistry $rules,
        private readonly ScheduleAnchorRegistry $anchors,
    ) {
    }

    /**
     * Assigns one requirement to one person inside one organisation.
     *
     * The scope is already authorised by the caller: this class resolves
     * nothing and authorises nothing, and every read it makes starts at the
     * scoped reader so the tenancy predicate is injected rather than typed.
     *
     * @return array{state: string, assignment_id: ?int, exception: ?string, compliance_status: string}
     */
    public function assign(OrgScope $scope, int $requirementId, int $userId, ?Principal $actor = null): array
    {
        $requirement = $this->requirement($scope, $requirementId);

        if ($requirement === null) {
            return $this->raise($scope, null, $userId, AssignmentExceptionKind::DUPLICATE_REQUIREMENT, [
                'reason' => 'no_such_requirement',
                'requirement_id' => $requirementId,
            ], $actor);
        }

        if (!$this->isActiveMember($scope, $userId)) {
            return $this->raise($scope, $requirementId, $userId, AssignmentExceptionKind::USER_NOT_IN_ORG, [
                'reason' => 'no_active_membership',
            ], $actor);
        }

        if ($this->alreadyAssigned($scope, $requirementId, $userId)) {
            return $this->raise($scope, $requirementId, $userId, AssignmentExceptionKind::DUPLICATE_REQUIREMENT, [
                'reason' => 'already_assigned',
            ], $actor);
        }

        $courseId = $requirement['course_id'] === null ? null : (int) $requirement['course_id'];

        if ($courseId !== null && !$this->hasOrganisationGrant($scope, $userId, $courseId)) {
            return $this->raise($scope, $requirementId, $userId, AssignmentExceptionKind::NO_ENTITLEMENT, [
                'reason' => 'no_organisation_grant',
                'course_id' => $courseId,
            ], $actor);
        }

        $enrolment = $courseId === null ? null : $this->enrolment($userId, $courseId);
        $anchorKey = $this->anchorKeyFor($requirement);
        $anchor = $this->resolveAnchor(
            $anchorKey,
            $enrolment,
            $actor ?? Principal::user($scope->actorId),
            $this->anchorParams($requirement),
        );

        if ($anchor === null) {
            return $this->raise($scope, $requirementId, $userId, AssignmentExceptionKind::ANCHOR_UNRESOLVED, [
                'anchor' => $anchorKey,
                'course_id' => $courseId,
            ], $actor);
        }

        $now = $this->clock->now();
        $state = $this->rules->evaluateThrough(
            \is_string($requirement['rule_key'] ?? null) ? $requirement['rule_key'] : null,
            ComplianceSubject::of($scope->organisationId, $requirementId, $userId, $now),
            ComplianceContext::create($now, $this->params($requirement), $anchor, $anchorKey),
        );

        $stamp = $now->format('Y-m-d H:i:s');
        $assignmentId = $this->database->insert('compliance_assignments', [
            'requirement_id' => $requirementId,
            'organisation_id' => $scope->organisationId,
            'user_id' => $userId,
            'cycle' => 1,
            'assigned_at' => $stamp,
            'compliance_status' => $state->status,
            'renewal_status' => $state->renewalStatus,
            'rule_state' => json_encode($state->storedRuleState(), JSON_THROW_ON_ERROR),
            'rule_state_version' => $state->ruleStateVersion,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ] + $state->dateColumns());

        return [
            'state' => 'assigned',
            'assignment_id' => $assignmentId,
            'exception' => null,
            'compliance_status' => $state->status,
        ];
    }

    /**
     * How hard this requirement is, for a report that needs a denominator.
     *
     * @param array<string, mixed> $requirement
     */
    public static function levelOf(array $requirement): string
    {
        return RequirementLevel::of(
            \is_string($requirement['requirement'] ?? null) ? $requirement['requirement'] : null,
        );
    }

    /**
     * Writes one exception row and answers with it, rather than throwing.
     *
     * An exception is a fact about a person that an administrator resolves, not
     * a failure of the caller's request: a bulk assignment over four hundred
     * people that threw on the first unresolved anchor would assign nobody and
     * tell an operator about one of them.
     *
     * @param array<string, mixed> $input
     *
     * @return array{state: string, assignment_id: ?int, exception: ?string, compliance_status: string}
     */
    private function raise(
        OrgScope $scope,
        ?int $requirementId,
        int $userId,
        string $kind,
        array $input,
        ?Principal $actor,
    ): array {
        $this->database->insert('assignment_exceptions', [
            'organisation_id' => $scope->organisationId,
            'requirement_id' => $requirementId,
            'user_id' => $userId,
            'kind' => $kind,
            'resolved_input' => json_encode(
                StoredShape::wrap($input + ['raised_by' => $actor->userId ?? $scope->actorId], 1),
                JSON_THROW_ON_ERROR,
            ),
            'resolved_input_version' => 1,
            'raised_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        return [
            'state' => 'exception_raised',
            'assignment_id' => null,
            'exception' => $kind,
            // Never compliant. An exception is the absence of an answer and the
            // absence of an answer is not a pass.
            'compliance_status' => ComplianceState::untracked()->status,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requirement(OrgScope $scope, int $requirementId): ?array
    {
        return $this->database->selectOne(
            OrgScopedReader::from($scope, 'compliance_requirements')
                ->where('id', '=', $requirementId)
                ->where('state', '=', 'active'),
        );
    }

    private function isActiveMember(OrgScope $scope, int $userId): bool
    {
        $query = OrgScopedReader::from($scope, 'org_memberships', 'm')
            ->where('m.user_id', '=', $userId)
            ->where('m.state', '=', 'active');
        $scope->constrainUnit($query, 'm.org_unit_id');

        return $this->database->count($query) > 0;
    }

    private function alreadyAssigned(OrgScope $scope, int $requirementId, int $userId): bool
    {
        return $this->database->count(
            OrgScopedReader::from($scope, 'compliance_assignments')
                ->where('requirement_id', '=', $requirementId)
                ->where('user_id', '=', $userId),
        ) > 0;
    }

    /**
     * Whether this person reaches the course **through this organisation**.
     *
     * The entitlement and never the enrolment, which is the isolation rule: a
     * learner who bought the course themselves has not been given it by their
     * employer, and assigning the employer's requirement against the learner's
     * own purchase would put a personal record inside an organisation report.
     */
    private function hasOrganisationGrant(OrgScope $scope, int $userId, int $courseId): bool
    {
        return $this->database->count(
            Query::from('entitlements')
                ->where('organisation_id', '=', $scope->organisationId)
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId)
                ->where('status', '=', 'active'),
        ) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function enrolment(int $userId, int $courseId): ?array
    {
        return $this->database->selectOne(
            Query::from('enrolments')->where('user_id', '=', $userId)->where('course_id', '=', $courseId),
        );
    }

    /**
     * @param array<string, mixed> $requirement
     */
    private function anchorKeyFor(array $requirement): string
    {
        $params = $this->params($requirement);
        $anchor = $params['anchor'] ?? null;

        return \is_string($anchor) && $anchor !== '' ? $anchor : self::DEFAULT_ANCHOR;
    }

    /**
     * @param array<string, mixed> $requirement
     *
     * @return array<string, mixed>
     */
    private function params(array $requirement): array
    {
        $raw = $requirement['rule_params'] ?? null;

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * The anchor instant, or null when nothing resolves it.
     *
     * **A registry miss is a null and never a guess.** An anchor key nothing
     * resolves is exactly the case the exception kind exists for, so it takes
     * the same path an anchor that resolved to nothing takes.
     *
     * @param array<string, mixed>|null $enrolment
     * @param array<string, mixed>      $payload
     */
    private function resolveAnchor(
        string $anchorKey,
        ?array $enrolment,
        Principal $actor,
        array $payload = [],
    ): ?\DateTimeImmutable {
        if (!$this->anchors->has($anchorKey)) {
            return null;
        }

        return $this->anchors->get($anchorKey)->resolve(AnchorContext::create(
            // The person the requirement is about, when the caller named one,
            // and otherwise the actor whose scope authorised the run. No
            // shipped anchor reads the principal; passing the scope's own actor
            // rather than a guest keeps that true if one ever does.
            $actor,
            $this->clock->now(),
            $enrolment,
            null,
            // The anchor own parameters, which nothing used to pass. Every
            // shipped anchor that reads one was therefore unreachable from a
            // requirement: the absolute anchor reads an instant out of the
            // payload and answered null every time, so a requirement with no
            // course always raised anchor_unresolved and the four shipped
            // anchors could not be exercised from the surface that names them.
            $payload,
        ));
    }

    /**
     * The parameters the requirement carries for its own anchor.
     *
     * They live under an `anchor_params` key inside `rule_params` rather than
     * in a column of their own, because the anchor key is read from the same
     * bag: one stored object holds the rule parameters and the anchor it is
     * scheduled by, and a second column would be a second place for them to
     * disagree.
     *
     * @param array<string, mixed> $requirement
     *
     * @return array<string, mixed>
     */
    private function anchorParams(array $requirement): array
    {
        $params = $this->params($requirement);
        $anchorParams = $params[self::ANCHOR_PARAMS] ?? null;

        return \is_array($anchorParams) ? $anchorParams : [];
    }
}
