<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiGroundingMode;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresGrounding;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * Does every draft cite something, and does everything it cites exist.
 *
 * Two failures with opposite causes and the same symptom on a review screen.
 * A draft with no citation was produced from nothing the site can point at,
 * which is the grounded-generation claim quietly not holding. A draft citing
 * a chunk key that is not in the resolved grounding set cited something that
 * was never sent, which is the other half and is worse: it looks grounded and
 * is not.
 *
 * The check declares Optional grounding rather than Required. It is useful
 * over an ungrounded run too, where the honest answer is that no draft cites
 * anything and the reviewer should know that before accepting twenty items.
 */
final class CheckCitationCoverage extends DeterministicTask implements ProducesFindingsOnly, RequiresGrounding
{
    public const KEY = 'check.citation_coverage';

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 40;
    }

    public function placements(): array
    {
        return ['workbench_question_bank', 'workbench_lesson_editor'];
    }

    public function groundingMode(): AiGroundingMode
    {
        return AiGroundingMode::Optional;
    }

    public function citationUnit(): string
    {
        return 'chunk';
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $grounding = $request->groundingKeys;
        $findings = [];

        foreach (TaskInputs::drafts($request) as $index => $draft) {
            $questionId = CheckSchemaValidity::syntheticId($draft, $index);
            $citations = [];

            if (\is_array($draft['citations'] ?? null)) {
                foreach ($draft['citations'] as $citation) {
                    if (\is_string($citation)) {
                        $citations[] = $citation;
                    }
                }
            }

            if ($citations === []) {
                $findings[] = PublicationFinding::warning(
                    'ai.check.citation_coverage.uncited',
                    'ai.finding.draft_cites_nothing',
                    [],
                    $questionId,
                );

                continue;
            }

            foreach ($citations as $citation) {
                if ($grounding !== [] && !\in_array($citation, $grounding, true)) {
                    $findings[] = PublicationFinding::blocks(
                        'ai.check.citation_coverage.unknown_source',
                        'ai.finding.citation_names_a_source_that_was_never_sent',
                        ['citation' => $citation],
                        $questionId,
                    );
                }
            }
        }

        return AiTaskResult::create([], $findings);
    }
}
