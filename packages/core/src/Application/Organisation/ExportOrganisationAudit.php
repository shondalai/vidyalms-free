<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;

/** A bounded export of actual membership events, not a claim of a regulatory access ledger. */
final class ExportOrganisationAudit
{
    public function __construct(private readonly OrgScopeResolver $access, private readonly OrganisationStore $store)
    {
    }

    /** @return array{csv: string, total: int, page: int, per_page: int, snapshot_id: int} */
    public function execute(Principal $actor, int $orgId, int $page, int $perPage, int $snapshotId, string $eventKey): array
    {
        $scope = $this->access->require($actor, $orgId, 'events');
        OrganisationInput::page($page, $perPage);
        if ($snapshotId < 0 || $snapshotId > 2147483646 || ($eventKey !== '' && preg_match('/^[a-zA-Z0-9_.-]{1,60}$/D', $eventKey) !== 1)) {
            throw ValidationException::forField('filter', 'Use a valid event key and snapshot id.');
        }
        // The tenant predicate is injected by the reader rather than typed
        // here, so it cannot be the line somebody forgets on the next export.
        //
        // The unit predicate beside it is the line that WAS forgotten. The
        // paged read of this same table narrows on the subtree
        // (OrganisationStore::events), this one did not, and while the free
        // reach literal excluded the events right the difference was invisible.
        // The first role declaring a wider reach made it a leak: the same
        // caller saw one unit on the screen and the whole organisation in the
        // file. Aliased and qualified because the narrowing joins the closure
        // table and both sides carry an organisation_id.
        $query = OrgScopedReader::from($scope, 'org_membership_events', 'e');
        $scope->constrainUnit($query, 'e.org_unit_id');
        if ($snapshotId === 0) {
            $last = $this->store->database->selectOne((clone $query)->select(['e.id'])->orderBy('e.id', 'DESC')->limit(1));
            $snapshotId = (int) ($last['id'] ?? 0);
        }
        $query->where('e.id', '<=', $snapshotId);
        if ($eventKey !== '') {
            $query->where('e.event_key', '=', $eventKey);
        }
        $total = $this->store->database->count(clone $query);
        $fields = ['id', 'organisation_id', 'event_key', 'actor_id', 'subject_id', 'organisation_revision', 'created_at'];
        $rows = $this->store->database->selectAll(
            $query->select(array_map(static fn (string $field): string => 'e.' . $field, $fields))
                ->orderBy('e.id')->limit($perPage)->offset(($page - 1) * $perPage)
        );
        // Portable core writes CSV to a bounded php://temp stream, not a public filesystem path.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create the CSV response.');
        }
        try {
            // Portable core writes CSV to a bounded php://temp stream, not a public filesystem path.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $fields, ',', '"', '', "\r\n");
            // The guard that used to be this one inline expression is now
            // CellSafetyPolicy, asked here and by every other CSV writer in the
            // product. It was correct and it was in the wrong place: the second
            // writer copied the comment or did not, and the rule was true of
            // one route rather than of the product. Two things changed with the
            // move and both are widenings: the trigger set is now the full
            // OWASP list including the full-width forms a CJK locale produces,
            // and the prefix is a tab inside the quoted field rather than an
            // apostrophe, which is the Excel-resistant form OWASP names.
            $safety = CellSafetyPolicy::spreadsheetSafe();

            foreach ($rows as $row) {
                $cells = array_map(
                    static fn (string $key): string => $safety->apply((string) ($row[$key] ?? '')),
                    $fields,
                );
                fputcsv($stream, $cells, ',', '"', '', "\r\n");
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            if ($csv === false) {
                throw new \RuntimeException('Unable to read the CSV response.');
            }
        } finally {
            // Portable core writes CSV to a bounded php://temp stream, not a public filesystem path.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($stream);
        }
        $this->store->event(
            $scope,
            (int) $this->store->organisation($scope)['revision'],
            'audit.exported',
            null,
            ['page' => $page, 'per_page' => $perPage, 'snapshot_id' => $snapshotId, 'record_count' => \count($rows)]
        );

        return ['csv' => $csv, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'snapshot_id' => $snapshotId];
    }
}
