<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assessment;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Assessment\ReportableQuizGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Provider\Aggregation\HighestAggregationMethod;
use Shondalai\VidyaLMS\Core\Provider\Aggregation\LatestAggregationMethod;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AttemptScore;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

/**
 * The reportable grade for a whole quiz, and the composition order that
 * decides it: attempts_counted filters the set, attempt_aggregation reduces
 * what is left.
 */
final class ReportableQuizGradeTest extends TestCase
{
    public function testHighestIsTheDefault(): void
    {
        self::assertSame(80.0, $this->grade(new QuizPolicy()));
    }

    public function testLatestReportsTheMostRecentAttempt(): void
    {
        self::assertSame(50.0, $this->grade(new QuizPolicy(attemptAggregation: 'latest')));
    }

    public function testCountingTheFirstAttemptMakesTheAggregatorANoOp(): void
    {
        // "counted: first" with "aggregation: highest" is the first attempt,
        // not the highest of all, and the order is what makes it so.
        self::assertSame(
            30.0,
            $this->grade(new QuizPolicy(attemptsCounted: QuizPolicy::COUNTED_FIRST)),
        );
    }

    public function testCountingTheLastAttemptIgnoresAHigherEarlierOne(): void
    {
        self::assertSame(
            50.0,
            $this->grade(new QuizPolicy(attemptsCounted: QuizPolicy::COUNTED_LAST)),
        );
    }

    public function testAnUnresolvableMethodFallsBackToHighestRatherThanReportingNothing(): void
    {
        self::assertSame(80.0, $this->grade(new QuizPolicy(attemptAggregation: 'average_best_n')));
    }

    public function testAnAttemptStillWaitingOnAHumanIsNotAveragedInAsAZero(): void
    {
        $grade = $this->reportable()->of(
            [
                new AttemptScore(1, 1, 6.0, 10.0, 60.0, false),
                new AttemptScore(2, 2, null, null, null, null),
            ],
            new QuizPolicy(),
            50,
        );

        self::assertNotNull($grade);
        self::assertSame(60.0, $grade->percentage);
        self::assertSame(1, $grade->sourceAttemptId);
    }

    public function testNoClosedAttemptMeansNoGrade(): void
    {
        self::assertNull($this->reportable()->of([], new QuizPolicy(), 50));
    }

    public function testAHighestTieGoesToTheEarlierAttempt(): void
    {
        $grade = $this->reportable()->of(
            [
                new AttemptScore(7, 1, 8.0, 10.0, 80.0, true),
                new AttemptScore(8, 2, 8.0, 10.0, 80.0, true),
            ],
            new QuizPolicy(),
            50,
        );

        self::assertNotNull($grade);
        self::assertSame(7, $grade->sourceAttemptId);
    }

    private function grade(QuizPolicy $policy): ?float
    {
        return $this->reportable()->percentageOf($this->attempts(), $policy, 50);
    }

    /**
     * @return list<AttemptScore>
     */
    private function attempts(): array
    {
        return [
            new AttemptScore(1, 1, 3.0, 10.0, 30.0, false),
            new AttemptScore(2, 2, 8.0, 10.0, 80.0, true),
            new AttemptScore(3, 3, 5.0, 10.0, 50.0, true),
        ];
    }

    private function reportable(): ReportableQuizGrade
    {
        $translator = new StubTranslator();
        $methods = new AggregationMethodRegistry();
        $methods->register(new HighestAggregationMethod($translator));
        $methods->register(new LatestAggregationMethod($translator));

        return new ReportableQuizGrade($methods);
    }
}
