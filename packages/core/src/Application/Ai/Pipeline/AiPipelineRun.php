<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Application\Ai\AiRouteCandidate;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunRequest;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunSubject;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\CancellationSignal;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\RedactedPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;

/**
 * One run in flight, as the stages hand it along.
 *
 * It is deliberately mutable, which every other object in this wave is not. The
 * stages are a pipeline and a pipeline over readonly values is a chain of
 * constructors nobody can insert a stage into: the readability the named stage
 * list exists to buy would have been spent on immutability the run does not
 * need, because the object lives inside one method call and is never stored,
 * shared or published.
 *
 * Two things it will not do:
 *
 * - it holds no bridge handle and no DatabasePort, so a stage cannot reach past
 *   the collaborators it was given;
 * - it holds the redacted payload rather than the raw one once redaction has
 *   run, so a later stage cannot send what the redactor removed.
 */
final class AiPipelineRun
{
    /** @var array<string, mixed> */
    public array $facts = [];

    /** @var list<AiRunSubject> */
    public array $subjects = [];

    public ?AiTaskInterface $task = null;

    public ?string $placementKey = null;

    public ?AiTaskPayload $payload = null;

    public ?RedactedPayload $redacted = null;

    /** @var list<string> */
    public array $manifest = [];

    /**
     * The manifest of what the bridge caller actually sent, or null when no
     * call was made.
     *
     * Kept beside the resolved payload's manifest rather than replacing it,
     * because the reserved row and the inputs digest are both written before
     * the task runs and must stay the record of what the pipeline resolved.
     * This is the record of what left, and the ledger row and the run report
     * are both corrected from it on the way out.
     *
     * @var list<string>|null
     */
    public ?array $manifestOnTheWire = null;

    /** @var array<string, mixed>|null */
    public ?array $outputSchema = null;

    /** @var list<string> */
    public array $droppedConstraints = [];

    public bool $schemaUnenforced = false;

    public ?bool $schemaValid = null;

    public ?int $runId = null;

    public ?int $routeId = null;

    public string $bridgeKey = 'null';

    public ?string $model = null;

    public CostRecord $reserved;

    public CostRecord $spent;

    public ?AiTaskResult $result = null;

    /**
     * What the bridge answered on the last successful call of this run, read
     * off the caller after the task returns.
     *
     * `AiTaskResult` carries nothing back from a bridge, so without this
     * nothing between the caller and the settle stage held the counterparty's
     * request id, fingerprint, prompt reference, finish reason or token counts,
     * and five declared columns on `ai_runs`, one of them indexed for the
     * reconciliation join, were written as literal nulls.
     */
    public ?BridgeResult $bridgeResult = null;

    public ?AiRefusal $refusal = null;

    public ?\DateTimeImmutable $deferUntil = null;

    public ?string $errorCode = null;

    public ?string $errorMessage = null;

    /** @var list<int> */
    public array $draftIds = [];

    /** @var list<ImportNote> */
    public array $notes = [];

    /**
     * The stop this run is being asked about, carried so a stage can hand it
     * to the task rather than the pipeline being the only thing that can see
     * it. It is not a bridge handle and not a database: it answers one
     * boolean and holds nothing.
     */
    public CancellationSignal $stop;

    /**
     * The candidate chain this run resolved, in position order.
     *
     * @var list<AiRouteCandidate>
     */
    public array $routes = [];

    public function __construct(
        public readonly AiRunRequest $request,
        public readonly \DateTimeImmutable $now,
        public readonly \DateTimeImmutable $deadline,
        /**
         * A per-run discriminator, so two runs that are alike in every stored
         * field do not mint the same ledger key.
         *
         * Empty for a JOB unit and only for one: there the key is derived on
         * purpose, so a resumed unit collides with the completed one and the
         * unique index refuses the duplicate. Everywhere else the derived key
         * was a bug wearing an invariant's clothes, because its finest grain
         * was one second: two identical previews in the same second, or two
         * refusals at one clock tick, collided and the second one was lost or
         * raised out of the pipeline.
         */
        public readonly string $nonce = '',
    ) {
        $this->placementKey = $request->placementKey;
        $this->reserved = CostRecord::none();
        $this->spent = CostRecord::none();
        $this->stop = CancellationSignal::never();
    }

