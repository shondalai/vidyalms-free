<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunState;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * What one pass through the pipeline produced.
 *
 * It carries the ledger row's id rather than the row, because a caller that
 * wanted the row can read it and a caller that got handed it would be tempted to
 * write it. The refusal is the typed one, so a surface renders a reason key and
 * a job log records a machine-countable outcome rather than a sentence.
 */
final class AiRunReport
{
    /**
     * @param list<int>    $draftIds
     * @param list<string> $manifest the redaction manifest, which is the dry
     *                               run's whole product and the right-to-know
     *                               view's content
     */
    public function __construct(
        public readonly int $runId,
        public readonly AiRunState $state,
        public readonly CostRecord $spent,
        public readonly array $draftIds,
        public readonly array $manifest,
        public readonly string $inputsDigest,
        public readonly ?AiRefusal $refusal = null,
        public readonly ?\DateTimeImmutable $deferUntil = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly string $log = '',
    ) {
    }

    public function succeeded(): bool
    {
        return $this->state === AiRunState::Succeeded;
    }

    public function wasRefused(): bool
    {
        return $this->refusal !== null;
    }

    public function wasDeferred(): bool
    {
        return $this->deferUntil !== null;
    }

    /** A dry run, which called no bridge and wrote no artefact. */
    public function wasPreview(): bool
    {
        return $this->state === AiRunState::Previewed;
    }
}
