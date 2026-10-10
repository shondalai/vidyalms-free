<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\DataHandling;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\DeclaresDataHandling;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\EstimatesCost;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\GeneratesText;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\HonoursIdempotency;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\ReportsUsage;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\BridgeContext;

/**
 * The free bridge, which reaches no model and never will.
 *
 * It exists so the model bridge seam has a working free provider and a free
 * code path that reaches it, which is the rule every other registry in the
 * product already meets. The eleven deterministic tasks route here: they are
 * functions from a draft or a chunk set to findings, they need no model to
 * compute an answer, and routing them through the same caller, the same
 * redactor, the same manifest, the same attempt row and the same provenance
 * path as a paid task is what makes the free build a real exercise of the
 * seam rather than a description of one.
 *
 * **It opens no socket, reads no credential and imports nothing.** Every
 * answer is computed from the request in front of it, so a free site running
 * every check task makes no outbound call at any point.
 *
 * The capability declarations are all honest rather than convenient. It
 * generates text, and the text is always empty, which is what no model access
 * means when stated literally. It reports usage, because zero tokens is a
 * fact here rather than an absence. It honours idempotency, because the same
 * key gets the same answer and nothing was ever spent. It does **not** declare
 * ProducesStructuredOutput, so a run wanting a structure is flagged
 * `schema_unenforced` and validated after the fact, exercising the absent
 * branch on every free site. It meters nothing and reconciles nothing,
 * because there is no counterparty to reconcile with.
 */
final class NullModelBridge implements
    ModelBridgeInterface,
    GeneratesText,
    EstimatesCost,
    HonoursIdempotency,
    ReportsUsage,
    DeclaresDataHandling
{
    public const KEY = 'null';

    /** The value BridgeResult::$model carries, so a run can say which bridge answered. */
    public const MODEL = 'null';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.bridge.null.label';
    }

    /**
     * Nothing is denominated, because nothing is spent. This is the one
     * bridge for which `none` is the whole truth rather than an admission.
     */
    public function unit(): string
    {
        return CostRecord::UNIT_NONE;
    }

    public function run(BridgeRequest $request, BridgeContext $context): BridgeResult|BridgeRefusal
    {
        if ($context->hasExpired()) {
            return BridgeRefusal::of(
                RefusalKind::DeadlineExceeded,
                'ai.refusal.deadline_exceeded',
                ['bridge' => self::KEY],
            );
        }

        if ($request->payload->isEmpty()) {
            return BridgeRefusal::of(
                RefusalKind::InputRejected,
                'ai.refusal.empty_payload',
                ['bridge' => self::KEY],
            );
        }

        if ($request->expectsStructure()) {
            // A structure is the one thing this bridge genuinely cannot
            // produce, and saying so is not the same as failing: a caller
            // reads unsupported_capability and moves to the next candidate
            // rather than retrying a route that will never answer.
            return BridgeRefusal::of(
                RefusalKind::UnsupportedCapability,
                'ai.refusal.null_bridge_produces_no_structure',
                ['bridge' => self::KEY],
            );
        }

        return BridgeResult::text(
            '',
            self::MODEL,
            CostRecord::none(),
            null,
            self::KEY . ':' . $request->idempotencyKey,
            null,
            'null_bridge',
            0,
            0,
            true,
        );
    }

    public function estimate(BridgeRequest $request): CostEstimate
    {
        return CostEstimate::free();
    }

    public function dataHandling(): DataHandling
    {
        return DataHandling::nothingLeavesTheSite();
    }
}