    /**
     * The ledger key for this run, or for one named part of it.
     *
     * It is derived from what the run IS rather than from when it happened,
     * plus the per-run discriminator above. The salt separates keys that must
     * not collide inside one run: the reservation and the row a refusal
     * settles before the reservation stage was ever reached.
     */
    public function runKey(string $salt = ''): string
    {
        return hash('sha256', implode('|', [
            $this->request->taskKey,
            (string) ($this->request->jobId ?? 0),
            $this->request->task->subjectType,
            (string) ($this->request->task->subjectId ?? 0),
            $this->inputsDigest(),
            $this->now->format('Y-m-d\TH:i:s'),
            (string) $this->request->principal->userId,
            $this->nonce,
            $salt,
        ]));
    }

    /**
     * Halts the run with a typed refusal.
     *
     * A refusal is an ordinary outcome and never an exception: a ceiling
     * reached, a policy denial or a deadline is a thing the surface has to
     * render, and an exception out of a provider is reserved for programmer
     * error. The first refusal wins, because a later stage cannot un-refuse
     * what an earlier one already decided.
     */
    /**
     * @param array<string, scalar|null> $parameters
     */
    public function refuse(RefusalKind $kind, string $messageKey, array $parameters = []): void
    {
        if ($this->refusal !== null) {
            return;
        }

        $this->refusal = AiRefusal::of($kind, $messageKey, $parameters);
        $this->note(ImportNote::WARNING, sprintf('Refused: %s (%s)', $messageKey, $kind->value));
    }

    /**
     * Halts the run until an instant, which is what a ceiling policy answers.
     *
     * A defer is not a denial: "not now, at 09:00 on the first" is a real answer
     * that a deny-set cannot express, and the job status that carries it exists
     * so a two hundred item build that trips a monthly ceiling at item forty is
     * not filed as a breakage.
     */
    public function defer(\DateTimeImmutable $until, string $reason): void
    {
        if ($this->deferUntil === null || $until > $this->deferUntil) {
            $this->deferUntil = $until;
        }

        $this->note(ImportNote::INFO, $reason);
    }

    /** Halts the run because it broke, which is a different fact from a refusal. */
    public function fail(string $code, string $message): void
    {
        $this->errorCode ??= $code;
        $this->errorMessage ??= $message;
        $this->note(ImportNote::ERROR, $message);
    }

    public function isHalted(): bool
    {
        return $this->refusal !== null || $this->deferUntil !== null || $this->errorCode !== null;
    }

    public function note(string $level, string $message): void
    {
        $this->notes[] = ImportNote::of($level, $message);
    }

    /**
     * The digest of exactly what would be sent, computed over the redacted
     * payload when there is one and over the resolved payload's provenance when
     * there is not.
     *
     * Storing the digest rather than a second copy is what lets a later audit
     * prove what left the site without keeping the thing that left it.
     */
    public function inputsDigest(): string
    {
        $material = $this->redacted !== null
            ? implode("\n", array_map(
                static fn (\Shondalai\VidyaLMS\Core\Registry\Ai\PromptSegment $segment): string => $segment->label
                    . '|' . $segment->trust->value . '|' . hash('sha256', $segment->text),
                $this->redacted->segments,
            ))
            : implode("\n", $this->manifest);

        return hash('sha256', $this->request->taskKey . "\n" . $material);
    }

    /** The job log line for every note this run made, in order. */
    public function log(): string
    {
        return implode("\n", array_map(static fn (ImportNote $note): string => $note->line(), $this->notes));
    }
}
