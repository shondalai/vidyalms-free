<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai;

use Shondalai\VidyaLMS\Core\Application\Ai\AiAttemptLedgerInterface;
use Shondalai\VidyaLMS\Core\Application\Ai\AiAttemptRecord;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunAttempt;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;

/**
 * The durable half of the attempt ledger: what the bridge caller reports goes on
 * to the ai_run_attempts rows the outage post-mortem and the billing dispute
 * both read.
 *
 * The caller holds the narrow one-method interface and this holds the
 * repository, which is the split that keeps a bridge holder from also holding a
 * class that can do twenty other things. The in-memory ledger stays the default
 * for a caller with no run behind it, so a task exercised in isolation still
 * runs and still reports, and nothing silently writes rows for a run that does
 * not exist.
 *
 * An attempt naming a run key nothing resolves is DROPPED rather than throwing.
 * A ledger write is a record of a call that already happened, and turning a
 * bookkeeping miss into an exception would lose the call as well as the record.
 */
final class SqlAiAttemptLedger implements AiAttemptLedgerInterface
{
    public function __construct(private readonly AiRunRepository $runs)
    {
    }

    public function record(AiAttemptRecord $attempt): void
    {
        $run = $this->runs->findByKey($attempt->runKey);

        if ($run === null) {
            return;
        }

        $opened = AiRunAttempt::opened(
            $run->id,
            $attempt->attempt,
            $attempt->bridgeKey,
            $attempt->at,
            $run->routeId,
            $attempt->model === '' ? null : $attempt->model,
            $attempt->position,
        );

        $this->runs->recordAttempt($opened->closed(
            $attempt->outcome === AiAttemptRecord::OUTCOME_SUCCEEDED,
            $attempt->at,
            $attempt->refusalKind,
            $attempt->outcome === AiAttemptRecord::OUTCOME_ERRORED ? 'bridge_error' : null,
            null,
            $attempt->tokensIn,
            $attempt->tokensOut,
            $attempt->cost->unit,
            $attempt->cost->amount,
            $attempt->providerRequestId,
            $attempt->autoRetrySafe,
            // The dropped constraints have their own column, because "which
            // constraints did this route not enforce" is what the item review
            // screen shows beside a schema_unenforced flag. They used to be
            // written into error_message, so a succeeded attempt carried an
            // error message and the list could not be read as a list.
            $attempt->schemaUnenforced ? $attempt->droppedConstraints : [],
        ));
    }
}
