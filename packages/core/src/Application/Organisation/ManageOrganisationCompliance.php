<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Compliance\AssignmentExceptionKind;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState;
use Shondalai\VidyaLMS\Core\Domain\Compliance\RequirementLevel;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Domain\Organisation\ProviderParameters;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The compliance authoring surface: writing a requirement down, and putting it
 * in front of people.
 *
 * It exists because the seam did not have one. R5 shipped the four compliance
 * tables, the state machines, the exception vocabulary and the daily window
 * task, and nothing in core or pro-core wrote a `compliance_requirements` row:
 * `ComplianceAssignments::assign` had no caller, so on a stock site the window
 * task reported `checked=0` for ever and no rule package could be exercised
 * even after it was installed. A seam with no authoring path is a seam nobody
 * can reach.
 *
 * ## What is free here, and what is not
 *
 * All of it is free. The free build registers **no compliance rule**, which is
 * the registry miss stated as a decision rather than a gap: a requirement
 * carries `rule_key` `none`, its assignments are rendered as assigned and
 * untracked, and nothing anywhere reports anybody compliant. A free rule that
 * guessed a due date would be worse than none. What a Pro rule package adds is
 * the dates, not the ability to record the requirement.
 *
 * ## Assignment raises exceptions rather than assigning silently
 *
 * The bulk route hands every named person to
 * {@see ComplianceAssignments::assign} and reports one outcome each. A call
 * over four hundred people therefore assigns everybody it can and reports
 * every one it could not, rather than throwing on the first unresolved anchor
 * and assigning nobody. Every person quietly skipped would otherwise be a
 * person missing from the denominator of a report somebody reads as evidence.
 */
final class ManageOrganisationCompliance
{
    /** Authoring a requirement is the learning right, like a grant or a catalogue entry. */
    public const ACTION = 'learning';

    /** Reading requirements, assignments, cycles and exceptions is the read right. */
    public const READ_ACTION = 'read';

    /** The shape version of the stored rule_params envelope. */
    public const PARAMS_SHAPE = 1;

    /** What a requirement carries when no rule tracks it, which is every free requirement. */
    public const RULE_NONE = 'none';

    /** The most people one assignment call may name. */
    public const MAX_SUBJECTS = 200;

