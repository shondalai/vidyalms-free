<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\BankSiblingDigestsFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\BankSiblingVectorsFact;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * Has this item already been asked.
 *
 * **The facts are the correction that made this check able to do its job.**
 * It declared none, and the published default for an absent RequiresFacts is
 * that the task is handed no learner or item data at all, so it received a
 * draft payload and nothing to compare it against: structurally unable to see
 * the bank it exists to check. It now declares the sibling digests for the
 * free normalised-exact path and the sibling vectors for the embedding-backed
 * one, so the candidate set is a declared, bounded, manifest-visible fact
 * supplied by a fact provider rather than a database handle smuggled in.
 *
 * **Two thresholds and both are site-configurable.** The exam-security
 * research puts near-duplicate assessment items at 0.65 cosine and prose at
 * 0.85, and shows independent generation runs converging above the first in
 * narrowly defined domains, which is exactly what a single-course bank looks
 * like. Both live in provider settings and arrive as authored options.
 *
 * On a free site with no embeddings the fallback is normalised exact
 * matching, which catches the common case honestly **and says so on the
 * finding**, which is the part that matters: a duplicate check that quietly
 * degraded would report a clean bank it never compared.
 */
final class CheckNearDuplicate extends DeterministicTask implements ProducesFindingsOnly, RequiresFacts
{
    public const KEY = 'check.near_duplicate';

    /** The published default for assessment items. */
    public const DEFAULT_ITEM_THRESHOLD = 0.65;

    /** The published default for prose. */
    public const DEFAULT_PROSE_THRESHOLD = 0.85;

    public const METHOD_NORMALISED_EXACT = 'normalised_exact';
    public const METHOD_VECTOR = 'vector';

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 30;
    }

    public function placements(): array
    {
        return ['workbench_question_bank'];
    }

    public function requiredFacts(): array
    {
        return [BankSiblingDigestsFact::KEY, BankSiblingVectorsFact::KEY];
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $siblings = $context->fact(BankSiblingDigestsFact::KEY);
        $vectors = $context->fact(BankSiblingVectorsFact::KEY);
        $method = \is_array($vectors) && $vectors !== [] ? self::METHOD_VECTOR : self::METHOD_NORMALISED_EXACT;
        $threshold = TaskInputs::number($request, 'item_threshold', self::DEFAULT_ITEM_THRESHOLD);

        $known = [];

        if (\is_array($siblings)) {
            foreach ($siblings as $reference => $digest) {
                if (\is_string($digest)) {
                    $known[$digest] = (string) $reference;
                }
            }
        }

        $findings = [];
        $warnings = [$method === self::METHOD_VECTOR
            ? 'ai.warning.duplicate_method_vector'
            : 'ai.warning.duplicate_method_normalised_exact'];

        foreach (TaskInputs::drafts($request) as $index => $draft) {
            $questionId = CheckSchemaValidity::syntheticId($draft, $index);
            $definition = \is_array($draft['definition'] ?? null) ? $draft['definition'] : [];
            $stem = \is_string($definition['prompt'] ?? null) ? $definition['prompt'] : '';

            if ($stem === '') {
                continue;
            }

            $digest = TaskInputs::digest($stem);

            if (isset($known[$digest])) {
                $findings[] = PublicationFinding::acknowledge(
                    'ai.check.near_duplicate.match',
                    'ai.finding.item_already_in_the_bank',
                    [
                        'sibling' => $known[$digest],
                        'method' => $method,
                        'threshold' => $threshold,
                    ],
                    $questionId,
                );

                continue;
            }

            $known[$digest] = 'draft:' . abs($questionId);
        }

        return AiTaskResult::create([], $findings, $warnings);
    }
}
