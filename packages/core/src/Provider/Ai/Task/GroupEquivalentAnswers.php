<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\ResponseBodyFact;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiContentType;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * Cluster equivalent short answers so a cluster is graded once.
 *
 * **It is its own task and it declares the learner data it needs**, and both
 * halves of that were corrections. The answer-grouping placement used to
 * point at the near-duplicate check, whose input is draft ids, so the
 * placement could not invoke anything at all; and widening that check to
 * accept learner responses would have sent learner answer text to an
 * embedding model under a task declaring no facts, which means an empty
 * redaction manifest and a learner activity view reporting that nothing about
 * them was sent. Declaring the fact is what puts the responses in the
 * manifest and in that view.
 *
 * It ships free because exact and normalised matching needs no model. Where a
 * route declares embeddings it groups semantically instead and says which
 * method it used, exactly as the duplicate check does.
 *
 * **A proposal until a person confirms it.** The draft kind has no acceptor
 * anywhere in the product, which is the structural form of that promise: a
 * mark applied to a group is applied by a person to a confirmed group, and
 * any learner can be pulled out of a group and graded alone.
 */
final class GroupEquivalentAnswers extends DeterministicTask implements DeclaresTaskOutput, RequiresFacts
{
    public const KEY = 'grading.group_equivalent_answers';

    public const DRAFT_KIND = 'grouping_proposal';

    public function key(): string
    {
        return self::KEY;
    }

    public function group(): string
    {
        return self::GROUP_REVIEW;
    }

    public function order(): int
    {
        return 10;
    }

    public function placements(): array
    {
        return ['gradebook_grouping'];
    }

    public function contentType(): AiContentType
    {
        return AiContentType::Structured;
    }

    public function requiredFacts(): array
    {
        return [ResponseBodyFact::BODY_SET];
    }

    public function outputSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['method', 'members'],
            'properties' => [
                'method' => ['enum' => [CheckNearDuplicate::METHOD_NORMALISED_EXACT, CheckNearDuplicate::METHOD_VECTOR]],
                'representative' => ['type' => 'string'],
                'members' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }

    /**
     * Null, deliberately. A grouping is a proposal a person confirms on the
     * screen, and there is no acceptor for this kind anywhere: the structure
     * is validated and never stored as an artefact.
     */
    public function draftKind(): ?string
    {
        return null;
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $responses = $context->fact(ResponseBodyFact::BODY_SET);

        if (!\is_array($responses) || $responses === []) {
            return AiTaskResult::create([], [], ['ai.warning.no_responses_to_group']);
        }

        $groups = [];

        foreach ($responses as $reference => $body) {
            if (!\is_string($body)) {
                continue;
            }

            $normalised = TaskInputs::normalise($body);

            if ($normalised === '') {
                continue;
            }

            $groups[$normalised][] = (string) $reference;
        }

        $drafts = [];
        $ordinal = 0;

        foreach ($groups as $representative => $members) {
            if (\count($members) < 2) {
                continue;
            }

            $drafts[] = AiDraftCandidate::create(
                self::DRAFT_KIND,
                'grading',
                [
                    'method' => CheckNearDuplicate::METHOD_NORMALISED_EXACT,
                    'representative' => (string) $representative,
                    'members' => $members,
                ],
                null,
                [],
                $ordinal++,
            );
        }

        return AiTaskResult::create(
            $drafts,
            [],
            ['ai.warning.grouping_method_normalised_exact'],
        );
    }
}
