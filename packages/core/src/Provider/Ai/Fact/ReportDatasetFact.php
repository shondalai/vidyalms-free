<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Fact;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;

/**
 * The numbers a report already computed, handed to a narrative task as a
 * fact.
 *
 * It resolves to **nobody**, and that is a guardrail rather than an accident
 * of shape: the narrative surface must never become an at-risk learner list,
 * so the fact that feeds it carries aggregates and never rows about people.
 * A dataset that could name an individual would make no causal claim safe and
 * would put a profile into a run record.
 *
 * The numerals in a narrative are checked against this dataset before the
 * narrative is shown, which is the other half of the same rule: a figure the
 * report did not produce does not reach a stakeholder.
 */
final class ReportDatasetFact implements AiFactProviderInterface
{
    public const KEY = 'report.dataset';

    /** The subject type a report run carries. */
    public const SUBJECT_REPORT_RUN = 'report_run';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.fact.report_dataset.label';
    }

    /** Aggregates about a cohort are not facts about its members. */
    public function resolvesToUsers(): FactCardinality
    {
        return FactCardinality::None;
    }

    public function resolve(AiFactContext $context): mixed
    {
        if ($context->subjectType !== self::SUBJECT_REPORT_RUN || $context->subjectId === null) {
            return null;
        }

        $row = $context->database->selectOne(
            Query::from('report_runs')
                ->select(['report_key', 'parameters', 'row_count', 'finished_at'])
                ->where('id', '=', $context->subjectId)
                ->limit(1),
        );

        if ($row === null || ($row['finished_at'] ?? null) === null) {
            // A report still running has no dataset, and a narrative over a
            // partial one would describe a snapshot nobody can reproduce.
            return null;
        }

        return [
            'report_key' => $row['report_key'] ?? null,
            'parameters' => $row['parameters'] ?? null,
            'row_count' => $row['row_count'] ?? null,
        ];
    }
}
