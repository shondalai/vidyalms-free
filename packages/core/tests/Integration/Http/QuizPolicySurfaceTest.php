<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportProviderInterface;

/**
 * The registry-driven settings form, the quiz policy on the wire, and the
 * item analysis placeholder: the three routes whose whole point is that a
 * free site answers them from what it has rather than failing.
 */
final class QuizPolicySurfaceTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testTheSettingsSchemaIsBuiltFromTheRegistries(): void
    {
        $response = $this->schema();

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['quiz_id', 'groups'], array_keys($response->data));

        $groups = array_map(static fn (array $group): string => $group['key'], $response->data['groups']);
        self::assertSame(
            ['behaviour', 'presentation', 'availability', 'grading', 'item_session'],
            $groups,
        );

        $behaviour = $this->field($response->data, 'behaviour', 'behaviour');
        self::assertSame('enum', $behaviour['type']);
        self::assertSame(
            ['deferred_feedback', 'manual'],
            array_map(static fn (array $option): string => $option['key'], $behaviour['options']),
            'A free site offers exactly the behaviours it registered.',
        );
        self::assertTrue($behaviour['available']);
    }

    public function testTheAggregationFieldNamesWhereItsParametersLive(): void
    {
        $field = $this->field($this->schema()->data ?? [], 'grading', 'attempt_aggregation');

        self::assertSame('attempt_aggregation_params', $field['params_key']);
        self::assertSame(
            ['highest', 'latest'],
            array_map(static fn (array $option): string => $option['key'], $field['options']),
        );
    }

    public function testAFreeSiteOffersNoNegativeMarkingRuleAndSaysSoWithAnOption(): void
    {
        $field = $this->field($this->schema()->data ?? [], 'grading', 'negative_marking_policy');

        self::assertSame(
            [''],
            array_map(static fn (array $option): string => $option['key'], $field['options']),
            'The seam exists; this site simply has no rule in it.',
        );
        self::assertNull($field['current']);
    }

    public function testEveryFieldCarriesTheContractShape(): void
    {
        $data = $this->schema()->data ?? [];

        foreach ($data['groups'] as $group) {
            self::assertSame(['key', 'label', 'description', 'fields'], array_keys($group));

            foreach ($group['fields'] as $field) {
                self::assertSame([
                    'key',
                    'label',
                    'description',
                    'type',
                    'default',
                    'current',
                    'minimum',
                    'maximum',
                    'options',
                    'fields',
                    'params_key',
                    'available',
                    'unavailable_reason',
                ], array_keys($field));
            }
        }
    }

    public function testTheGracePeriodReportsItsFloorRatherThanOnlyItsDefault(): void
    {
        $field = $this->field($this->schema()->data ?? [], 'availability', 'grace_seconds');

        self::assertSame('duration', $field['type']);
        self::assertSame(60.0, $field['minimum']);
    }

    public function testAStoredKeyNoInstalledPackageProvidesIsReportedRatherThanReverted(): void
    {
        // The quiz was set under a site that had the behaviour; the package
        // has since gone. Nothing reverts, and the author is told.
        $this->fixture->env->database->update(
            'quizzes',
            ['behaviour' => 'certainty_based'],
            ['id' => $this->fixture->quizId],
        );

        $field = $this->field($this->schema()->data ?? [], 'behaviour', 'behaviour');

        self::assertFalse($field['available']);
        self::assertSame('certainty_based', $field['current']);
        self::assertIsString($field['unavailable_reason']);
    }

    public function testTheQuizUpdateRefusesASettingNoRegisteredProviderUnderstands(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/quizzes/' . $this->fixture->quizId,
            $this->fixture->teacher(),
            ['title' => 'Checkpoint', 'policy' => ['behaviour' => 'certainty_based']],
        );

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('behaviour', $response->data['errors']);
    }

    public function testTheQuizUpdateWritesTheWholePolicySetBack(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/quizzes/' . $this->fixture->quizId,
            $this->fixture->teacher(),
            [
                'title' => 'Checkpoint',
                'policy' => [
                    'navigation_mode' => 'linear',
                    'shuffle_options' => true,
                    'grace_seconds' => 90,
                    'closes_at' => '2030-01-01 09:00:00',
                    'attempt_aggregation' => 'latest',
                    'item_session_control' => ['max_attempts' => 2, 'allow_skipping' => false],
                ],
            ],
        );

        self::assertSame(200, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);

        $policy = $response->data['policy'];
        self::assertSame('linear', $policy['navigation_mode']);
        self::assertTrue($policy['shuffle_options']);
        self::assertSame(90, $policy['grace_seconds']);
        self::assertSame('2030-01-01 09:00:00', $policy['closes_at']);
        self::assertSame('latest', $policy['attempt_aggregation']);
        self::assertSame(2, $policy['item_session_control']['max_attempts']);
        self::assertFalse($policy['item_session_control']['allow_skipping']);
    }

    /**
     * Two settings that can disagree are normalised on save rather than
     * stored contradicting each other.
     */
    public function testRedundantCombinationsAreNormalisedRatherThanStored(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/quizzes/' . $this->fixture->quizId,
            $this->fixture->teacher(),
            [
                'title' => 'Checkpoint',
                'policy' => [
                    // Counting one attempt makes the aggregator a no-op by
                    // construction, so the neutral default is what is stored.
                    'attempts_counted' => 'last',
                    'attempt_aggregation' => 'latest',
                    // Simultaneous submission gives one try per item by
                    // definition, so a higher count is a promise the mode
                    // cannot keep.
                    'submission_mode' => 'simultaneous',
                    'item_session_control' => ['max_attempts' => 3],
                ],
            ],
        );

        self::assertSame(200, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);
        self::assertSame('last', $response->data['policy']['attempts_counted']);
        self::assertSame('highest', $response->data['policy']['attempt_aggregation']);
        self::assertSame(1, $response->data['policy']['item_session_control']['max_attempts']);
    }

    public function testAGracePeriodBelowTheClockSkewFloorIsRefused(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/quizzes/' . $this->fixture->quizId,
            $this->fixture->teacher(),
            ['title' => 'Checkpoint', 'policy' => ['grace_seconds' => 5]],
        );

        self::assertSame(422, $response->status);
    }

    public function testItemAnalysisAnswersAPlaceholderRatherThanAnErrorWithNoProvider(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/reports/item-analysis',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertFalse($response->data['computed']);
        self::assertNull($response->data['computed_by']);
        self::assertIsString($response->data['reason']);
        self::assertSame([], $response->data['items']);
        self::assertSame(30, $response->data['min_responses']);
    }

    public function testItemAnalysisIsWhateverTheRegisteredProviderComputes(): void
    {
        $this->fixture->kernel->registries->reports->register(
            new class () implements ReportProviderInterface {
                public function key(): string
                {
                    return 'item_analysis';
                }

                public function label(): string
                {
                    return 'Item analysis';
                }

                public function requiredCapability(): string
                {
                    return Capability::VIEW_REPORTS;
                }

                public function generate(ReportContext $context): array
                {
                    $parameters = $context->parameters;

                    return [
                        'items' => [[
                            'question_id' => 1,
                            'question_version_id' => null,
                            'policy_key' => 'match_correct',
                            'n' => (int) ($parameters['min_responses'] ?? 0),
                            'facility' => 62.5,
                        ]],
                        'total' => 1,
                    ];
                }
            },
        );

        $response = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/reports/item-analysis',
            $this->fixture->teacher(),
            [],
            ['min_responses' => '40'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertTrue($response->data['computed']);
        self::assertSame('item_analysis', $response->data['computed_by']);
        self::assertSame(40, $response->data['min_responses']);
        self::assertSame(40, $response->data['items'][0]['n'], 'The threshold reaches the provider.');
        self::assertSame(62.5, $response->data['items'][0]['facility']);
    }

    /**
     * A second registered report used to have no way to be read at all: the
     * only synchronous route hardcoded the item analysis key, so adding a
     * report meant a core route, a core handler and a contract operation.
     */
    public function testASecondRegisteredReportIsReadableWithoutACoreRoute(): void
    {
        $this->fixture->kernel->registries->reports->register(
            new class () implements ReportProviderInterface, \Shondalai\VidyaLMS\Core\Registry\Report\CourseScopedReport {
                public function key(): string
                {
                    return 'x_acme_time_on_task';
                }

                public function label(): string
                {
                    return 'Time on task';
                }

                public function requiredCapability(): string
                {
                    return Capability::VIEW_REPORTS;
                }

                public function generate(ReportContext $context): array
                {
                    return ['items' => [['seconds' => 90]], 'total' => 1];
                }
            },
        );

        $response = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/reports/x_acme_time_on_task',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertTrue($response->data['computed']);
        self::assertSame('x_acme_time_on_task', $response->data['computed_by']);
        self::assertSame(90, $response->data['items'][0]['seconds']);
    }

    /**
     * The registry miss contract applied to a report: a key nothing
     * registers answers the placeholder rather than 404, because a route
     * that failed without a paid package would be a gate by another name.
     */
    public function testAnUnregisteredReportKeyAnswersThePlaceholder(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/reports/x_acme_absent',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertFalse($response->data['computed']);
        self::assertNull($response->data['computed_by']);
        self::assertIsString($response->data['reason']);
        self::assertSame([], $response->data['items']);
    }

    public function testTheSettingsSchemaIsRefusedToAGuestAndToAnotherCoursesTeacher(): void
    {
        $path = '/manage/quizzes/' . $this->fixture->quizId . '/settings-schema';

        self::assertSame(401, $this->fixture->call('GET', $path, Principal::guest())->status);
        self::assertSame(403, $this->fixture->call('GET', $path, $this->fixture->outsider())->status);
        self::assertSame(404, $this->fixture->call(
            'GET',
            '/manage/quizzes/9999/settings-schema',
            $this->fixture->teacher(),
        )->status);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function field(array $schema, string $group, string $key): array
    {
        foreach ($schema['groups'] ?? [] as $candidate) {
            if ($candidate['key'] !== $group) {
                continue;
            }

            foreach ($candidate['fields'] as $field) {
                if ($field['key'] === $key) {
                    return $field;
                }
            }
        }

        self::fail(sprintf('The settings schema has no field "%s" in group "%s".', $key, $group));
    }

    private function schema(): \Shondalai\VidyaLMS\Core\Http\ApiResponse
    {
        return $this->fixture->call(
            'GET',
            '/manage/quizzes/' . $this->fixture->quizId . '/settings-schema',
            $this->fixture->teacher(),
        );
    }
}
