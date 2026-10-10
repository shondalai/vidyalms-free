<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * One stored rehearsal of one rule at one version.
 *
 * An initial subject state, a changed subject state, and expectations, run
 * inside a rolled-back transaction. It is the cheapest defence against the
 * class of bug where a rule works in the builder and enrols four hundred
 * people on a live site.
 *
 * **The expectations are written in the grammar that already exists**, because
 * inventing one here would be the second rule language the design spends a
 * section refusing. An expectation is an availability condition tree on the
 * Automation surface, plus the small closed set of engine assertions the tree
 * cannot express: a step executed, a step suppressed with a named reason, and
 * a subject count matched. An earlier draft described "assertions over which
 * steps executed and what values they produced" and published no grammar at
 * all, which is a comparison language invented by omission.
 *
 * rule_version is real because a test written against version 2 must not
 * silently claim to cover version 3, and last_result is real because the rules
 * screen shows a failing-test badge beside a rule and a JSON scan cannot drive
 * a list badge.
 */
final class StoredRuleTest
{
    public const RESULT_PASSED = 'passed';
    public const RESULT_FAILED = 'failed';
    public const RESULT_ERRORED = 'errored';

    /** The three engine assertions the condition tree cannot express. */
    public const ASSERT_STEP_EXECUTED = 'step_executed';
    public const ASSERT_STEP_SUPPRESSED = 'step_suppressed';
    public const ASSERT_SUBJECT_COUNT = 'subject_count';

    /**
     * @param array<string, mixed> $given
     * @param array<string, mixed> $changed
     * @param array<string, mixed> $expectations
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $ruleId,
        public readonly int $ruleVersion,
        public readonly string $name,
        public readonly array $given,
        public readonly array $changed,
        public readonly array $expectations,
        public readonly int $createdBy,
        public readonly ?\DateTimeImmutable $lastRunAt = null,
        public readonly ?string $lastResult = null,
        public readonly ?string $lastDetail = null,
        public readonly int $givenVersion = 1,
        public readonly int $changedVersion = 1,
        public readonly int $expectationsVersion = 1,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function engineAssertions(): array
    {
        return [self::ASSERT_STEP_EXECUTED, self::ASSERT_STEP_SUPPRESSED, self::ASSERT_SUBJECT_COUNT];
    }
}
