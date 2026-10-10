<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Shondalai\VidyaLMS\Core\Application\Platform\GetSiteLearningReport;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportRunStore;

/**
 * The read half of the asynchronous export contract: the run's state, and on
 * completion a short-lived signed URL.
 *
 * **A run belongs to the person who asked for it**, and a site manager does not
 * inherit it. That is stricter than the capability check alone and it is the
 * point: the artefact was computed under one caller's visibility scope, so
 * handing it to a second caller who happens to hold the same capability serves
 * rows that were never computed for them. The digest is what records the reach
 * and the requester is what records the reader, and both have to match.
 */
final class GetReportRun
{
    public function __construct(
        private readonly ReportRunStore $runs,
        private readonly ReportExportPipeline $pipeline,
        private readonly AccessGuard $guard,
        private readonly ?\Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess $organisations = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, int $id): array
    {
        $this->guard->require($principal, Capability::VIEW_REPORTS);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $run = $this->runs->find($id) ?? throw EntityNotFoundException::for('report run', $id);

        if ($run->requestedBy !== $principal->userId) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw NotAuthorizedException::forCapability(Capability::VIEW_REPORTS);
        }

        $currentDigest = $run->organisationId === null
            ? \Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest::of(GetSiteLearningReport::scopeFor($this->guard, $principal))
            : $this->organisations?->require($principal, $run->organisationId, GetOrganisationReport::ACTION)->digest();
        if ($currentDigest === null || !hash_equals($run->scopeDigest, $currentDigest)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw NotAuthorizedException::forCapability(Capability::VIEW_REPORTS);
        }

        return [
            'id' => $run->id,
            'report' => $run->reportKey,
            'format' => $run->formatKey,
            'delivery' => $run->deliveryKey,
            'state' => $run->state,
            'row_count' => $run->rowCount,
            // Progress beside the answer, so a poll on a long export can say
            // how far it has got rather than only that it is still going.
            'current_line' => $run->currentLine,
            'truncated' => $run->truncated,
            'byte_size' => $run->byteSize,
            'timezone' => $run->timezone,
            'organisation_id' => $run->organisationId,
            'error' => $run->error === null ? null : 'Report export failed. Ask a site administrator to check the server log.',
            'started_at' => $run->startedAt?->format(DATE_ATOM),
            'finished_at' => $run->finishedAt?->format(DATE_ATOM),
            'expires_at' => $run->expiresAt->format(DATE_ATOM),
            // Minted per read and never stored, so a URL cannot outlive the
            // window it was issued for by sitting in a JSON column.
            'download_url' => $this->pipeline->downloadUrl($run),
        ];
    }

    /** What a caller polling a queued or running export should wait, in seconds. */
    public static function retryAfter(ReportRun $run): int
    {
        return $run->isTerminal() ? 0 : 5;
    }
}
