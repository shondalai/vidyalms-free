<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Fact;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;

/**
 * The four item statistics, computed from the marks the engine already
 * recorded.
 *
 * They resolve to **nobody**, and the cardinality says so, which is what
 * keeps them out of the run's subject list: a facility index is a property of
 * an item and not a fact about any of the people who answered it. It is
 * derived from their answers, and the derivation is an aggregate that names
 * none of them, which is exactly the distinction the subject write exists to
 * make.
 *
 * All four are computed here rather than read from a cache, because a stale
 * discrimination index handed to a rewrite task is worse than a slow one: the
 * task would be asked to fix a distractor that has already been fixed.
 *
 * One class with four factories rather than four classes, because what
 * differs between them is one arithmetic step over the same rows and four
 * copies of the same query is how one of them drifts.
 */
final class ItemStatisticFact implements AiFactProviderInterface
{
    public const FACILITY = 'item.facility';
    public const DISCRIMINATION = 'item.discrimination';
    public const OPTION_FREQUENCIES = 'item.option_frequencies';
    public const NON_RESPONSE = 'item.non_response';

    /** The subject type that names an item. */
    public const SUBJECT_QUESTION = 'question';

    /** Below this many marked attempts, an index is noise and answers null. */
    public const MINIMUM_SAMPLE = 5;

    private function __construct(private readonly string $key)
    {
    }

    public static function facility(): self
    {
        return new self(self::FACILITY);
    }

    public static function discrimination(): self
    {
        return new self(self::DISCRIMINATION);
    }

    public static function optionFrequencies(): self
    {
        return new self(self::OPTION_FREQUENCIES);
    }

    public static function nonResponse(): self
    {
        return new self(self::NON_RESPONSE);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::facility(), self::discrimination(), self::optionFrequencies(), self::nonResponse()];
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.fact.' . str_replace('.', '_', $this->key) . '.label';
    }

    /**
     * None. An aggregate over many people is a fact about the item, and
     * writing one subject row per person who ever answered it would put a
     * cohort into the run record of a task that never saw a name.
     */
    public function resolvesToUsers(): FactCardinality
    {
        return FactCardinality::None;
    }

    public function resolve(AiFactContext $context): mixed
    {
        if ($context->subjectType !== self::SUBJECT_QUESTION || $context->subjectId === null) {
            return null;
        }

        $rows = $context->database->selectAll(
            Query::from('attempt_grades')
                ->select(['attempt_id', 'points_awarded', 'max_points', 'answered_status'])
                ->where('question_id', '=', $context->subjectId),
        );

        if (\count($rows) < self::MINIMUM_SAMPLE) {
            // Null and never zero. An item nobody has answered enough times
            // is an item with no statistics, and a zero facility index would
            // read as an item everybody got wrong.
            return null;
        }

        return match ($this->key) {
            self::FACILITY => $this->facilityIndex($rows),
            self::DISCRIMINATION => $this->discriminationIndex($rows),
            self::OPTION_FREQUENCIES => $this->frequencies($context->database, $context->subjectId),
            default => $this->nonResponseRate($rows),
        };
    }

    /**
     * The proportion of the available marks the cohort earned, which is the
     * classic p value generalised to partial credit.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function facilityIndex(array $rows): float
    {
        $awarded = 0.0;
        $available = 0.0;

        foreach ($rows as $row) {
            $awarded += \is_numeric($row['points_awarded'] ?? null) ? (float) $row['points_awarded'] : 0.0;
            $available += \is_numeric($row['max_points'] ?? null) ? (float) $row['max_points'] : 0.0;
        }

        return $available > 0.0 ? round($awarded / $available, 4) : 0.0;
    }

    /**
     * The upper and lower group difference, over the top and bottom thirds by
     * score on this item.
     *
     * It is the discrimination index a paper-and-pencil item analysis uses,
     * chosen over a point-biserial correlation because it needs only this
     * item's own rows: a correlation against the whole test would need the
     * total score of every attempt, which is a second query answering a
     * question a drafting task does not ask.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function discriminationIndex(array $rows): float
    {
        $ratios = [];

        foreach ($rows as $row) {
            $max = \is_numeric($row['max_points'] ?? null) ? (float) $row['max_points'] : 0.0;

            if ($max <= 0.0) {
                continue;
            }

            $awarded = \is_numeric($row['points_awarded'] ?? null) ? (float) $row['points_awarded'] : 0.0;
            $ratios[] = $awarded / $max;
        }

        if (\count($ratios) < self::MINIMUM_SAMPLE) {
            return 0.0;
        }

        sort($ratios);
        $third = max(1, (int) floor(\count($ratios) / 3));
        $lower = \array_slice($ratios, 0, $third);
        $upper = \array_slice($ratios, -$third);

        return round((array_sum($upper) / \count($upper)) - (array_sum($lower) / \count($lower)), 4);
    }

    /**
     * How often each recorded answer was given, which is what tells a
     * distractor-repair task which distractor nobody chose.
     *
     * @return array<string, int>
     */
    private function frequencies(\Shondalai\VidyaLMS\Core\Port\Database\DatabasePort $database, int $questionId): array
    {
        $rows = $database->selectAll(
            Query::from('attempt_answers')
                ->select(['answer'])
                ->where('question_id', '=', $questionId),
        );

        $counts = [];

        foreach ($rows as $row) {
            $answer = $row['answer'] ?? null;

            if (!\is_string($answer) || $answer === '') {
                continue;
            }

            $counts[$answer] = ($counts[$answer] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function nonResponseRate(array $rows): float
    {
        $unanswered = 0;

        foreach ($rows as $row) {
            if (($row['answered_status'] ?? '') !== 'answered') {
                ++$unanswered;
            }
        }

        return round($unanswered / \count($rows), 4);
    }
}