    /** @var list<string> */
    public const SCOPE_TYPES = ['organisation', 'unit'];

    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $organisations,
        private readonly OrganisationLearningStore $store,
        private readonly ComplianceAssignments $assignments,
        private readonly ComplianceRuleRegistry $rules,
        private readonly ScheduleAnchorRegistry $anchors,
        private readonly CourseReleasePolicy $releases,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requirements(Principal $actor, int $orgId): array
    {
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        $items = [];

        foreach (
            $this->store->database->selectAll(
                OrgScopedReader::from($scope, 'compliance_requirements')->orderBy('id'),
            ) as $row
        ) {
            $items[] = $this->present($scope, $row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(Principal $actor, int $orgId, array $input): array
    {
        OrganisationInput::only($input, [
            'title',
            'scope_type',
            'scope_id',
            'course_id',
            'requirement',
            'rule_key',
            'rule_params',
            'enforced_at',
        ]);
        $title = OrganisationInput::text($input, 'title', 255);
        $level = $this->level($input['requirement'] ?? RequirementLevel::MANDATORY);
        $ruleKey = $this->ruleKey($input['rule_key'] ?? self::RULE_NONE);
        $enforced = OrganisationInput::datetime($input['enforced_at'] ?? null, 'enforced_at');

        return $this->store->database->transactional(function () use (
            $actor,
            $orgId,
            $title,
            $level,
            $ruleKey,
            $enforced,
            $input
        ): array {
            $scope = $this->access->require($actor, $orgId, self::ACTION);
            [$scopeType, $scopeId] = $this->requirementScope($scope, $input);
            $courseId = isset($input['course_id'])
                ? OrganisationInput::integer($input, 'course_id')
                : null;

            if ($courseId !== null && !$this->releases->allows($scope, $courseId)) {
                throw new ConflictException(sprintf(
                    'forbidden_scope: course %d has not been released to this organisation.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $courseId,
                ));
            }

            $params = $this->ruleParams(
                \is_array($input['rule_params'] ?? null) ? $input['rule_params'] : [],
                $courseId,
                $ruleKey,
            );
            $id = $this->store->insert('compliance_requirements', $scope, [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'title' => $title,
                'course_id' => $courseId,
                'program_id' => null,
                'requirement' => $level,
                'rule_key' => $ruleKey,
                'rule_params' => json_encode(StoredShape::wrap($params, self::PARAMS_SHAPE), JSON_THROW_ON_ERROR),
                // The RULE's shape and not this seam's. The envelope version is
                // the seam's, exactly as the catalogue's is; the column mirrors
                // the provider that owns the bag, so a rule that later changes
                // shape can tell which of its own versions wrote the row. It
                // stored the constant 1 for every rule, which made the column a
                // copy of the envelope and told an upgrader nothing.
                'rule_params_version' => $this->ruleShape($ruleKey),
                'enforced_at' => $enforced,
                'state' => 'active',
                'created_by' => $scope->actorId,
            ]);
            $this->record($scope, 'compliance.requirement_created', [
                'requirement_id' => $id,
                'rule_key' => $ruleKey,
                'requirement' => $level,
            ]);

            return $this->present($scope, $this->requirement($scope, $id));
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(Principal $actor, int $orgId, int $requirementId, array $input): array
    {
        OrganisationInput::only($input, ['title', 'requirement', 'rule_key', 'rule_params', 'enforced_at', 'state']);

        return $this->store->database->transactional(function () use ($actor, $orgId, $requirementId, $input): array {
            $scope = $this->access->require($actor, $orgId, self::ACTION);
            $row = $this->requirement($scope, $requirementId);
            $this->requirementScope($scope, $row);
            $values = [];

            if (\array_key_exists('title', $input)) {
                $values['title'] = OrganisationInput::text($input, 'title', 255);
            }

            if (\array_key_exists('requirement', $input)) {
                $values['requirement'] = $this->level($input['requirement']);
            }

            if (\array_key_exists('rule_key', $input)) {
                $values['rule_key'] = $this->ruleKey($input['rule_key']);
            }

            if (\array_key_exists('rule_params', $input)) {
                // Against the rule this row will carry after the update, which
                // is the one being sent when the key changes in the same call.
                $ruleKey = \is_string($values['rule_key'] ?? null)
                    ? $values['rule_key']
                    : (string) ($row['rule_key'] ?? self::RULE_NONE);
                $values['rule_params'] = json_encode(
                    StoredShape::wrap(
                        $this->ruleParams(
                            \is_array($input['rule_params']) ? $input['rule_params'] : [],
                            $row['course_id'] === null ? null : (int) $row['course_id'],
                            $ruleKey,
                        ),
                        self::PARAMS_SHAPE,
                    ),
                    JSON_THROW_ON_ERROR,
                );
                $values['rule_params_version'] = $this->ruleShape($ruleKey);
            }

            if (\array_key_exists('enforced_at', $input)) {
                $values['enforced_at'] = OrganisationInput::datetime($input['enforced_at'], 'enforced_at');
            }

            if (\array_key_exists('state', $input)) {
                $state = \is_string($input['state']) ? $input['state'] : '';

                if (!\in_array($state, ['active', 'retired'], true)) {
                    throw ValidationException::forField('state', 'Expected active or retired.');
                }

                $values['state'] = $state;
            }

            if ($values === []) {
                throw ValidationException::forField('title', 'Nothing was changed.');
            }

            // The rule and its parameters may be corrected while nobody is
            // assigned and never after: an assignment carries the dates the
            // rule produced, and changing the rule underneath it would leave
            // stored dates no rule now explains. The course is fixed for the
            // same reason and more strongly, since an assignment records what
            // a person was asked to do.
            if (
                $this->assignedCount($scope, $requirementId) > 0
                && (isset($values['rule_key']) || isset($values['rule_params']))
            ) {
                throw new ConflictException('The rule cannot be changed once anybody is assigned.');
            }

            $this->store->update('compliance_requirements', $scope, $requirementId, $values);
            $this->record($scope, 'compliance.requirement_updated', ['requirement_id' => $requirementId] + $values);

            return $this->present($scope, $this->requirement($scope, $requirementId));
        });
    }

    /**
     * Puts one requirement in front of the people named, one outcome each.
     *
     * @param array<string, mixed> $input
     *
     * @return array{assigned: int, exceptions: int, items: list<array<string, mixed>>}
     */
    public function assign(Principal $actor, int $orgId, int $requirementId, array $input): array
    {
        OrganisationInput::only($input, ['user_ids']);
        $userIds = $this->userIds($input);

        return $this->store->database->transactional(function () use ($actor, $orgId, $requirementId, $userIds): array {
            $scope = $this->access->require($actor, $orgId, self::ACTION);
            $requirement = $this->requirement($scope, $requirementId);

            if ((string) $requirement['state'] !== 'active') {
                throw new ConflictException('A retired requirement cannot be assigned.');
            }

            $items = [];
            $assigned = 0;
            $exceptions = 0;

            foreach ($userIds as $userId) {
                $outcome = $this->assignments->assign($scope, $requirementId, $userId, $actor);
                $items[] = ['user_id' => $userId] + $outcome;

                if ($outcome['state'] === 'assigned') {
                    ++$assigned;
                } else {
                    ++$exceptions;
                }
            }

            $this->record($scope, 'compliance.assigned', [
                'requirement_id' => $requirementId,
                'assigned' => $assigned,
                'exceptions' => $exceptions,
            ]);

            return ['assigned' => $assigned, 'exceptions' => $exceptions, 'items' => $items];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function assignments(
        Principal $actor,
        int $orgId,
        int $page = 1,
        int $perPage = 25,
        ?int $requirementId = null,
        ?int $userId = null,
        string $status = '',
    ): array {
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        OrganisationInput::page($page, $perPage);
        $query = OrgScopedReader::from($scope, 'compliance_assignments', 'a')
            ->select([
                'a.id', 'a.requirement_id', 'a.organisation_id', 'a.user_id', 'a.cycle', 'a.assigned_at',
                'a.starts_at', 'a.due_at', 'a.completed_at', 'a.window_opens_at', 'a.expires_at',
                'a.grace_ends_at', 'a.compliance_status', 'a.renewal_status', 'a.rule_state',
                'r.title AS requirement_title', 'r.rule_key',
            ])
            ->join('compliance_requirements', 'r', 'a.requirement_id', 'r.id', [['a.organisation_id', 'r.organisation_id']]);
        $scope->constrainMembers($query, 'a.user_id');

        if ($requirementId !== null) {
            $query->where('a.requirement_id', '=', $requirementId);
        }

        if ($userId !== null) {
            $query->where('a.user_id', '=', $userId);
        }

        if ($status !== '') {
            $query->where('a.compliance_status', '=', $status);
        }

        $result = $this->store->page($query, $page, $perPage, 'a.id');

        // Why, beside what. The four note keys the registry and the rules write
        // reached no human at all before this: nothing returned them and the
        // row showed a status word with no reason, so a site whose rule package
        // was uninstalled could not tell an untracked assignment from a
        // tracked one whose provider had gone. The provider's own memory stays
        // where the schema doctrine puts it and is not returned.
        foreach ($result['items'] as $index => $row) {
            // Read past the envelope version rather than through it: the rule
            // owns its own shape number and a note written under shape 4 is
            // still a note this seam can render.
            $raw = $row['rule_state'] ?? null;
            $stored = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            unset($result['items'][$index]['rule_state']);
            $result['items'][$index]['notes'] = \is_array($stored) ? ComplianceState::notesOf($stored) : [];
        }

        return $result;
    }

    /**
     * The earlier cycles of one assignment.
     *
     * `compliance_cycles` carries no organisation of its own, so the tenancy
     * is the assignment's: the assignment is read through the scoped reader
     * first and the cycles are read by its id afterwards. That order is the
     * check, and reading the cycles first would be reading rows whose tenant
     * nobody had established.
     *
     * @return list<array<string, mixed>>
     */
    public function cycles(Principal $actor, int $orgId, int $assignmentId): array
    {
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        $query = OrgScopedReader::from($scope, 'compliance_assignments', 'a')->select(['a.id'])->where('a.id', '=', $assignmentId);
        $scope->constrainMembers($query, 'a.user_id');
        $assignment = $this->store->database->selectOne($query)
            ?? throw new EntityNotFoundException('No compliance assignment here.');
        $rows = $this->store->database->selectAll(
            Query::from('compliance_cycles')->where('assignment_id', '=', (int) $assignment['id'])->orderBy('cycle'),
        );
        $items = [];

        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'assignment_id' => (int) $row['assignment_id'],
                'cycle' => (int) $row['cycle'],
                'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
                'certificate_id' => $row['certificate_id'] === null ? null : (int) $row['certificate_id'],
                'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
                'baseline_expires_at' => $row['baseline_expires_at'] === null
                    ? null
                    : (string) $row['baseline_expires_at'],
                'reset_at' => $row['reset_at'] === null ? null : (string) $row['reset_at'],
                'reset_ref' => $row['reset_ref'] === null ? null : (string) $row['reset_ref'],
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function exceptions(
        Principal $actor,
        int $orgId,
        int $page = 1,
        int $perPage = 25,
        ?int $requirementId = null,
        string $kind = '',
    ): array {
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        OrganisationInput::page($page, $perPage);

        if ($kind !== '' && !AssignmentExceptionKind::isKnown($kind)) {
            throw ValidationException::forField('kind', 'Unknown exception kind.');
        }

        $query = OrgScopedReader::from($scope, 'assignment_exceptions', 'e')->select(['e.*']);
        $scope->constrainMembers($query, 'e.user_id');

        if ($requirementId !== null) {
            $query->where('e.requirement_id', '=', $requirementId);
        }

        if ($kind !== '') {
            $query->where('e.kind', '=', $kind);
        }

        $result = $this->store->page($query, $page, $perPage, 'e.id');

        foreach ($result['items'] as &$row) {
            $row['resolved_input'] = $this->decode($row['resolved_input'] ?? null);
        }

        return $result;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(OrgScope $scope, array $row): array
    {
        $ruleKey = (string) $row['rule_key'];
        $params = $this->decode($row['rule_params'] ?? null) ?? [];
        $anchorKey = \is_string($params['anchor'] ?? null) && $params['anchor'] !== ''
            ? $params['anchor']
            : ComplianceAssignments::DEFAULT_ANCHOR;

        return [
            'id' => (int) $row['id'],
            'organisation_id' => (int) $row['organisation_id'],
            'scope_type' => (string) $row['scope_type'],
            'scope_id' => (int) $row['scope_id'],
            'title' => (string) $row['title'],
            'course_id' => $row['course_id'] === null ? null : (int) $row['course_id'],
            'program_id' => $row['program_id'] === null ? null : (int) $row['program_id'],
            'requirement' => (string) $row['requirement'],
            'rule_key' => $ruleKey,
            'rule_params' => $params === [] ? null : $params,
            'rule_params_version' => (int) ($row['rule_params_version'] ?? 1),
            'enforced_at' => $row['enforced_at'] === null ? null : (string) $row['enforced_at'],
            'state' => (string) $row['state'],
            // "none" is resolved: it is the word the free build means, not a
            // key nobody answers. Any other key is resolved only when a
            // package registered it, and an unresolved rule tracks nothing.
            'rule_resolved' => $ruleKey === self::RULE_NONE || $this->rules->has($ruleKey),
            'anchor_key' => $anchorKey,
            'anchor_resolved' => $this->anchors->has($anchorKey),
            'assignment_count' => $this->assignedCount($scope, (int) $row['id']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requirement(OrgScope $scope, int $requirementId): array
    {
        return $this->store->database->selectOne(
            OrgScopedReader::from($scope, 'compliance_requirements')->where('id', '=', $requirementId),
        ) ?? throw new EntityNotFoundException('No compliance requirement here.');
    }

    private function assignedCount(OrgScope $scope, int $requirementId): int
    {
        $query = OrgScopedReader::from($scope, 'compliance_assignments', 'a')->where('a.requirement_id', '=', $requirementId);
        $scope->constrainMembers($query, 'a.user_id');
        return $this->store->database->count($query);
    }

    /**
     * The rule's own bag, with the anchor it is scheduled by.
     *
     * A requirement with **no course** must name an anchor that does not need
     * one, and the absolute anchor is that anchor: its instant lives in
     * `anchor_params.at`. The default anchor is the person's own enrolment
     * instant, which a requirement with no course has no source for, so this
     * refuses the combination at save rather than letting every assignment
     * raise `anchor_unresolved` afterwards.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function ruleParams(array $params, ?int $courseId, string $ruleKey = self::RULE_NONE): array
    {
        $anchor = $params['anchor'] ?? null;

        if ($anchor !== null && (!\is_string($anchor) || !$this->anchors->has($anchor))) {
            throw ValidationException::forField('rule_params.anchor', 'No installed anchor answers that key.');
        }

        $anchorKey = \is_string($anchor) && $anchor !== '' ? $anchor : ComplianceAssignments::DEFAULT_ANCHOR;
        $anchorParams = $params[ComplianceAssignments::ANCHOR_PARAMS] ?? null;

        if ($anchorParams !== null && !\is_array($anchorParams)) {
            throw ValidationException::forField('rule_params.anchor_params', 'The anchor parameters are an object.');
        }

        if ($courseId === null && $anchorKey === ComplianceAssignments::DEFAULT_ANCHOR) {
            throw ValidationException::forField(
                'rule_params.anchor',
                'A requirement with no course must name the anchor it is due from.',
            );
        }

        // The rule's own schema, asked. It had zero callers in the whole tree,
        // so a rule could declare a closed schema requiring `days` and this
        // accepted `{dayz: "thirty"}` with a 201 and an author found out at the
        // first sweep. The two anchor keys above are the seam's own and are not
        // the provider's, so they are checked here and handed past the schema.
        if ($ruleKey === self::RULE_NONE || !$this->rules->has($ruleKey)) {
            return $params;
        }

        $own = $params;
        unset($own['anchor'], $own[ComplianceAssignments::ANCHOR_PARAMS]);

        ProviderParameters::validate($this->rules->get($ruleKey)->paramsSchema(), $own, 'rule_params');

        return $params;
    }

    /**
     * The shape version the resolving rule stamps on a bag it owns.
     *
     * A requirement carrying no rule stamps 1, which is what a bag nothing
     * interprets is worth and what every shipped provider answers.
     */
    private function ruleShape(string $ruleKey): int
    {
        return $ruleKey === self::RULE_NONE || !$this->rules->has($ruleKey)
            ? self::PARAMS_SHAPE
            : max(1, $this->rules->get($ruleKey)->currentShape());
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{string, int}
     */
    private function requirementScope(OrgScope $scope, array $input): array
    {
        $scopeType = $input['scope_type'] ?? 'organisation';

        if (!\is_string($scopeType) || !\in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw ValidationException::forField('scope_type', 'Expected organisation or unit.');
        }

        if ($scopeType === 'organisation') {
            if ($scope->unitId !== null) {
                throw new EntityNotFoundException('No organisation-wide requirement in this scope.');
            }
            return [$scopeType, 0];
        }

        $scopeId = OrganisationInput::integer($input, 'scope_id');
        $query = OrgScopedReader::from($scope, 'org_units', 'u')->select(['u.id'])->where('u.id', '=', $scopeId);
        $scope->constrainUnit($query, 'u.id');
        $unit = $this->store->database->selectOne($query);

        if ($unit === null) {
            throw ValidationException::forField('scope_id', 'That unit is not part of this organisation.');
        }

        return [$scopeType, $scopeId];
    }

    private function level(mixed $value): string
    {
        if (!\is_string($value) || !\in_array($value, RequirementLevel::all(), true)) {
            throw ValidationException::forField('requirement', 'Unknown requirement level.');
        }

        return $value;
    }

    private function ruleKey(mixed $value): string
    {
        if (!\is_string($value) || $value === '' || mb_strlen($value) > 40) {
            throw ValidationException::forField('rule_key', 'A rule key is a short word.');
        }

        // A key nothing answers is refused at save. The registry miss
        // direction covers a package uninstalled AFTER the row was written,
        // which is a different fact from an author naming a rule that never
        // existed on this site.
        if ($value !== self::RULE_NONE && !$this->rules->has($value)) {
            throw ValidationException::forField('rule_key', 'No installed rule answers that key.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<int>
     */
    private function userIds(array $input): array
    {
        $ids = $input['user_ids'] ?? null;

        if (!\is_array($ids) || $ids === []) {
            throw ValidationException::forField('user_ids', 'Name at least one person.');
        }

        if (\count($ids) > self::MAX_SUBJECTS) {
            throw ValidationException::forField('user_ids', sprintf(
                'Assign at most %d people in one call.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::MAX_SUBJECTS,
            ));
        }

        $clean = [];

        foreach ($ids as $id) {
            if (!\is_int($id) || $id < 1) {
                throw ValidationException::forField('user_ids', 'Every person is a positive id.');
            }

            $clean[$id] = true;
        }

        return array_map(static fn (int|string $id): int => (int) $id, array_keys($clean));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $raw): ?array
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        return StoredShape::versionOf($decoded) <= self::PARAMS_SHAPE ? StoredShape::bodyOf($decoded) : null;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(OrgScope $scope, string $key, array $details): void
    {
        $organisation = $this->organisations->organisation($scope);
        $this->organisations->event(
            $scope,
            (int) ($organisation['revision'] ?? 1),
            MembershipEvent::GOVERNANCE_CHANGED,
            null,
            ['change' => $key] + $details,
            MembershipEventContext::manual(),
        );
    }
}
