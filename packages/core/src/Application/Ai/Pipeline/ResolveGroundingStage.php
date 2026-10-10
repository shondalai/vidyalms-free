<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Application\Ai\AiGroundingIndexer;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiGroundingMode;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresGrounding;

/**
 * The grounding set the request named, resolved onto the payload.
 *
 * It exists because grounding was write-only. `AiGroundingIndexer` wrote
 * `ai_grounding_chunks` and nothing in the whole product read the table:
 * `AssembleFactsStage` built the payload with an always-empty chunk list and
 * `RedactStage` fell back to the same empty list, so `RequiresGrounding` was a
 * declaration nothing served, a run's manifest could never name a grounding
 * source, and the strict redactor's chunk loop, which is the branch that
 * applies the trust marking and downgrades a claimed `trusted_instruction`,
 * was dead on both entry paths.
 *
 * Three rules, and each is the design's own.
 *
 * **A chunk is fetched by its key and never by its text.** The request carries
 * chunk keys, which are content-addressed and stable across a re-index, so a
 * citation stored on a draft in March still resolves in June.
 *
 * **Every chunk arrives untrusted.** The trust column is what the redactor
 * reads, and the indexer writes exactly one value into it. A chunk claiming
 * to be a trusted instruction is content that says so, and the redactor
 * downgrades it; this stage does not second-guess the column either way.
 *
 * **A task declaring RequiresGrounding::Required with an empty resolved set
 * refuses.** A required grounding set that resolved to nothing is a run that
 * would have been answered from the model's own memory, which is the thing
 * grounding exists to prevent, and it refuses rather than proceeding
 * ungrounded. Optional runs either way and the manifest records which.
 */
final class ResolveGroundingStage implements AiRunStageInterface
{
    /** How many chunks one run may carry, so a large course is bounded. */
    public const CHUNK_LIMIT = 200;

    private const CHUNKS = 'ai_grounding_chunks';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function name(): string
    {
        return 'ResolveGrounding';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        $task = $run->task;
        $mode = $task instanceof RequiresGrounding ? $task->groundingMode() : AiGroundingMode::None;
        $keys = $run->request->task->groundingKeys;

        if ($mode === AiGroundingMode::None && $keys === []) {
            return;
        }

        $chunks = $keys === [] ? [] : $this->chunksFor($keys, $run->request->courseId);
        if ($keys !== [] && count($chunks) !== count(array_unique($keys))) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.grounding.unresolved', ['task' => $run->request->taskKey]);
            return;
        }

        if ($chunks === [] && $mode === AiGroundingMode::Required) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.grounding.unresolved', [
                'task' => $run->request->taskKey,
                'requested' => \count($keys),
            ]);

            return;
        }

        if ($chunks === []) {
            return;
        }

        $payload = $run->payload;
        $run->payload = AiTaskPayload::create(
            $run->request->task,
            $chunks,
            $payload === null ? $run->facts : $payload->facts,
            $payload === null ? $run->request->task->options : $payload->options,
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return list<array{provenance: string, trust: string, text: string}>
     */
    private function chunksFor(array $keys, ?int $courseId): array
    {
        if ($courseId === null || $courseId < 1 || count($keys) > self::CHUNK_LIMIT) {
            return [];
        }
        $rows = $this->database->selectAll(
            Query::from(self::CHUNKS)
                ->select(['chunk_key', 'subject_type', 'subject_id', 'ordinal', 'text', 'trust'])
                ->whereIn('chunk_key', array_values(array_unique($keys)))
                ->where('course_id', '=', $courseId)
                ->orderBy('subject_id', Query::SORT_ASC)
                ->orderBy('ordinal', Query::SORT_ASC)
                ->limit(self::CHUNK_LIMIT),
        );

        $chunks = [];

        foreach ($rows as $row) {
            $text = $row['text'] ?? null;

            if (!\is_string($text) || trim($text) === '') {
                continue;
            }

            $chunks[] = [
                // The provenance label a person is shown, and the entry the
                // manifest carries: the subject the text came from, so a
                // reviewer following a citation lands on a lesson rather than
                // on a hash.
                'provenance' => (string) ($row['subject_type'] ?? AiGroundingIndexer::SUBJECT_LESSON)
                    . ':' . (int) ($row['subject_id'] ?? 0),
                'trust' => \is_string($row['trust'] ?? null) ? $row['trust'] : AiGroundingIndexer::TRUST_UNTRUSTED,
                'text' => $text,
            ];
        }

        return $chunks;
    }
}
