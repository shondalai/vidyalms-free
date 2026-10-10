<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Task;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * Is this harder to read than the audience it was written for.
 *
 * The measure is a classic sentence-length and syllable-count grade estimate,
 * computed here rather than fetched, so it costs nothing and runs on every
 * site. Its limits are stated on the finding rather than hidden: it is a
 * proxy that counts structure and cannot read meaning, so a dense passage of
 * short words scores well and a clear passage of long technical terms scores
 * badly.
 *
 * That is precisely why it warns and never blocks. A subject whose vocabulary
 * is long is not a subject written badly, and a check that refused one would
 * be refusing chemistry.
 */
final class CheckReadability extends DeterministicTask implements ProducesFindingsOnly
{
    public const KEY = 'check.readability';

    /** The default target, in United States school grade levels. */
    public const DEFAULT_TARGET_GRADE = 10.0;

    /** How far above the target a passage may sit before it is worth saying. */
    public const TOLERANCE = 2.0;

    public function key(): string
    {
        return self::KEY;
    }

    public function order(): int
    {
        return 50;
    }

    public function placements(): array
    {
        return ['workbench_lesson_editor', 'workbench_question_bank'];
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $target = TaskInputs::number($request, 'target_grade', self::DEFAULT_TARGET_GRADE);
        $findings = [];

        foreach (TaskInputs::drafts($request) as $index => $draft) {
            $questionId = CheckSchemaValidity::syntheticId($draft, $index);
            $text = implode(' ', TaskInputs::leaves($draft['payload'] ?? $draft['definition'] ?? null));
            $grade = self::gradeLevel($text);

            if ($grade === null) {
                continue;
            }

            if ($grade > $target + self::TOLERANCE) {
                $findings[] = PublicationFinding::warning(
                    'ai.check.readability.above_target',
                    'ai.finding.reading_level_above_target',
                    ['grade' => round($grade, 1), 'target' => $target],
                    $questionId,
                );
            }
        }

        return AiTaskResult::create([], $findings);
    }

    /**
     * The grade estimate, or null when there is not enough text to measure.
     *
     * Null rather than zero, for the reason every unknown in this design is
     * null rather than zero: a passage too short to score has not scored
     * well.
     */
    public static function gradeLevel(string $text): ?float
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
        $sentences = max(1, preg_match_all('/[.!?]+/u', $text));

        if (\count($words) < 10) {
            return null;
        }

        $syllables = 0;

        foreach ($words as $word) {
            $syllables += self::syllables($word);
        }

        $wordsPerSentence = \count($words) / $sentences;
        $syllablesPerWord = $syllables / \count($words);

        return 0.39 * $wordsPerSentence + 11.8 * $syllablesPerWord - 15.59;
    }

    /**
     * A vowel-group count, which is the approximation every implementation of
     * this family uses and which nobody should mistake for a dictionary.
     */
    private static function syllables(string $word): int
    {
        $clean = preg_replace('/[^a-z]/', '', mb_strtolower($word)) ?? '';

        if ($clean === '') {
            return 0;
        }

        $groups = preg_match_all('/[aeiouy]+/', $clean);
        $count = \is_int($groups) ? $groups : 0;

        if (str_ends_with($clean, 'e') && $count > 1) {
            --$count;
        }

        return max(1, $count);
    }
}
