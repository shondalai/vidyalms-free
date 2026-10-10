<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Notification\ConditionsStillHold;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Domain\Automation\StoredRuleTest;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleTestRepository;

/**
 * One stored rehearsal, executed and scored.
 *
 * The run is a dry run, so it writes nothing but its own rows, and every
 * expectation is written in the grammar that already exists: an availability
 * condition tree on the Automation surface, plus the three engine assertions
 * the tree cannot express, because inventing a comparison language here would
 * be the second rule language this design spends a section refusing.
 *
 * The three engine assertions are exactly step_executed, step_suppressed with
 * a named reason, and subject_count. An earlier draft described "assertions
 * over which steps executed and what values they produced" and published no
 * grammar at all, which is a comparison language invented by omission.
 *
 * **Two deviations from the design are recorded here rather than left to be
 * discovered.** The execution is a dry run rather than the design's
 * rolled-back transaction, which is a stronger guarantee for the actions that
 * declare SupportsDryRun and a weaker one for a third-party action that
 * declares it and lies; and neither `given` nor `changed` seeds any row,
 * because the free engine has no fixture path and writing one from a rehearsal
 * is exactly what a rehearsal must not do. Both states are read for the
 * subjects they name, which is the whole of what the engine can honour today.
 */
final class RunStoredRuleTest
{
    public function __construct(
        private readonly SqlAutomationRuleTestRepository $tests,
        private readonly SqlAutomationRuleRepository $rules,
        private readonly PreviewAutomationRule $preview,
        private readonly AccessGuard $access,
        /**
         * The other half of the expectation grammar.
         *
         * The design's sentence is "an availability condition tree on the
         * Automation surface, plus the small closed set of engine assertions
         * the tree cannot express", and only the second half was scored: the
         * `conditions` half of every stored test was written, stored, versioned
         * and never evaluated, so a test could assert nothing at all about the
         * state the run left behind and still pass.
         */
        private readonly ?ConditionsStillHold $conditions = null,
        private readonly ?ClockPort $clock = null,
    ) {
    }

    /**
     * @return array{result: string, detail: string, failures: list<string>}
     */
    public function execute(Principal $actor, int $testId): array
    {
        $this->access->require($actor, Capability::MANAGE);
        $test = $this->tests->find($testId);

        if ($test === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRuleTest', $testId);
        }

        $rule = $this->rules->find($test->ruleId);

        if ($rule === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRule', $test->ruleId);
        }

        // A test written against version 2 must not silently claim to cover
        // version 3, which is why rule_version is a real column here.
        if ($test->ruleVersion !== $rule->version) {
            return $this->finish($testId, StoredRuleTest::RESULT_ERRORED, ['automation.test_version_mismatch']);
        }

        $subjects = $this->subjectsOf($test);

        if ($subjects === []) {
            return $this->finish($testId, StoredRuleTest::RESULT_ERRORED, ['automation.test_names_no_subject']);
        }

        $report = $this->preview->execute($actor, $test->ruleId, $subjects, 'user');
        $failures = $this->score($test, $report);

        foreach ($this->scoreConditions($test, $rule->courseId, $subjects) as $failure) {
            $failures[] = $failure;
        }

        return $this->finish(
            $testId,
            $failures === [] ? StoredRuleTest::RESULT_PASSED : StoredRuleTest::RESULT_FAILED,
            $failures,
        );
    }

