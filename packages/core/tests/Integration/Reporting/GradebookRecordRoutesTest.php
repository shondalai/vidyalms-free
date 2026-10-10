<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The gradebook's four routes through the real routed kernel, and every
 * refusal.
 *
 * Routed rather than direct on the use cases, because three of the properties
 * are properties of the routing and the middleware around it: that the three
 * reads do not shadow the manual grading queue on the path beside them, that
 * the cursor and the limit arrive as the strings a query string is always made
 * of, and that the regrade's required idempotency key is enforced before the
 * body is read.
 */
final class GradebookRecordRoutesTest extends TestCase
{
    private const MANAGER = 1;
    private const TEACHER = 10;
    private const LEARNER = 50;
    private const OTHER = 51;

    private TestPorts $env;
    private ApiKernel $api;
    private int $courseId = 0;
    private int $quizItemId = 0;
    private int $totalItemId = 0;
    private int $cellId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER, Capability::GRADE);
        $this->env->authorization->grant(self::TEACHER, Capability::GRADE);
        $this->env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');
        $this->env->users->add(self::TEACHER, 'Tara Teacher', 'tara@example.test');
        $this->env->users->add(self::LEARNER, 'Lena Learner', 'lena@example.test');
        $this->env->users->add(self::OTHER, 'Otto Other', 'otto@example.test');

        $kernel = new CoreKernel($this->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->api = $kernel->apiKernel();
        $this->seed();
    }

    public function testTheItemsReadNamesTheAggregationProviderAndWhetherTheSiteHasIt(): void
    {
        $response = $this->call('GET', '/manage/courses/' . $this->courseId . '/gradebook/items');

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertSame($this->courseId, $response->data['course_id']);
        self::assertCount(2, $response->data['items']);

        $total = $response->data['course_total'];
        self::assertIsArray($total);
        self::assertSame('course_total', $total['item_type']);
        self::assertSame('natural', $total['aggregation_key']);
        self::assertTrue($total['aggregation_available']);
        self::assertIsString($total['aggregation_label']);
        // A category is an item of its own type, so the tree is one table.
        self::assertNull($total['parent_item_id']);
    }

    /**
     * The published miss direction, applied rather than guessed: a guessed
     * total is a grade dispute and an absent one is a support ticket.
     */
    public function testAnItemNamingAnUnregisteredProviderSaysSoRatherThanGuessing(): void
    {
        // A key no build registers. It was `weighted-mean` while that was a
        // strategy the design had named and nobody had written; the free build
        // ships it now, so the stand-in has to be a key that is genuinely
        // nobody's or this test asserts the miss direction against a provider
        // that answers.
        $this->env->database->update(
            'grade_items',
            ['aggregation_key' => 'x-acme-uninstalled-strategy'],
            ['id' => $this->totalItemId],
        );

        $response = $this->call('GET', '/manage/courses/' . $this->courseId . '/gradebook/items');

        self::assertIsArray($response->data);
        self::assertFalse($response->data['course_total']['aggregation_available']);
        self::assertNull($response->data['course_total']['aggregation_label']);
        self::assertSame('x-acme-uninstalled-strategy', $response->data['course_total']['aggregation_key']);
    }

    public function testACellIsSelfDescribingAndCarriesBothNamedTotals(): void
    {
        $response = $this->call('GET', '/manage/courses/' . $this->courseId . '/gradebook/grades');

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertCount(1, $response->data['cells']);

        $cell = $response->data['cells'][0];
        // The snapshot is the point: an instructor may change the item's
        // maximum afterwards and the row must still be readable.
        self::assertSame('10.00000', $cell['raw_grade_max']);
        self::assertSame('8.00000', $cell['raw_grade']);
        self::assertSame('FullyGraded', $cell['grading_progress']);
        self::assertSame('fully graded', $cell['score_status']);

        $totals = $response->data['totals'];
        self::assertCount(1, $totals);
        self::assertSame(self::LEARNER, $totals[0]['user_id']);
        self::assertArrayHasKey('current', $totals[0]);
        self::assertArrayHasKey('final', $totals[0]);
        self::assertSame('natural', $totals[0]['aggregation_key']);
    }

    public function testTheCellsPageByKeysetWithoutRepeatingOrSkippingARow(): void
    {
        $this->cellFor(self::OTHER, 5.0);
        $this->cellFor(self::LEARNER, 6.0, $this->itemNamed('second-quiz'));

        $first = $this->call(
            'GET',
            '/manage/courses/' . $this->courseId . '/gradebook/grades',
            query: ['limit' => '1'],
        );

        self::assertIsArray($first->data);
        self::assertCount(1, $first->data['cells']);
        self::assertIsString($first->data['next_cursor']);

        $second = $this->call(
            'GET',
            '/manage/courses/' . $this->courseId . '/gradebook/grades',
            query: ['limit' => '2', 'cursor' => $first->data['next_cursor']],
        );

        self::assertIsArray($second->data);
        $ids = array_map(
            static fn (array $cell): int => (int) $cell['id'],
            [...$first->data['cells'], ...$second->data['cells']],
        );
        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertCount(3, $ids);
    }

    public function testAGradeCrossesTheContractAsADecimalStringAndNeverAsAFloat(): void
    {
        $response = $this->call('GET', '/manage/courses/' . $this->courseId . '/gradebook/grades');

        self::assertIsArray($response->data);
        self::assertIsString($response->data['cells'][0]['raw_grade']);
        self::assertIsString($response->data['cells'][0]['raw_grade_max']);
    }

    public function testARegradeWritesOneChangeRowCarryingBothMaximaAndTheReason(): void
    {
        $response = $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', [
            'raw_grade' => '9.5',
            'reason' => 'Marked the second page after all.',
        ]);

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertSame('9.50000', $response->data['cell']['raw_grade']);
        self::assertSame(self::MANAGER, $response->data['cell']['graded_by']);
        self::assertNotNull($response->data['cell']['overridden_at']);

        $change = $response->data['change'];
        self::assertSame('8.00000', $change['grade_before']);
        self::assertSame('9.50000', $change['grade_after']);
        self::assertSame('10.00000', $change['max_before']);
        self::assertSame('10.00000', $change['max_after']);
        self::assertSame(self::MANAGER, $change['actor_id']);
        self::assertSame('Marked the second page after all.', $change['reason']);
        // The key the change was written under, so the audit row and the
        // replay store name the same act.
        self::assertSame('regrade-key', $change['request_ref']);

        self::assertArrayHasKey('current', $response->data['totals']);
        self::assertSame(1, $this->env->database->count(Query::from('grade_changes')));
    }

    public function testAGradeIsClampedToTheItemRatherThanStoredOutOfRange(): void
    {
        $response = $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', [
            'raw_grade' => '99',
            'reason' => 'Typo in the mark sheet.',
        ]);

        self::assertIsArray($response->data);
        self::assertSame('10.00000', $response->data['cell']['raw_grade']);
    }

    public function testAnExplicitNullClearsTheGradeAndIsNotZero(): void
    {
        $response = $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', [
            'raw_grade' => null,
            'reason' => 'Submission withdrawn.',
        ]);

        self::assertIsArray($response->data);
        self::assertNull($response->data['cell']['raw_grade']);
        self::assertSame('novalue', $response->data['cell']['aggregation_status']);
    }

    public function testARegradeWithNoReasonIsRefusedAndWritesNothing(): void
    {
        $response = $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', [
            'raw_grade' => '9',
        ]);

        self::assertSame(422, $response->status);
        self::assertSame(0, $this->env->database->count(Query::from('grade_changes')));
    }

    public function testABodyThatChangesNothingIsRefusedRatherThanASilentNoOp(): void
    {
        $response = $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', [
            'reason' => 'Nothing to say.',
        ]);

        self::assertSame(422, $response->status);
    }

    public function testAScoreStatusOutsideTheOneRosterVocabularyIsRefused(): void
    {
        $response = $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', [
            'score_status' => 'invented',
            'reason' => 'Trying it on.',
        ]);

        self::assertSame(422, $response->status);
    }

    public function testAMissingIdempotencyKeyIsFourHundredAndWritesNothing(): void
    {
        $response = $this->api->handle(new ApiRequest(
            'POST',
            '/manage/gradebook/grades/' . $this->cellId . '/regrade',
            $this->manager(),
            [],
            ['raw_grade' => '9', 'reason' => 'No key.'],
        ));

        self::assertSame(400, $response->status);
        self::assertSame(0, $this->env->database->count(Query::from('grade_changes')));
    }

    public function testAReplayedRegradeWritesOneChangeRow(): void
    {
        $body = ['raw_grade' => '9', 'reason' => 'Second marker agreed.'];

        $first = $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', $body);
        $second = $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', $body);

        self::assertSame(200, $first->status);
        self::assertSame(200, $second->status);
        self::assertSame(1, $this->env->database->count(Query::from('grade_changes')));
    }

    public function testTheChangeLogPagesNewestFirstAndFiltersByLearner(): void
    {
        $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', [
            'raw_grade' => '9',
            'reason' => 'First correction.',
        ], 'one');
        $this->write('/manage/gradebook/grades/' . $this->cellId . '/regrade', [
            'raw_grade' => '9.5',
            'reason' => 'Second correction.',
        ], 'two');

        $response = $this->call('GET', '/manage/courses/' . $this->courseId . '/gradebook/changes');

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertCount(2, $response->data['changes']);
        self::assertSame('Second correction.', $response->data['changes'][0]['reason']);

        $mine = $this->call(
            'GET',
            '/manage/courses/' . $this->courseId . '/gradebook/changes',
            query: ['user_id' => (string) self::OTHER],
        );
        self::assertIsArray($mine->data);
        self::assertCount(0, $mine->data['changes']);
    }

    public function testTheThreeReadsDoNotShadowTheManualGradingQueueBesideThem(): void
    {
        $queue = $this->call('GET', '/manage/courses/' . $this->courseId . '/gradebook');

        self::assertSame(200, $queue->status);
        self::assertIsArray($queue->data);
        self::assertArrayHasKey('pending_total', $queue->data);
        self::assertArrayNotHasKey('course_total', $queue->data);
    }

    public function testAGuestAndACallerWithNoGradeClaimAreRefused(): void
    {
        self::assertSame(401, $this->api->handle(new ApiRequest(
            'GET',
            '/manage/courses/' . $this->courseId . '/gradebook/items',
            Principal::guest(),
        ))->status);

        self::assertSame(403, $this->api->handle(new ApiRequest(
            'GET',
            '/manage/courses/' . $this->courseId . '/gradebook/items',
            Principal::user(self::LEARNER),
        ))->status);
    }

    public function testARegradeOfACellThatIsNotThereIsNotFound(): void
    {
        self::assertSame(404, $this->write('/manage/gradebook/grades/9999/regrade', [
            'raw_grade' => '1',
            'reason' => 'Nothing here.',
        ])->status);
    }

    private function seed(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->courseId = $this->env->database->insert('courses', [
            'title' => 'Gradebook course',
            'slug' => 'gradebook-course',
            'state' => 'published',
            'created_by' => self::MANAGER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->totalItemId = $this->env->database->insert('grade_items', [
            'course_id' => $this->courseId,
            'parent_item_id' => null,
            'item_type' => 'course_total',
            'source_kind' => 'manual',
            'source_id' => 0,
            'title' => 'Course total',
            'grade_max' => 10,
            'grade_min' => 0,
            'aggregation_key' => 'natural',
            'empty_grade_policy' => 'exclude',
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->quizItemId = $this->itemNamed('first-quiz');
        $this->cellId = $this->cellFor(self::LEARNER, 8.0, $this->quizItemId);
    }

    private function itemNamed(string $slug): int
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        return $this->env->database->insert('grade_items', [
            'course_id' => $this->courseId,
            'parent_item_id' => $this->totalItemId,
            'item_type' => 'activity',
            'source_kind' => 'quiz',
            'source_id' => crc32($slug) % 1000,
            'title' => $slug,
            'grade_max' => 10,
            'grade_min' => 0,
            'aggregation_key' => 'natural',
            'empty_grade_policy' => 'exclude',
            'position' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function cellFor(int $userId, float $grade, ?int $itemId = null): int
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        return $this->env->database->insert('grade_cells', [
            'grade_item_id' => $itemId ?? $this->quizItemId,
            'user_id' => $userId,
            'course_id' => $this->courseId,
            'raw_grade' => $grade,
            'raw_grade_max' => 10,
            'raw_grade_min' => 0,
            'final_grade' => $grade,
            'activity_progress' => 'Completed',
            'grading_progress' => 'FullyGraded',
            'score_status' => 'fully graded',
            'aggregation_status' => 'used',
            'aggregation_weight_bp' => 10000,
            'graded_at' => $now,
            'scored_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function write(string $path, array $body, string $key = 'regrade-key'): ApiResponse
    {
        return $this->api->handle(new ApiRequest('POST', $path, $this->manager(), [], $body, $key));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function call(string $method, string $path, array $query = []): ApiResponse
    {
        return $this->api->handle(new ApiRequest($method, $path, $this->manager(), $query));
    }

    private function manager(): Principal
    {
        return Principal::user(self::MANAGER);
    }
}
