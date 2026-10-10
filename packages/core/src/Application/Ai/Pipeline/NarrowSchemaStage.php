<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Application\Ai\AiSchemaNarrower;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskOutput;

/**
 * Stage 5. Turns the task's declared output schema into the decoding guidance a
 * route can actually enforce, and records every constraint it could not.
 *
 * The narrowed schema is guidance and NOT the gate. It may be weaker than the
 * type's own schema and must never be stronger: the narrower refuses only a
 * transformation that could reject a document the type's own schema accepts.
 * Dropping a constraint keyword is a widening by definition, and an earlier rule
 * that refused widenings resolved to "refuse every route" for every shipped
 * question type, because no provider strict mode supports the whole keyword set
 * the free types use.
 *
 * The authoritative gate is still the question type's own DefinitionSchemaCheck,
 * run inside AddQuestion when a draft is accepted. This stage's job is to say
 * what the route did not enforce, so the run can flag schema_unenforced naming
 * exactly which constraints, which is what the item review screen shows.
 *
 * On the free build every route is the null bridge, which enforces no keywords
 * at all, so this stage runs on every free run and its unenforced list is the
 * honest full set rather than an empty one nobody exercised.
 */
final class NarrowSchemaStage implements AiRunStageInterface
{
    /**
     * @param list<string> $supportedKeywords what the route's bridge declares it
     *                                        can constrain, which is empty for
     *                                        the null bridge
     */
    public function __construct(
        private readonly AiSchemaNarrower $narrower,
        private readonly array $supportedKeywords = [],
    ) {
    }

    public function name(): string
    {
        return 'NarrowSchema';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        $task = $run->task;

        if (!$task instanceof DeclaresTaskOutput) {
            // Absent means the result is display only and can never become a
            // draft row, so there is nothing to narrow and nothing to validate.
            return;
        }

        $narrowed = $this->narrower->narrow($task->outputSchema(), $this->supportedKeywords);

        $run->outputSchema = $narrowed->schema;
        $run->droppedConstraints = $narrowed->droppedConstraints;
        $run->schemaUnenforced = !$narrowed->isFullyEnforced();

        if ($run->droppedConstraints === []) {
            return;
        }

        $run->note(
            \Shondalai\VidyaLMS\Core\Application\Platform\ImportNote::INFO,
            'The route did not enforce ' . implode(', ', $run->droppedConstraints)
                . ', so the output was validated after the fact rather than during decoding.',
        );
    }
}
