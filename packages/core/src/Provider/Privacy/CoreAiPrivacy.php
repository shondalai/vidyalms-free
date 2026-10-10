<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Privacy;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Privacy\DescribesForArticle30;
use Shondalai\VidyaLMS\Core\Registry\Privacy\EraseOutcome;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesSubjectRecords;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorInterface;
use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;
use Shondalai\VidyaLMS\Core\Registry\Privacy\SubjectPrivacyGroup;

/**
 * The AI tables, in both privacy manifests.
 *
 * The wave that built the AI spine shipped `GET /me/ai-activity` as the
 * Article 15 answer and left the subject access export and the erasure path
 * blind to every AI row. `ai_run_subjects` is the table that answers whose
 * data a run was about; `ai_drafts.payload` holds submission-derived and
 * answer-derived text about named learners; `ai_runs` carries the person who
 * asked and the person it was asked about; `ai_policy_acceptances` records a
 * consent. None of them was described, exported or erased, so a subject
 * access request answered without them, and an erasure left draft payloads
 * standing for their ninety days and run rows for at least the hundred and
 * eighty day floor.
 *
 * Four record classes, and the erasure treats them differently on purpose.
 *
 * **Drafts go.** A draft payload is the learner's own text or text derived
 * from it, and a discarded or accepted draft has no remaining purpose: the
 * artefact, if there is one, now lives in its own table with its own history.
 *
 * **Runs are pseudonymised, not deleted.** The run row is the ledger the
 * refusal counts, the spend and the outage post-mortem read, and deleting it
 * would delete somebody else's evidence to satisfy this person's request. The
 * two user columns are cleared and the subject rows go, so the row survives
 * as an unattributed fact.
 *
 * **Provenance is pseudonymised and never deleted.** It is the editorial
 * responsibility record for a published artefact: which task produced it, at
 * which version, under which disclosure tier, and whether a human edited it.
 * Deleting it would leave a published question with no record of how it came
 * to exist, which is the one thing the provenance row exists to prevent. The
 * accepting person's id is what is removed.
 *
 * The organisation bound is the same one the other contributors state: a
 * tenant erases the rows its own organisation stamped, and rows belonging to
 * the site or to another organisation are reported untouched rather than
 * quietly reached.
 */
final class CoreAiPrivacy implements PrivacyContributorInterface, DescribesForArticle30, ErasesSubjectRecords
{
    public const RECORD_RUNS = 'ai_runs';
    public const RECORD_SUBJECTS = 'ai_run_subjects';
    public const RECORD_DRAFTS = 'ai_drafts';
    public const RECORD_PROVENANCE = 'ai_artefact_provenance';
    public const RECORD_ACCEPTANCES = 'ai_policy_acceptances';

    private const RUNS = 'ai_runs';
    private const SUBJECTS = 'ai_run_subjects';
    private const DRAFTS = 'ai_drafts';
    private const PROVENANCE = 'ai_artefact_provenance';
    private const ACCEPTANCES = 'ai_policy_acceptances';

    /**
     * What a subject may see of a run.
     *
     * `inputs_digest` is not among them: it is an opaque hash of what was
     * sent, it identifies nobody on its own, and a person cannot act on it.
     * `redaction_manifest` IS among them, because it is the honest answer to
     * "which fields about me left the site".
     *
     * @var list<string>
     */
    private const RUN_FIELDS = [
        'id',
        'task_key',
        'placement_key',
        'bridge_key',
        'model',
        'actor_kind',
        'principal_user_id',
        'on_behalf_of_user_id',
        'course_id',
        'organisation_id',
        'redaction_manifest',
        'dry_run',
        'state',
        'refusal_kind',
        'retain_until',
        'created_at',
    ];

    /** @var list<string> */
    private const DRAFT_FIELDS = [
        'id',
        'run_id',
        'draft_kind',
        'subject_type',
        'subject_id',
        'course_id',
        'state',
        'disclosure_tier',
        'retain_until',
        'created_at',
        'payload',
        'edited_payload',
        'citations',
        'reject_reason',
    ];

    /** @var list<string> */
    private const ACCEPTANCE_FIELDS = [
        'id',
        'user_id',
        'policy_version',
        'scope_type',
        'scope_id',
        'placement_key',
        'accepted_at',
        'evidence',
    ];

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function key(): string
    {
        return 'core-ai';
    }

    public function describes(): array
    {
        return [
            self::RECORD_RUNS => 'AI runs raised by or about this person',
            self::RECORD_DRAFTS => 'AI drafts, including text derived from this person\'s work',
            self::RECORD_PROVENANCE => 'How an accepted AI artefact came to exist',
            self::RECORD_ACCEPTANCES => 'This person\'s acceptance of a data-use policy version',
        ];
    }

