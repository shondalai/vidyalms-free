<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\QuizSetting\QuizSettingContext;
use Shondalai\VidyaLMS\Core\Registry\QuizSetting\QuizSettingGroupInterface;

/**
 * A package's own quiz settings group, which is the whole point of the
 * seam: it declares a field the core has never heard of and the form
 * renders it.
 */
final class ProctoringSettingGroup implements QuizSettingGroupInterface
{
    /** @var list<int> */
    private array $quizIds = [];

    /** @var list<string> */
    private array $aggregations = [];

    public function __construct(private readonly string $groupKey = 'proctoring')
    {
    }

    public function key(): string
    {
        return $this->groupKey;
    }

    public function describe(QuizSettingContext $context): array
    {
        $this->quizIds[] = $context->quizId;
        $this->aggregations[] = $context->policy->attemptAggregation;

        return [
            'key' => $this->groupKey,
            'label' => 'Proctoring',
            'fields' => [[
                'key' => 'lockdown',
                'label' => 'Lockdown browser',
                'type' => 'boolean',
                'default' => false,
                'current' => false,
            ]],
        ];
    }

    /**
     * @return list<int>
     */
    public function quizIds(): array
    {
        return $this->quizIds;
    }

    /**
     * @return list<string>
     */
    public function aggregations(): array
    {
        return $this->aggregations;
    }
}
