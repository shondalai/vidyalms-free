<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiRedactorInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRedactorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Context\AiRedactionContext;

/**
 * Stage 4. Produces the manifest of exactly what would be sent, and the payload
 * that may be sent.
 *
 * **This is what makes the dry run the privacy artefact.** For an importer a dry
 * run counts what it would create and writes nothing; for an AI run it renders
 * the exact redacted payload and the field manifest the active policy permits,
 * computes the inputs digest, and calls no bridge. That gives a site owner an
 * inspectable, per-run answer to "what exactly would you send about my learners"
 * before a token is spent, and the extra costs nearly nothing because the
 * redaction stage already runs in front of every call.
 *
 * The redactor is a registry rather than a class for the reason the rest of the
 * design exists: a site wanting a stricter level, or an organisation under a
 * different data-use statement, would otherwise have to fork the one class that
 * is the only path to a bridge. **An unresolvable redactor refuses the run**,
 * which is the gate direction and not the notification one.
 *
 * The manifest travels on the run and is written to the ledger row, so the
 * learner's right-to-know view renders which fields were sent rather than a
 * category description somebody wrote once.
 */
final class RedactStage implements AiRunStageInterface
{
    public function __construct(
        private readonly AiRedactorRegistry $redactors,
        private readonly string $siteRef,
        private readonly string $defaultLevel = 'strict',
    ) {
    }

    public function name(): string
    {
        return 'Redact';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        $payload = $run->payload ?? AiTaskPayload::create($run->request->task, [], [], $run->request->task->options);
        $level = $this->levelFor($run);

        if (!$this->redactors->has($level)) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.redactor.unresolvable', ['level' => $level]);

            return;
        }

        $redactor = $this->redactors->get($level);

        if (!$redactor instanceof AiRedactorInterface) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.redactor.unresolvable', ['level' => $level]);

            return;
        }

        $task = $run->task;
        $context = AiRedactionContext::create(
            $level,
            $this->siteRef,
            $task instanceof RequiresFacts ? $task->requiredFacts() : [],
            $run->request->locale,
        );

        try {
            // The declared-fact filter runs HERE and not inside the redactor,
            // so it holds for every registered redactor rather than for the
            // shipped one alone: a replacement that forgot the membership test
            // could otherwise put an undeclared fact on the wire.
            $redacted = $redactor->redact($payload->onlyFacts($context->declaredFactKeys), $context);
        } catch (\Throwable $broken) {
            // A redactor that broke the manifest contract, or broke at all, is
            // a gate that did not close. An unresolved gate fails closed, and
            // the run refuses rather than the exception leaving the pipeline.
            $run->refuse(RefusalKind::NotConfigured, 'ai.redactor.contract_broken', [
                'level' => $level,
                'reason' => $broken->getMessage(),
            ]);

            return;
        }

        $run->redacted = $redacted;
        // The manifest is the redactor's own, and the value object now refuses
        // one that is not exactly the emitted segment labels, so this can no
        // longer be shorter than what is on the wire.
        $run->manifest = $redacted->manifest;
    }

    /**
     * The redaction level in force, read from the run's own options when the
     * caller resolved the active policy and falling back to the shipped default.
     *
     * strict is the default rather than the loosest level, because a run whose
     * policy could not be read must not send more than one whose policy could.
     */
    private function levelFor(AiPipelineRun $run): string
    {
        $level = $run->request->options['redaction_level'] ?? null;

        return \is_string($level) && $level !== '' ? $level : $this->defaultLevel;
    }
}
