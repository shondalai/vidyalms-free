<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Availability\CompletionCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\ScoreCondition;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The one save-time pass, over the route that stores a content gate.
 */
final class AvailabilityRuleSaveTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testASubjectWithNoRuleAnswersANullTreeRatherThanNotFound(): void
    {
        $response = $this->get();

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertNull($response->data['tree']);
        self::assertSame('shown', $response->data['default_visibility']);
    }

    public function testAValidTreeIsStoredAndReadBack(): void
    {
        $stored = $this->put($this->node('and', [$this->dateLeaf('2026-03-03T09:00:00+00:00')]));

        self::assertSame(200, $stored->status);
        self::assertNotNull($stored->data);
        self::assertIsArray($stored->data['tree']);
        self::assertSame('and', $stored->data['tree']['op']);

        $read = $this->get();
        self::assertNotNull($read->data);
        self::assertIsArray($read->data['tree']);
        self::assertSame(DateCondition::KEY, $read->data['tree']['c'][0]['type']);
    }

    /**
     * The single most reported structural fault in the model this grammar is
     * taken from, refused by name.
     */
    public function testAChildAndVisibilityLengthMismatchIsRejected(): void
    {
        $response = $this->put([
            'op' => 'and',
            'showc' => [true],
            'c' => [
                $this->dateLeaf('2026-03-03T09:00:00+00:00'),
                $this->dateLeaf('2026-03-04T09:00:00+00:00'),
            ],
        ]);

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('errors', $response->data);
    }

    public function testALeafUsedOutsideItsDeclaredApplicabilityIsRejected(): void
    {
        // The completion condition declares no enrolment-gating surface, so
        // the same tree that is valid on the content gate is refused there.
        $response = $this->fixture->call(
            'PUT',
            '/manage/courses/' . $this->fixture->courseId . '/availability',
            $this->fixture->teacher(),
            ['tree' => $this->node('and', [$this->completionLeaf($this->fixture->lessonId, 'complete')])],
            ['surface' => 'enrolment_gating'],
        );

        self::assertSame(422, $response->status);
    }

    public function testACycleAcrossTheCourseIsRejectedNamingBothEnds(): void
    {
        $lessonId = $this->fixture->lessonId;
        $quizLessonId = $this->fixture->quizLessonId;

        $first = $this->fixture->call(
            'PUT',
            '/manage/lessons/' . $lessonId . '/availability',
            $this->fixture->teacher(),
            ['tree' => $this->node('and', [$this->completionLeaf($quizLessonId, 'complete')])],
        );
        self::assertSame(200, $first->status);

        $second = $this->fixture->call(
            'PUT',
            '/manage/lessons/' . $quizLessonId . '/availability',
            $this->fixture->teacher(),
            ['tree' => $this->node('and', [$this->completionLeaf($lessonId, 'complete')])],
        );

        self::assertSame(422, $second->status);
        self::assertNotNull($second->data);
        self::assertIsArray($second->data['errors']);
        self::assertStringContainsString('circular', (string) $second->data['errors']['tree']);
    }

    /**
     * A pass state against a target with no pass mark can never be
     * satisfied, so the picker hides it and this is the check: a rule
     * arriving through the API would otherwise sit in the denominator
     * forever.
     */
    public function testAPassStateAgainstATargetWithNoPassMarkIsRejected(): void
    {
        $response = $this->put($this->node('and', [
            $this->completionLeaf($this->fixture->lessonId, CompletionCondition::STATE_COMPLETE_PASS),
        ]));

        self::assertSame(422, $response->status);
    }

    /**
     * A warning and not a refusal, because a course total can exceed a
     * hundred with bonus points.
     */
    public function testAScoreMaximumAtTheAttainableTotalWarnsRatherThanRefuses(): void
    {
        $response = $this->put($this->node('and', [
            [
                'type' => ScoreCondition::KEY,
                'payload' => StoredShape::wrap([
                    'target' => ['kind' => 'quiz', 'id' => $this->fixture->quizId],
                    'min' => 10,
                    'max' => 100,
                ], 1),
            ],
        ]));

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertIsArray($response->data['warnings']);
        self::assertSame('availability.score.max_above_attainable', $response->data['warnings'][0]['code']);
    }

    public function testAnImpossibleRangeIsRejected(): void
    {
        $response = $this->put($this->node('and', [
            [
                'type' => DateCondition::KEY,
                'payload' => StoredShape::wrap([
                    'from' => '2026-03-10T09:00:00+00:00',
                    'until' => '2026-03-01T09:00:00+00:00',
                ], 1),
            ],
        ]));

        self::assertSame(422, $response->status);
    }

    public function testSavingWritesOneLeafReferenceRowPerNamedEntity(): void
    {
        $this->fixture->call(
            'PUT',
            '/manage/lessons/' . $this->fixture->assignmentLessonId . '/availability',
            $this->fixture->teacher(),
            ['tree' => $this->node('and', [$this->completionLeaf($this->fixture->lessonId, 'complete')])],
        );

        $rows = $this->fixture->env->database->selectAll(
            Query::from('availability_rule_refs')
                ->where('ref_type', '=', 'lesson')
                ->where('ref_id', '=', $this->fixture->lessonId),
        );

        self::assertCount(1, $rows);
        self::assertSame('availability_rule', $rows[0]['owner_type']);
        self::assertSame('c.0', $rows[0]['leaf_path']);
    }

    public function testDeletingClearsTheRuleAndItsReferences(): void
    {
        $this->put($this->node('and', [$this->completionLeaf($this->fixture->lessonId, 'complete')]));

        $deleted = $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->quizLessonId . '/availability',
            $this->fixture->teacher(),
        );

        self::assertSame(204, $deleted->status);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('availability_rules')));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('availability_rule_refs')));
    }

    public function testAGuestIsUnauthorizedAndAnOutsiderIsForbidden(): void
    {
        $guest = $this->fixture->call(
            'GET',
            '/manage/lessons/' . $this->fixture->lessonId . '/availability',
            \Shondalai\VidyaLMS\Core\Application\Shared\Principal::guest(),
        );
        self::assertSame(401, $guest->status);

        $outsider = $this->fixture->call(
            'GET',
            '/manage/lessons/' . $this->fixture->lessonId . '/availability',
            $this->fixture->outsider(),
        );
        self::assertSame(403, $outsider->status);
    }

    public function testAnUnknownSubjectTypeIsNotFound(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/badgers/1/availability',
            $this->fixture->teacher(),
        );

        self::assertSame(404, $response->status);
    }

    private function get(): \Shondalai\VidyaLMS\Core\Http\ApiResponse
    {
        return $this->fixture->call(
            'GET',
            '/manage/lessons/' . $this->fixture->quizLessonId . '/availability',
            $this->fixture->teacher(),
        );
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function put(array $tree): \Shondalai\VidyaLMS\Core\Http\ApiResponse
    {
        return $this->fixture->call(
            'PUT',
            '/manage/lessons/' . $this->fixture->quizLessonId . '/availability',
            $this->fixture->teacher(),
            ['tree' => $tree],
        );
    }

    /**
     * @param list<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function node(string $op, array $children): array
    {
        return ['op' => $op, 'c' => $children];
    }

    /**
     * @return array<string, mixed>
     */
    private function dateLeaf(string $from): array
    {
        return [
            'type' => DateCondition::KEY,
            'payload' => StoredShape::wrap(['from' => $from, 'until' => null, 'tz' => 'UTC'], 1),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function completionLeaf(int $lessonId, string $state): array
    {
        return [
            'type' => CompletionCondition::KEY,
            'payload' => StoredShape::wrap([
                'target' => ['kind' => 'lesson', 'id' => $lessonId],
                'state' => $state,
            ], 1),
        ];
    }
}