    /**
     * The Article 30(1) entries for the four AI record classes.
     *
     * There were none, so the generated record carried four classes with an
     * empty purpose and an empty lawful basis. Article 30(1)(b) makes the
     * purposes of processing a mandatory element, and an entry with no purpose
     * is not a record of processing but a table name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function article30(): array
    {
        $common = [
            self::SUBJECT_CATEGORIES => ['learners', 'authors', 'administrators'],
            self::RECIPIENTS => ['the person the run is about', 'site administrators'],
            self::LAWFUL_BASIS => 'legitimate interests',
        ];

        return [
            self::RECORD_RUNS => $common + [
                self::PURPOSE => 'Running an assistive model task somebody asked for, and recording what was '
                    . 'sent, what came back and what it cost.',
                self::DATA_CATEGORIES => ['identifiers', 'redaction manifests', 'model outcomes'],
                self::TABLES => [self::RUNS, self::SUBJECTS],
            ],
            self::RECORD_DRAFTS => $common + [
                self::PURPOSE => 'Holding a draft an author may accept or discard, including text derived '
                    . 'from a person\'s own work.',
                self::DATA_CATEGORIES => ['identifiers', 'draft content'],
                self::TABLES => [self::DRAFTS],
            ],
            self::RECORD_PROVENANCE => $common + [
                self::PURPOSE => 'Recording how an accepted artefact came to exist, so a reader can tell '
                    . 'authored work from generated work.',
                self::DATA_CATEGORIES => ['identifiers', 'provenance references'],
                self::TABLES => [self::PROVENANCE],
            ],
            self::RECORD_ACCEPTANCES => $common + [
                self::PURPOSE => 'Evidencing which version of the data-use policy this person accepted, and '
                    . 'when.',
                self::DATA_CATEGORIES => ['identifiers', 'policy versions', 'timestamps'],
                self::LAWFUL_BASIS => 'legal obligation',
                self::TABLES => [self::ACCEPTANCES],
            ],
        ];
    }

    public function subjectGroups(int $userId, string $email): array
    {
        if ($userId < 1) {
            return [];
        }

        return [
            new SubjectPrivacyGroup(
                'vidyalms-ai-runs-raised',
                'AI runs this person raised',
                self::RUN_FIELDS,
                static fn (): Query => Query::from(self::RUNS)
                    ->select(self::RUN_FIELDS)
                    ->where('principal_user_id', '=', $userId),
            ),
            // The two groups below resolve the run ids first and narrow on
            // them, rather than joining ai_run_subjects. A group query is
            // handed to a caller that orders and pages it by `id`, so a join
            // would make that column ambiguous in somebody else's statement.
            new SubjectPrivacyGroup(
                'vidyalms-ai-runs-about',
                'AI runs this person was a subject of',
                self::RUN_FIELDS,
                fn (): Query => Query::from(self::RUNS)
                    ->select(self::RUN_FIELDS)
                    ->whereIn('id', $this->runIdsSubjectOf($userId)),
            ),
            new SubjectPrivacyGroup(
                'vidyalms-ai-drafts',
                'AI drafts produced by a run this person was a subject of',
                self::DRAFT_FIELDS,
                fn (): Query => Query::from(self::DRAFTS)
                    ->select(self::DRAFT_FIELDS)
                    ->whereIn('run_id', $this->runIdsSubjectOf($userId)),
            ),
            new SubjectPrivacyGroup(
                'vidyalms-ai-policy-acceptances',
                'Data-use policy versions this person accepted',
                self::ACCEPTANCE_FIELDS,
                static fn (): Query => Query::from(self::ACCEPTANCES)
                    ->select(self::ACCEPTANCE_FIELDS)
                    ->where('user_id', '=', $userId),
            ),
        ];
    }

    public function exportFor(OrgPrivacyContext $context, string $recordClass, int $page, int $perPage): array
    {
        $organisationId = $context->scope->organisationId;
        $userId = $context->userId;

        $query = match ($recordClass) {
            self::RECORD_RUNS => Query::from(self::RUNS)->where('organisation_id', '=', $organisationId),
            self::RECORD_DRAFTS => Query::from(self::DRAFTS)
                ->whereIn('run_id', $this->runIdsIn($organisationId)),
            self::RECORD_ACCEPTANCES => Query::from(self::ACCEPTANCES)
                ->where('scope_type', '=', 'organisation')
                ->where('scope_id', '=', $organisationId),
            default => null,
        };

        if ($query === null) {
            return ['items' => [], 'total' => 0];
        }

        if ($userId !== null) {
            $query = match ($recordClass) {
                self::RECORD_RUNS => $query->whereIn('id', $this->runIdsFor($userId, $organisationId)),
                self::RECORD_DRAFTS => $query->whereIn('run_id', $this->runIdsFor($userId, $organisationId)),
                default => $query->where('user_id', '=', $userId),
            };
        }

        $fields = match ($recordClass) {
            self::RECORD_RUNS => self::RUN_FIELDS,
            self::RECORD_DRAFTS => self::DRAFT_FIELDS,
            default => self::ACCEPTANCE_FIELDS,
        };

        $total = $this->database->count(clone $query);
        $items = $this->database->selectAll(
            $query->select($fields)->limit($perPage)->offset(($page - 1) * $perPage),
        );

        return ['items' => $items, 'total' => $total];
    }

    public function eraseFor(OrgPrivacyContext $context): array
    {
        $userId = $context->userId ?? throw new \LogicException('Erasure requires a subject.');
        $organisationId = $context->scope->organisationId;
        $runIds = $this->runIdsIn($organisationId);
        $subjectRuns = $this->runIdsSubjectOf($userId);
        $this->requireSeparableDrafts(array_values(array_intersect($runIds, $subjectRuns)), $userId);

        $drafts = 0;
        $subjects = 0;
        $runs = 0;

        $provenance = 0;
        foreach ($runIds as $runId) {
            if (in_array($runId, $subjectRuns, true)) {
                foreach ($this->database->selectAll(Query::from(self::DRAFTS)->select(['id'])->where('run_id', '=', $runId)) as $draft) {
                    $this->database->delete('ai_draft_findings', ['draft_id' => (int) $draft['id']]);
                }
                $drafts += $this->database->delete(self::DRAFTS, ['run_id' => $runId]);
                $this->database->update(self::RUNS, ['subject_type' => null, 'subject_id' => null,
                    'redaction_manifest' => '{}', 'error_message' => null], ['id' => $runId]);
                $this->database->update('ai_run_attempts', ['error_message' => null], ['run_id' => $runId]);
            }
            $subjects += $this->database->delete(self::SUBJECTS, ['run_id' => $runId, 'user_id' => $userId]);
            $changed = 0;
            foreach (['principal_user_id', 'on_behalf_of_user_id'] as $column) {
                $changed += $this->database->update(self::RUNS, [$column => null], ['id' => $runId, $column => $userId]);
            }
            $runs += $changed > 0 ? 1 : 0;
            foreach (['reviewer_user_id', 'accepted_by'] as $column) {
                $this->database->update(self::DRAFTS, [$column => null], ['run_id' => $runId, $column => $userId]);
            }
            foreach ($this->database->selectAll(Query::from(self::DRAFTS)->select(['id'])->where('run_id', '=', $runId)) as $draft) {
                $this->database->update('ai_draft_findings', ['acknowledged_by' => null],
                    ['draft_id' => (int) $draft['id'], 'acknowledged_by' => $userId]);
            }
            $provenance += $this->database->update(self::PROVENANCE, ['accepted_by' => 0],
                ['run_id' => $runId, 'accepted_by' => $userId]);
        }

        $acceptances = $this->database->delete(self::ACCEPTANCES, [
            'user_id' => $userId,
            'scope_type' => 'organisation',
            'scope_id' => $organisationId,
        ]);

        return EraseOutcome::begin()
            ->erased(self::RECORD_DRAFTS, $drafts)
            ->erased(self::RECORD_SUBJECTS, $subjects)
            ->erased(self::RECORD_ACCEPTANCES, $acceptances)
            ->pseudonymised(self::RECORD_RUNS, $runs, RetentionBasis::LEGAL_CLAIMS)
            // The provenance row is the editorial-responsibility record for a
            // published artefact. Deleting it leaves a live question with no
            // account of how it came to exist, so the person is removed from
            // it and the record stands.
            ->pseudonymised(self::RECORD_PROVENANCE, $provenance, RetentionBasis::LEGAL_OBLIGATION)
            ->untouchedElsewhere(self::RECORD_RUNS, $this->siteRunsFor($userId))
            ->warning(
                'AI runs this person was part of outside this organization are left alone, '
                . 'because an organization may not reach rows that belong to the site or to another tenant.',
            )
            ->toArray();
    }

    /**
     * The runs inside this organisation that this person raised or was a
     * subject of.
     *
     * @return list<int>
     */
    private function runIdsFor(int $userId, int $organisationId): array
    {
        $ids = [];

        foreach (
            $this->database->selectAll(
                Query::from(self::RUNS)
                    ->select(['id'])
                    ->where('organisation_id', '=', $organisationId)
                    ->where('principal_user_id', '=', $userId),
            ) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        $inOrganisation = $this->runIdsIn($organisationId);

        foreach ($this->runIdsSubjectOf($userId) as $runId) {
            if (\in_array($runId, $inOrganisation, true)) {
                $ids[] = $runId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every run this person is a recorded subject of, anywhere.
     *
     * @return list<int>
     */
    private function runIdsSubjectOf(int $userId): array
    {
        $ids = [];

        foreach ($this->database->selectAll(Query::from(self::RUNS)->select(['id'])
            ->where('on_behalf_of_user_id', '=', $userId)) as $row) {
            $ids[] = (int) $row['id'];
        }

        foreach (
            $this->database->selectAll(
                Query::from(self::SUBJECTS)->select(['run_id'])->where('user_id', '=', $userId),
            ) as $row
        ) {
            $ids[] = (int) $row['run_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * Every run stamped with this organisation.
     *
     * @return list<int>
     */
    private function runIdsIn(int $organisationId): array
    {
        $ids = [];

        foreach (
            $this->database->selectAll(
                Query::from(self::RUNS)->select(['id'])->where('organisation_id', '=', $organisationId),
            ) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        return array_values(array_unique($ids));
    }

    private function siteRunsFor(int $userId): int
    {
        return $this->database->count(
            Query::from(self::RUNS)->where('principal_user_id', '=', $userId)->whereNull('organisation_id'),
        );
    }

    /** @param list<int> $runIds */
    private function requireSeparableDrafts(array $runIds, int $userId): void
    {
        foreach ($runIds as $runId) {
            $otherSubjects = $this->database->count(Query::from(self::SUBJECTS)
                ->where('run_id', '=', $runId)->where('user_id', '!=', $userId));
            $run = $this->database->selectOne(Query::from(self::RUNS)->where('id', '=', $runId));
            $onBehalf = (int) ($run['on_behalf_of_user_id'] ?? 0);
            if (($otherSubjects > 0 || ($onBehalf > 0 && $onBehalf !== $userId))
                && $this->database->count(Query::from(self::DRAFTS)->where('run_id', '=', $runId)) > 0) {
                // Mixed free text cannot be safely separated by matching names.
                throw new \RuntimeException(sprintf(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    'AI run %d contains a draft about multiple learners. Review and remove or separate that draft before retrying this erasure.', $runId,
                ));
            }
        }
    }

    public function eraseSubjectRecords(int $userId, string $email): array
    {
        if ($userId < 1) {
            return ['erased' => [], 'anonymised' => [], 'warnings' => []];
        }
        return $this->database->transactional(function () use ($userId): array {
            $runIds = $this->runIdsSubjectOf($userId);
            // An instructor requesting a draft about somebody else is not its
            // data subject. Erasing the instructor anonymises attribution below;
            // it must not destroy another learner's feedback or review findings.
            foreach ($this->database->selectAll(Query::from(self::RUNS)->select(['id'])
                ->where('on_behalf_of_user_id', '=', $userId)) as $row) {
                $runIds[] = (int) $row['id'];
            }
            $this->requireSeparableDrafts(array_values(array_unique($runIds)), $userId);
            $erased = [];
            $anonymised = [];
            foreach (array_unique($runIds) as $runId) {
                foreach ($this->database->selectAll(Query::from(self::DRAFTS)->select(['id'])->where('run_id', '=', $runId)) as $draft) {
                    $erased['ai_draft_findings'] = ($erased['ai_draft_findings'] ?? 0)
                        + $this->database->delete('ai_draft_findings', ['draft_id' => (int) $draft['id']]);
                }
                $erased[self::DRAFTS] = ($erased[self::DRAFTS] ?? 0) + $this->database->delete(self::DRAFTS, ['run_id' => $runId]);
                $erased[self::SUBJECTS] = ($erased[self::SUBJECTS] ?? 0)
                    + $this->database->delete(self::SUBJECTS, ['run_id' => $runId, 'user_id' => $userId]);
                // Keep usage accounting, clear references and free text which may
                // reproduce a response. Published artefacts retain their provenance.
                $this->database->update(self::RUNS, ['subject_type' => null, 'subject_id' => null,
                    'redaction_manifest' => '{}', 'error_message' => null], ['id' => $runId]);
                foreach ($this->database->selectAll(Query::from('ai_run_attempts')->select(['id'])->where('run_id', '=', $runId)) as $attempt) {
                    $this->database->update('ai_run_attempts', ['error_message' => null], ['id' => (int) $attempt['id']]);
                }
            }
            foreach ([['ai_runs', 'principal_user_id'], ['ai_runs', 'on_behalf_of_user_id'],
                ['ai_drafts', 'reviewer_user_id'], ['ai_drafts', 'accepted_by'],
                ['ai_draft_findings', 'acknowledged_by'], ['ai_artefact_provenance', 'accepted_by']] as [$table, $column]) {
                $anonymised[$table . '.' . $column] = $this->database->update($table,
                    [$column => $table === self::PROVENANCE ? 0 : null], [$column => $userId]);
            }
            $erased[self::ACCEPTANCES] = $this->database->delete(self::ACCEPTANCES, ['user_id' => $userId]);
            return ['erased' => $erased, 'anonymised' => $anonymised, 'warnings' => []];
        });
    }
}
