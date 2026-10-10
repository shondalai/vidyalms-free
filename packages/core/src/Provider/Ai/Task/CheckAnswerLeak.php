<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * Does this text give the answer away.
 *
 * It accepts drafts **and an inline candidate string**, because the one place
 * a leak matters most produces no draft row at all: a learner-facing
 * explanation is rendered straight back to the person who just answered, so
 * if this check could only read stored drafts it would never look at the one
 * output nothing else in the pipeline inspects.
 *
 * The comparison is over the secret values a caller passes in, which is the
 * only shape that works from here: the task holds no database and no question
 * type registry could tell it what the answer to a specific item is without
 * one. The caller resolves the item's own secret keys and hands the values
 * down as options, which keeps this a pure function and keeps the secrets in
 * one place.
 *
 * **A leak blocks.** This is the one check in the family that does, and the
 * reason is that its finding is not a heuristic: the key is either present in
 * the text or it is not.
 */
final class CheckAnswerLeak extends DeterministicTask implements ProducesFindingsOnly
{
    public const KEY = 'check.answer_leak';

    /** The option a learner-facing caller passes its unstored candidate under. */
    public const OPTION_CANDIDATE = 'candidate';

    /** The option the resolved secret values arrive under. */
    public const OPTION_SECRETS = 'secrets';

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 60;
    }

    public function placements(): array
    {
        return ['workbench_question_bank', 'player_explain_mistake'];
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $secrets = [];

        foreach (TaskInputs::strings($request, self::OPTION_SECRETS) as $secret) {
            $normalised = TaskInputs::normalise($secret);

            // A one-word or two-letter answer would match almost any prose,
            // so a secret too short to be evidence is not treated as one. The
            // limitation is stated rather than silently applied: this check
            // cannot protect a true or false item, and nothing about a true
            // or false item needs it to.
            if (mb_strlen($normalised) >= 4) {
                $secrets[$normalised] = true;
            }
        }

        if ($secrets === []) {
            return AiTaskResult::create([], [], ['ai.warning.answer_leak_had_nothing_to_compare']);
        }

        $findings = [];
        $candidate = TaskInputs::text($request, self::OPTION_CANDIDATE);

        if ($candidate !== '') {
            foreach ($this->leaks($candidate, $secrets) as $secret) {
                $findings[] = PublicationFinding::blocks(
                    'ai.check.answer_leak.in_candidate',
                    'ai.finding.text_contains_the_answer',
                    ['secret' => $secret],
                );
            }
        }

        foreach (TaskInputs::drafts($request) as $index => $draft) {
            $questionId = CheckSchemaValidity::syntheticId($draft, $index);
            $text = implode(' ', TaskInputs::leaves($draft['payload'] ?? $draft['definition'] ?? null));

            foreach ($this->leaks($text, $secrets) as $secret) {
                $findings[] = PublicationFinding::blocks(
                    'ai.check.answer_leak.in_draft',
                    'ai.finding.text_contains_the_answer',
                    ['secret' => $secret],
                    $questionId,
                );
            }
        }

        return AiTaskResult::create([], $findings);
    }

    /**
     * @param array<string, true> $secrets
     *
     * @return list<string>
     */
    private function leaks(string $text, array $secrets): array
    {
        $haystack = TaskInputs::normalise($text);
        $found = [];

        foreach (array_keys($secrets) as $secret) {
            if (str_contains($haystack, $secret)) {
                $found[] = $secret;
            }
        }

        return $found;
    }
}
