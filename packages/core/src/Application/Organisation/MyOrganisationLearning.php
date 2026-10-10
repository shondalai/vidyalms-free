<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgCatalogueReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** A learner's own training, never an organization's roster or seat ledger. */
final class MyOrganisationLearning
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $store,
        private readonly ManageOrganisationCatalogue $catalogue,
        private readonly ClockPort $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function organisations(Principal $actor, int $page, int $perPage): array
    {
        $this->guard->requireAuthenticated($actor);
        OrganisationInput::page($page, $perPage);
        $result = $this->store->ownOrganisations($actor->userId, $page, $perPage);
        foreach ($result['items'] as &$item) {
            $data = $this->data($actor, (int) $item['id']);
            $item['learning'] = $data['summary'];
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function page(Principal $actor, int $orgId, string $tab = 'assigned', int $page = 1, int $perPage = 12, string $search = ''): array
    {
        OrganisationInput::page($page, $perPage);
        if (!in_array($tab, ['assigned', 'available'], true) || mb_strlen($search) > 200) {
            throw ValidationException::forField('tab', 'Choose assigned or available learning and a search of up to 200 characters.');
        }
        $data = $this->data($actor, $orgId);
        $ids = array_keys($data[$tab]);
        $query = Query::from('courses')->select(['id', 'title', 'slug', 'summary', 'state'])
            ->whereIn('id', $ids)->whereContains('title', trim($search))->orderBy('title')->orderBy('id');
        $total = $this->store->database->count(clone $query);
        $page = min($page, max(1, (int) ceil($total / $perPage)));
        $rows = $this->store->database->selectAll($query->limit($perPage)->offset(($page - 1) * $perPage));
        $courseIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $enrolments = [];
        foreach ($this->store->database->selectAll(Query::from('enrolments')->where('user_id', '=', $actor->userId)->whereIn('course_id', $courseIds)) as $row) {
            $enrolments[(int) $row['course_id']] = $row;
        }
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $assignment = $data['assigned'][$id] ?? null;
            $enrolment = $enrolments[$id] ?? null;
            $items[] = [
                'course_id' => $id, 'title' => (string) $row['title'], 'slug' => (string) $row['slug'],
                // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
                'summary' => mb_substr(trim(strip_tags((string) ($row['summary'] ?? ''))), 0, 220),
                'progress_percent' => (float) ($enrolment['progress_percent'] ?? 0),
                'enrolment_status' => $enrolment['status'] ?? null,
                'can_learn' => $row['state'] === 'published' && ($assignment['has_access'] ?? false)
                    && in_array($enrolment['status'] ?? '', ['active', 'completed'], true),
                'required' => $assignment['required'] ?? false,
                'due_at' => $assignment['due_at'] ?? null,
                'completed_at' => $assignment['completed_at'] ?? null,
                'expires_at' => $assignment['expires_at'] ?? null,
                'has_access' => $assignment['has_access'] ?? false,
                'grant_mode' => $data['available'][$id] ?? null,
                'published' => $row['state'] === 'published',
            ];
        }
        return ['organisation' => $data['organisation'], 'summary' => $data['summary'],
            'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** Resolve IDs first; only the requested page loads course content and progress.
     * @return array{organisation: array{id: int, name: string}, summary: array{assigned: int, available: int, required: int}, assigned: array<int, array<string, mixed>>, available: array<int, string>}
     */
    private function data(Principal $actor, int $orgId): array
    {
        $scope = $this->access->require($actor, $orgId, 'own_learning');
        $membership = $this->store->ownMembership($orgId, $actor->userId)
            ?? throw new EntityNotFoundException('No organisation here.');
        $db = $this->store->database;
        $now = $this->store->now();
        $assigned = [];
        // All grants, not merely the enrolment's current covering grant. A course
        // funded by two organizations remains one enrolment and appears in both.
        $grants = $db->selectAll(Query::from('entitlements', 't')->select(['t.*'])
            ->join('courses', 'c', 't.course_id', 'c.id')->where('c.state', '!=', 'trashed')
            ->where('t.user_id', '=', $actor->userId)->where('t.organisation_id', '=', $orgId));
        foreach (OrgEntitlementBoundary::retain($db, $grants, $now, true) as $grant) {
            $id = (int) $grant['course_id'];
            $assigned[$id] ??= $this->assignment();
            $active = $grant['status'] === 'active' && $this->inWindow($grant, 'starts_at', 'expires_at', $now)
                && ($grant['org_membership_id'] === null || (int) $grant['org_membership_id'] === (int) $membership['id']);
            if ($active) {
                $previous = $assigned[$id]['has_access'];
                $expiry = $grant['expires_at'];
                $assigned[$id]['expires_at'] = !$previous ? $expiry
                    : ($assigned[$id]['expires_at'] === null || $expiry === null ? null : max($assigned[$id]['expires_at'], $expiry));
                $assigned[$id]['has_access'] = true;
            } elseif (!$assigned[$id]['has_access']) {
                $assigned[$id]['expires_at'] = $grant['expires_at'] !== null && $grant['expires_at'] <= $now ? $grant['expires_at'] : null;
            }
        }
        $seen = [];
        foreach ($db->selectAll(OrgScopedReader::from($scope, 'compliance_assignments', 'a')
            ->select(['a.*', 'r.course_id', 'r.requirement'])->join('compliance_requirements', 'r', 'a.requirement_id', 'r.id', [['a.organisation_id', 'r.organisation_id']])
            ->join('courses', 'c', 'r.course_id', 'c.id')->where('c.state', '!=', 'trashed')
            ->where('a.user_id', '=', $actor->userId)->where('r.state', '=', 'active')->orderBy('a.cycle', Query::SORT_DESC)->orderBy('a.id', Query::SORT_DESC)) as $requirement) {
            $requirementId = (int) $requirement['requirement_id'];
            if (isset($seen[$requirementId])) {
                continue;
            }
            $seen[$requirementId] = true;
            $id = (int) $requirement['course_id'];
            $assigned[$id] ??= $this->assignment();
            if (in_array($requirement['requirement'], ['mandatory', 'required'], true)
                && !in_array($requirement['compliance_status'], ['exempt', 'not_required'], true)) {
                $assigned[$id]['required'] = true;
                $pending = $requirement['compliance_status'] !== 'suspended'
                    && ($requirement['completed_at'] === null || in_array($requirement['compliance_status'], ['expired', 'overdue'], true));
                if ($pending) {
                    $assigned[$id]['outstanding'] = true;
                    $due = $requirement['due_at'];
                    if ($due !== null && ($assigned[$id]['due_at'] === null || $due < $assigned[$id]['due_at'])) {
                        $assigned[$id]['due_at'] = $due;
                    }
                } else {
                    $assigned[$id]['completed_at'] = $requirement['completed_at'];
                }
            }
        }
        $available = [];
        $reader = new OrgCatalogueReader($db, $this->clock, $scope);
        $unitId = (int) ($membership['org_unit_id'] ?? 0);
        foreach ($db->selectAll(OrgScopedReader::from($scope, 'org_catalogue_entries')->where('state', '=', 'active')) as $entry) {
            if (!$this->inWindow($entry, 'starts_at', 'ends_at', $now)) {
                continue;
            }
            if ($entry['scope_type'] === 'unit' && ((int) $entry['scope_id'] !== $unitId)
                && (!(bool) $entry['applies_to_descendants'] || !in_array($unitId, $this->store->unitSubtree($orgId, (int) $entry['scope_id']), true))) {
                continue;
            }
            foreach ($this->catalogue->resolve($scope, $entry, $reader) as $grant) {
                if (!isset($assigned[$grant->courseId])) {
                    if (!isset($available[$grant->courseId]) || $grant->grantMode === 'auto_grant') {
                        $available[$grant->courseId] = $grant->grantMode;
                    }
                }
            }
        }
        return ['organisation' => ['id' => $orgId, 'name' => (string) $membership['organisation_name']],
            'assigned' => $assigned, 'available' => $available,
            'summary' => ['assigned' => count($assigned), 'available' => count($available),
                'required' => count(array_filter($assigned, static fn (array $a): bool => (bool) $a['outstanding']))]];
    }

    /** @return array<string, mixed> */
    private function assignment(): array
    {
        return ['has_access' => false, 'required' => false, 'outstanding' => false, 'due_at' => null, 'completed_at' => null, 'expires_at' => null];
    }

    /** @param array<string, mixed> $row */
    private function inWindow(array $row, string $start, string $end, string $now): bool
    {
        return ($row[$start] === null || $row[$start] <= $now) && ($row[$end] === null || $row[$end] > $now);
    }
}
