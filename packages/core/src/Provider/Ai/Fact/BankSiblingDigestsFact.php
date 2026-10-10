<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Fact;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\TaskInputs;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The normalised digests of the items a new item would sit beside.
 *
 * **This fact is why the duplicate check can do its job at all.** The check
 * declared no facts, and the published default for an absent declaration is
 * that a task is handed nothing, so it received a draft and no bank: it was
 * structurally unable to see the thing it exists to compare against. The
 * candidate set arrives here instead, as a declared, bounded, manifest-visible
 * fact rather than a database handle inside the task.
 *
 * It sends digests and never stems. A duplicate comparison over hashes needs
 * no text, so the free path puts nothing quotable on the wire and the
 * manifest says exactly that.
 *
 * It resolves to nobody: an item is not a person, and a bank of them is not a
 * cohort.
 */
final class BankSiblingDigestsFact implements AiFactProviderInterface
{
    public const KEY = 'bank.sibling_digests';

    /** The subject type a quiz-scoped drafting run carries. */
    public const SUBJECT_QUIZ = 'quiz';

    /** How many siblings one comparison reads, so a large bank is bounded. */
    public const LIMIT = 1000;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.fact.bank_sibling_digests.label';
    }

    public function resolvesToUsers(): FactCardinality
    {
        return FactCardinality::None;
    }

    /**
     * @return array<string, string>
     */
    public function resolve(AiFactContext $context): mixed
    {
        $quizId = $this->quizId($context);

        if ($quizId === null) {
            return [];
        }

        $rows = $context->database->selectAll(
            Query::from('questions')
                ->select(['id', 'definition'])
                ->where('quiz_id', '=', $quizId)
                ->orderBy('position', Query::SORT_ASC)
                ->limit(self::LIMIT),
        );

        $digests = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $prompt = $this->prompt($row['definition'] ?? null);

            if ($prompt === '' || (!\is_int($id) && !\is_numeric($id))) {
                continue;
            }

            $digests['question:' . (int) $id] = TaskInputs::digest($prompt);
        }

        return $digests;
    }

    private function quizId(AiFactContext $context): ?int
    {
        if ($context->subjectType === self::SUBJECT_QUIZ && $context->subjectId !== null) {
            return $context->subjectId;
        }

        if ($context->subjectType !== ItemStatisticFact::SUBJECT_QUESTION || $context->subjectId === null) {
            return null;
        }

        $row = $context->database->selectOne(
            Query::from('questions')
                ->select(['quiz_id'])
                ->where('id', '=', $context->subjectId)
                ->limit(1),
        );

        $quizId = $row['quiz_id'] ?? null;

        return \is_int($quizId) || \is_numeric($quizId) ? (int) $quizId : null;
    }

    private function prompt(mixed $stored): string
    {
        $definition = [];

        if (\is_array($stored)) {
            /** @var array<string, mixed> $stored */
            $definition = StoredShape::bodyOf($stored);
        } elseif (\is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);

            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $definition = StoredShape::bodyOf($decoded);
            }
        }

        $prompt = $definition['prompt'] ?? null;

        return \is_string($prompt) ? $prompt : '';
    }
}
