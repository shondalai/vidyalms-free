<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Privacy;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportRunStore;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Privacy\EraseOutcome;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesSubjectRecords;
use Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\DescribesForArticle30;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorInterface;
use Shondalai\VidyaLMS\Core\Registry\Privacy\SubjectPrivacyGroup;

/**
 * `report_runs` rows and their artefacts, in both privacy manifests.
 *
 * The design's requirement is short and the reason it matters is not: a report
 * artefact is personal data sitting in object storage under a name nothing
 * recorded, so an erasure request had to hunt object storage by hand, which is
 * exactly what the old task's untracked blob forced. The manifest is what makes
 * these findable, and this contributor is what makes them answerable.
 *
 * It follows Moodle's split of a metadata provider from the request providers:
 * {@see describes()} is the Article 30 half, naming the record class, and the
 * export and erase halves are the request half.
 *
 * **Erasure takes the artefact as well as the row.** Deleting the manifest and
 * leaving the file would be the worst of both: the personal data survives and
 * nothing points at it any more, which is the untracked blob again with a
 * successful erasure report on top of it.
 */
final class CoreReportingPrivacy implements PrivacyContributorInterface, DescribesForArticle30, ErasesSubjectRecords
{
    public const RECORD_CLASS = 'report_runs';

    /**
     * The columns a subject may see. `scope_digest` is not among them: it is an
     * opaque hash of somebody's reach and it identifies no one, so exporting it
     * would hand a subject a value they cannot read and cannot act on.
     *
     * @var list<string>
     */
    private const FIELDS = [
        'id',
        'report_key',
        'format_key',
        'delivery_key',
        'organisation_id',
        'state',
        'row_count',
        'timezone',
        'created_at',
        'expires_at',
        'parameters',
        'requested_by',
        'rendered_as',
        'error',
        'delivery_error',
    ];

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ReportRunStore $runs,
        private readonly StoragePort $storage,
    ) {
    }

    public function key(): string
    {
        return 'core-reporting';
    }

    public function describes(): array
    {
        return [self::RECORD_CLASS => 'Report runs and their downloadable artefacts'];
    }

    /**
     * The Article 30(1) entry for report runs.
     *
     * It had none, so the generated record carried this class with an empty
     * purpose and an empty lawful basis, and Article 30(1)(b) makes the
     * purposes of processing a mandatory element of the record: an entry with
     * no purpose is not a record of processing, it is a table name.
     *
     * @return array<string, array<string, mixed>>
     */
    public function article30(): array
    {
        return [
            self::RECORD_CLASS => [
                self::PURPOSE => 'Producing the reports an administrator asked for, and keeping the artefact '
                    . 'long enough for them to download it.',
                self::SUBJECT_CATEGORIES => ['administrators', 'the people a report is about'],
                self::DATA_CATEGORIES => ['identifiers', 'report parameters', 'artefact references'],
                self::RECIPIENTS => ['the administrator who ran it', 'site administrators'],
                self::LAWFUL_BASIS => 'legitimate interests',
                self::TABLES => [ReportRunStore::TABLE],
            ],
        ];
    }

    public function subjectGroups(int $userId, string $email): array
    {
        if ($userId < 1) {
            return [];
        }

        $query = Query::from(ReportRunStore::TABLE)
            ->select(self::FIELDS)
            ->where('requested_by', '=', $userId);

        return [new SubjectPrivacyGroup(
            'vidyalms-report-runs',
            'Report runs',
            self::FIELDS,
            static fn (): Query => clone $query,
        )];
    }

    public function exportFor(OrgPrivacyContext $context, string $recordClass, int $page, int $perPage): array
    {
        if ($recordClass !== self::RECORD_CLASS) {
            return ['items' => [], 'total' => 0];
        }

        $query = Query::from(ReportRunStore::TABLE)
            ->where('organisation_id', '=', $context->scope->organisationId);

        if ($context->userId !== null) {
            $query->where('requested_by', '=', $context->userId);
        }

        $total = $this->database->count(clone $query);
        $items = $this->database->selectAll(
            $query->select(self::FIELDS)->orderBy('id')->limit($perPage)->offset(($page - 1) * $perPage),
        );

        return ['items' => $items, 'total' => $total];
    }

    public function eraseFor(OrgPrivacyContext $context): array
    {
        $userId = $context->userId ?? throw new \LogicException('Erasure requires a subject.');
        $erased = 0;
        $filesRemoved = 0;
        $files = new \Shondalai\VidyaLMS\Core\Application\Platform\PrivacyFileDeletion($this->database, $this->storage);

        foreach ($this->runs->idsForSubject($userId) as $id) {
            $run = $this->runs->find($id);

            if ($run === null || $run->organisationId !== $context->scope->organisationId) {
                // A site-scoped run, or one belonging to another tenant, is
                // not this organisation's to erase. An organisation may not
                // reach a person's rows outside itself, which is the same rule
                // the membership contributor states.
                continue;
            }

            $this->database->transactional(function () use ($run, $files, $userId, $id, &$filesRemoved): void {
                if ($run->storagePath !== null && str_starts_with($run->storagePath, 'reports/')) {
                    try {
                        \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::downloadable($run->storagePath);
                        $files->enqueue($run->storagePath, $userId);
                        $filesRemoved++;
                    } catch (\InvalidArgumentException) {
                        // Invalid old references cannot prevent erasing the manifest.
                    }
                }
                $this->runs->delete($id);
            });
            $erased++;
        }

        // Untouched rather than retained, and the difference is the whole point
        // of the receipt. A retained record class names the basis it is kept
        // under; these rows are not kept under any basis, because they were
        // never this organisation's to erase. Reporting them as retained left
        // one record class in every receipt with no basis behind it, which is
        // exactly the state the basis model exists to make impossible.
        return EraseOutcome::begin()
            ->erased(self::RECORD_CLASS, $erased)
            ->erased('report_artefacts_queued_for_deletion', $filesRemoved)
            ->untouchedElsewhere('site_report_runs', $this->siteRunsFor($userId))
            ->warning(
                'Report runs this person requested outside this organization are left alone, '
                . 'because an organization may not reach rows that belong to the site or to another tenant.',
            )
            ->toArray();
    }

    private function siteRunsFor(int $userId): int
    {
        return $this->database->count(
            Query::from(ReportRunStore::TABLE)->where('requested_by', '=', $userId)->whereNull('organisation_id'),
        );
    }

    public function eraseSubjectRecords(int $userId, string $email): array
    {
        $erased = [];
        $warnings = [];
        $files = new \Shondalai\VidyaLMS\Core\Application\Platform\PrivacyFileDeletion($this->database, $this->storage);
        if ($userId > 0) {
            foreach ($this->runs->idsForSubject($userId) as $id) {
                try {
                    $run = $this->runs->find($id);
                    if ($run === null) {
                        continue;
                    }
                    $this->database->transactional(function () use ($run, $files, $userId, $id): void {
                        if ($run->storagePath !== null && str_starts_with($run->storagePath, 'reports/')) {
                            try {
                                \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::downloadable($run->storagePath);
                                $files->enqueue($run->storagePath, $userId);
                            } catch (\InvalidArgumentException) {
                                // An invalid stored path cannot prevent database erasure.
                            }
                        }
                        $this->runs->delete($id);
                    });
                    $erased[self::RECORD_CLASS] = ($erased[self::RECORD_CLASS] ?? 0) + 1;
                } catch (\Throwable $failure) {
                    // Database failures keep the manifest available to a retry.
                    $warnings[] = 'report_runs: ' . $failure->getMessage();
                }
            }
        }
        array_push($warnings, ...$files->drain($userId));
        return ['erased' => $erased, 'anonymised' => [], 'warnings' => $warnings];
    }

    /** Every state a run can be in, so a manifest reader has the vocabulary. */
    public static function states(): string
    {
        return implode('|', ReportRun::STATES);
    }
}