    /**
     * The subjects the rehearsal runs for: those the test was given, plus
     * those its changed state adds.
     *
     * `changed` had no reader at all, so the second half of "an initial
     * subject state and a changed subject state" was stored, versioned and
     * ignored: a test that named a subject only in its changed state
     * rehearsed nobody and errored with `test_names_no_subject`.
     *
     * @return list<int>
     */
    private function subjectsOf(StoredRuleTest $test): array
    {
        $subjects = [];

        foreach ([$test->given, $test->changed] as $state) {
            foreach (self::subjectsIn($state) as $subjectId) {
                if (!\in_array($subjectId, $subjects, true)) {
                    $subjects[] = $subjectId;
                }
            }
        }

        return $subjects;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return list<int>
     */
    private static function subjectsIn(array $state): array
    {
        $declared = $state['subjects'] ?? null;
        $subjects = [];

        if (!\is_array($declared)) {
            return $subjects;
        }

        foreach ($declared as $entry) {
            if (\is_int($entry)) {
                $subjects[] = $entry;
            } elseif (\is_array($entry) && \is_int($entry['user_id'] ?? null)) {
                $subjects[] = (int) $entry['user_id'];
            }
        }

        return $subjects;
    }

    /**
     * The condition half of the expectation grammar, evaluated against the
     * post-run state on the Automation surface.
     *
     * One evaluation per subject, because an expectation written in the
     * availability grammar is a statement about a person and a tree that held
     * for one of five subjects is a test that failed.
     *
     * @param list<int> $subjects
     *
     * @return list<string>
     */
    private function scoreConditions(StoredRuleTest $test, ?int $courseId, array $subjects): array
    {
        $declared = $test->expectations['conditions'] ?? null;

        if (!\is_array($declared) || $declared === []) {
            return [];
        }

        if ($this->conditions === null || $this->clock === null) {
            return ['automation.test_conditions_unevaluable'];
        }

        $tree = AvailabilityTreeCodec::decode($declared);

        if ($tree->isEmpty()) {
            return ['automation.test_conditions_unreadable'];
        }

        $failures = [];

        foreach ($subjects as $subjectId) {
            $context = AvailabilityContext::create(
                Principal::user($subjectId),
                AvailabilitySurface::Automation,
                $this->clock->now(),
            );

            if (!$this->conditions->hold($tree, $context, $courseId)) {
                $failures[] = 'automation.test_conditions_do_not_hold:' . $subjectId;
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<string>
     */
    private function score(StoredRuleTest $test, array $report): array
    {
        $failures = [];
        $expectations = $test->expectations['engine'] ?? [];

        if (!\is_array($expectations)) {
            return ['automation.test_expectations_unreadable'];
        }

        $executed = [];
        $suppressed = [];
        $subjects = \is_array($report['subjects'] ?? null) ? $report['subjects'] : [];

        foreach ($subjects as $subject) {
            foreach ((\is_array($subject['steps'] ?? null) ? $subject['steps'] : []) as $step) {
                $index = (int) ($step['step_index'] ?? 0);
                $disposition = (string) ($step['disposition'] ?? '');

                if ($disposition === 'executed') {
                    $executed[] = $index;
                }

                if ($disposition === 'suppressed') {
                    $suppressed[] = $index;
                }
            }
        }

        foreach ($expectations as $expectation) {
            if (!\is_array($expectation)) {
                $failures[] = 'automation.test_expectation_unreadable';

                continue;
            }

            // "kind" and "count", which is what AutomationExpectations
            // publishes and what AutomationRuleTesting::refuseUnknownAssertions
            // enforces at save. This reader spelled them "assert" and "value",
            // so a test written to the contract saved with a 200 and then
            // scored every one of its engine assertions as unknown. Proved on
            // the first live run of POST .../tests/{id}/run against a real
            // site: a test naming subject_count answered
            // automation.unknown_engine_assertion with an empty kind.
            $assertion = (string) ($expectation['kind'] ?? '');

            if (!\in_array($assertion, StoredRuleTest::engineAssertions(), true)) {
                $failures[] = 'automation.unknown_engine_assertion:' . $assertion;

                continue;
            }

            if ($assertion === StoredRuleTest::ASSERT_SUBJECT_COUNT) {
                $expected = (int) ($expectation['count'] ?? -1);

                if ((int) ($report['subject_count'] ?? 0) !== $expected) {
                    $failures[] = 'automation.subject_count_mismatch';
                }

                continue;
            }

            $index = (int) ($expectation['step_index'] ?? 0);
            $pool = $assertion === StoredRuleTest::ASSERT_STEP_EXECUTED ? $executed : $suppressed;

            if (!\in_array($index, $pool, true)) {
                $failures[] = $assertion . ':' . $index;
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $failures
     *
     * @return array{result: string, detail: string, failures: list<string>}
     */
    private function finish(int $testId, string $result, array $failures): array
    {
        $detail = $failures === [] ? 'ok' : implode('; ', $failures);
        $this->tests->recordResult($testId, $result, $detail);

        return ['result' => $result, 'detail' => $detail, 'failures' => $failures];
    }
}
