<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\AssessmentCatalogue;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The successful shape of every management route, field by field against the
 * schema openapi.yaml declares for it. A field the contract does not declare
 * is as much a failure here as a missing one: the SDK is generated from that
 * document, and an undeclared field is one no client will ever read.
 */
final class ManagementShapeTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testLessonTypesAreTheRegisteredProviders(): void
    {
        $response = $this->fixture->call('GET', '/lesson-types', $this->fixture->teacher());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertIsArray($response->data['items']);
        self::assertCount(5, $response->data['items']);
        self::assertSame(['key', 'label', 'completion_mode'], array_keys($response->data['items'][0]));
        self::assertSame('reading', $response->data['items'][0]['key']);
    }

    /**
     * The order this asserts changed, and the old one was the defect.
     *
     * It asserted registration order, which the design says is not the
     * picker's order and must not be treated as one: replace() keeps a
     * displaced key's position, so decorating the free six would pin them at
     * positions one to six and split the selection family across the list.
     * The free build already registers fill_blank before short_answer while
     * every table in the design lists them the other way round, which is the
     * contract being broken before any decoration exists.
     *
     * Presentation order is now declared through group and order, so the
     * three selection types come first and the two text-entry types follow
     * in the order the design states.
     */
    public function testQuestionTypesAreListedByTheirDeclaredGroupAndOrder(): void
    {
        $response = $this->fixture->call('GET', '/question-types', $this->fixture->teacher());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['items', 'presets'], array_keys($response->data));
        self::assertCount(6, $response->data['items']);
        self::assertSame(
            ['key', 'label', 'group', 'order', 'response_shape', 'offerable_policies', 'policy_field', 'authoring'],
            array_keys($response->data['items'][0]),
        );
        self::assertSame(
            ['ordering', 'single_choice', 'multiple_choice', 'true_false', 'short_answer', 'fill_blank'],
            array_map(static fn (array $type): string => $type['key'], $response->data['items']),
        );
        self::assertSame('association', $response->data['items'][0]['group']);
        self::assertSame('selection', $response->data['items'][1]['group']);

        $authoring = $response->data['items'][0]['authoring'];
        self::assertSame(
            [
                'definition_version',
                'supported_definition_versions',
                'definition_schema',
                'primitive',
                'surface',
                'scoring_policies',
                'accessibility',
            ],
            array_keys($authoring),
        );
        self::assertSame(1, $authoring['definition_version']);
        self::assertSame([1], $authoring['supported_definition_versions']);
        self::assertSame('order', $authoring['primitive']);
        self::assertSame('flow', $authoring['surface']);
        self::assertNotEmpty($authoring['definition_schema']);
        self::assertNotEmpty($authoring['scoring_policies']);
        self::assertSame(
            ['key', 'label', 'default_params', 'params_schema'],
            array_keys($authoring['scoring_policies'][0]),
        );
        self::assertSame(
            [
                'interaction_modes',
                'essential_dragging_justification',
                'conformance_result',
                'verified',
            ],
            array_keys($authoring['accessibility']),
        );
    }

    /**
     * What the author's picker may offer is computed from the registry, so an
     * any-shape decorator is authorable on every type without that type
     * naming it. As an exclusive allowlist, none of Pro's any-shape
     * decorators could be selected on any free type without Pro editing
     * free-build files, which ADR 0002 forbids outright.
     */
    public function testEveryTypeOffersTheAnyShapeDecoratorsWithoutNamingThem(): void
    {
        $response = $this->fixture->call('GET', '/question-types', $this->fixture->teacher());

        self::assertNotNull($response->data);

        foreach ($response->data['items'] as $type) {
            self::assertContains('external', $type['offerable_policies'], sprintf(
                '%s cannot be authored as human marked, although external binds to any shape',
                (string) $type['key'],
            ));
            self::assertContains('none', $type['offerable_policies']);
            self::assertNotContains(
                'kendall_tau',
                (string) $type['key'] === 'ordering' ? [] : $type['offerable_policies'],
                'a shape-bound policy is still narrowed by the shape it binds to',
            );
        }
    }

    public function testQuestionTypeAuthoringContractComesFromTheProviderCatalogues(): void
    {
        $response = $this->fixture->call('GET', '/question-types', $this->fixture->teacher());
        self::assertNotNull($response->data);

        $catalogue = (new AssessmentCatalogue($this->fixture->kernel->registries))->build();
        $types = [];
        foreach ($catalogue['question_types'] as $type) {
            $types[$type['key']] = $type;
        }
        $policies = [];
        foreach ($catalogue['scoring_policies'] as $policy) {
            $policies[$policy['key']] = $policy;
        }

        foreach ($response->data['items'] as $listed) {
            $declared = $types[$listed['key']];
            $authoring = $listed['authoring'];

            self::assertSame($declared['definition_version'], $authoring['definition_version']);
            self::assertSame($declared['supported_definition_versions'], $authoring['supported_definition_versions']);
            self::assertSame($declared['definition_schema'], $authoring['definition_schema']);
            self::assertSame($declared['primitive'] ?? null, $authoring['primitive']);
            self::assertSame($declared['surface'] ?? null, $authoring['surface']);

            self::assertSame(
                $listed['offerable_policies'],
                array_column($authoring['scoring_policies'], 'key'),
            );
            foreach ($authoring['scoring_policies'] as $policy) {
                self::assertSame($policies[$policy['key']]['label'], $policy['label']);
                self::assertSame($policies[$policy['key']]['default_params'], $policy['default_params']);
                self::assertSame($policies[$policy['key']]['params_schema'], $policy['params_schema']);
            }
        }
    }

    public function testQuestionTypesCarryThePresetsThePickerShows(): void
    {
        $response = $this->fixture->call('GET', '/question-types', $this->fixture->teacher());

        self::assertNotNull($response->data);
        self::assertCount(6, $response->data['presets']);
        self::assertSame(
            [
                'preset_key',
                'label',
                'group',
                'base_type_key',
                'default_policy',
                'definition_skeleton',
                'source_aliases',
                'help_url',
            ],
            array_keys($response->data['presets'][0]),
        );
        self::assertSame('single_choice', $response->data['presets'][0]['base_type_key']);
    }

    public function testManagedCourseListCarriesCountsAndPaging(): void
    {
        $response = $this->fixture->call('GET', '/manage/courses', $this->fixture->teacher());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['items', 'total', 'page', 'per_page'], array_keys($response->data));
        self::assertSame(1, $response->data['total'], 'A teacher sees only the course they instruct.');

        $course = $response->data['items'][0];
        self::assertSame(self::managedCourseKeys(), array_keys($course));
        self::assertSame(3, $course['lesson_count']);
        self::assertSame(1, $course['enrolled_count']);
        self::assertSame(1, $course['active_count']);
        self::assertSame(0.0, $course['completion_rate']);
        self::assertSame(
            [['user_id' => ManagementFixture::TEACHER_ID, 'name' => 'Tara Teacher', 'role' => 'lead']],
            $course['instructors'],
        );
    }

    public function testManagedCourseDetailAddsPrerequisitesAndTheGradingBadge(): void
    {
        $response = $this->fixture->call('GET', $this->fixture->path('/manage/courses/{course}'), $this->fixture->teacher());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            [
                ...self::managedCourseKeys(),
                'description',
                'created_by',
                'created_at',
                'prerequisites',
                'pending_grading_count',
                'enrolment_rule',
                'progression',
                'timezone',
                'starts_at',
                'late_policy_key',
                'late_policy_params',
            ],
            array_keys($response->data),
        );
        self::assertSame([], $response->data['prerequisites']);
        self::assertSame(0, $response->data['pending_grading_count']);
        self::assertSame('open', $response->data['enrolment_rule']);
        self::assertSame('free', $response->data['progression']);
        self::assertNull($response->data['timezone']);
        self::assertNull($response->data['starts_at']);
        self::assertSame('none', $response->data['late_policy_key']);
        self::assertNull($response->data['late_policy_params']);
    }

    public function testAuthoringCurriculumCarriesDraftsContentAndPerTypeConfiguration(): void
    {
        $response = $this->fixture->call(
            'GET',
            $this->fixture->path('/manage/courses/{course}/curriculum'),
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['course_id', 'modules'], array_keys($response->data));

        $module = $response->data['modules'][0];
        self::assertSame(['id', 'course_id', 'title', 'summary', 'position', 'state', 'lessons'], array_keys($module));
        self::assertCount(3, $module['lessons']);

        $reading = $module['lessons'][0];
        self::assertSame(self::authoringLessonKeys(), array_keys($reading));
        self::assertSame(['body' => '<p>Steep well.</p>'], $reading['content']);
        self::assertTrue($reading['content_available']);
        self::assertNull($reading['quiz']);
        self::assertNull($reading['assignment']);

        $quizLesson = $module['lessons'][1];
        self::assertIsArray($quizLesson['quiz']);
        self::assertSame(self::quizSummaryKeys(), array_keys($quizLesson['quiz']));
        self::assertSame($this->fixture->quizId, $quizLesson['quiz']['id']);

        $assignmentLesson = $module['lessons'][2];
        self::assertIsArray($assignmentLesson['assignment']);
        self::assertSame(self::assignmentKeys(), array_keys($assignmentLesson['assignment']));
        self::assertSame(100.0, $assignmentLesson['assignment']['max_points']);
    }

    public function testAddingAQuizLessonCreatesAndLinksItsQuiz(): void
    {
        $response = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/modules/{module}/lessons'),
            $this->fixture->teacher(),
            ['title' => 'Second checkpoint', 'type' => 'quiz'],
        );

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertIsArray($response->data['quiz']);
        self::assertSame($response->data['id'], $response->data['quiz']['lesson_id']);
        self::assertSame('Second checkpoint', $response->data['quiz']['title']);
        self::assertSame(0, $response->data['quiz']['question_count']);
        self::assertSame(['quiz_id' => $response->data['quiz']['id']], $response->data['content']);
    }

    public function testAddingAQuizLessonCanLinkAnExistingUnlinkedQuiz(): void
    {
        $teacher = $this->fixture->teacher();
        $quiz = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/courses/{course}/quizzes'),
            $teacher,
            ['title' => 'Spare quiz'],
        );

        self::assertSame(201, $quiz->status);
        self::assertNotNull($quiz->data);
        self::assertNull($quiz->data['lesson_id']);

        $lesson = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/modules/{module}/lessons'),
            $teacher,
            ['title' => 'Uses the spare', 'type' => 'quiz', 'content' => ['quiz_id' => $quiz->data['id']]],
        );

        self::assertSame(201, $lesson->status);
        self::assertNotNull($lesson->data);
        self::assertSame($quiz->data['id'], $lesson->data['quiz']['id']);
        self::assertSame($lesson->data['id'], $lesson->data['quiz']['lesson_id']);
    }

    public function testQuizAuthoringViewCarriesTheQuestionDefinitions(): void
    {
        $response = $this->fixture->call('GET', $this->fixture->path('/manage/quizzes/{quiz}'), $this->fixture->teacher());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame([...self::quizSummaryKeys(), 'questions'], array_keys($response->data));

        $question = $response->data['questions'][0];
        self::assertSame(['id', 'quiz_id', 'type', 'position', 'points', 'definition'], array_keys($question));
        // A new question carries the scoring policy its type defaults to,
        // written at creation. An existing definition that names none is
        // never stamped, because that would silently move the mark of a
        // question authored before the field existed.
        self::assertSame(
            ['policy' => ['key' => 'text_match'], 'prompt' => 'Describe an oolong.', 'accepted' => []],
            $question['definition'],
        );
    }

    public function testQuizzesTabListsTheCourseQuizzes(): void
    {
        $response = $this->fixture->call(
            'GET',
            $this->fixture->path('/manage/courses/{course}/quizzes'),
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertCount(1, $response->data['items']);
        self::assertSame(self::quizSummaryKeys(), array_keys($response->data['items'][0]));
        self::assertSame(1, $response->data['items'][0]['question_count']);
        self::assertIsArray($response->data['items'][0]['lesson']);
        self::assertSame('Basics', $response->data['items'][0]['lesson']['module_title']);
    }

    public function testReorderingQuestionsRewritesPositions(): void
    {
        $teacher = $this->fixture->teacher();
        $second = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/quizzes/{quiz}/questions'),
            $teacher,
            ['type' => 'true_false', 'definition' => ['prompt' => 'Oolong is a tea.', 'correct' => true]],
        );

        self::assertSame(201, $second->status);
        self::assertNotNull($second->data);

        $reordered = $this->fixture->call(
            'PUT',
            $this->fixture->path('/manage/quizzes/{quiz}/questions/order'),
            $teacher,
            ['question_ids' => [$second->data['id'], $this->fixture->questionId]],
        );

        self::assertSame(204, $reordered->status);
        self::assertNull($reordered->data);

        $view = $this->fixture->call('GET', $this->fixture->path('/manage/quizzes/{quiz}'), $teacher);
        self::assertNotNull($view->data);
        self::assertSame(
            [$second->data['id'], $this->fixture->questionId],
            array_map(static fn (array $question): int => $question['id'], $view->data['questions']),
        );
        self::assertSame([1, 2], array_map(static fn (array $question): int => $question['position'], $view->data['questions']));
    }

    public function testInstructorListNamesTheLeadFirst(): void
    {
        $response = $this->fixture->call(
            'GET',
            $this->fixture->path('/manage/courses/{course}/instructors'),
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            [['user_id' => ManagementFixture::TEACHER_ID, 'name' => 'Tara Teacher', 'role' => 'lead']],
            $response->data['items'],
        );
    }

    public function testPrerequisitesAnswerTheStoredList(): void
    {
        $response = $this->fixture->call(
            'PUT',
            $this->fixture->path('/manage/courses/{course}/prerequisites'),
            $this->fixture->manager(),
            ['course_ids' => [$this->fixture->otherCourseId]],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertCount(1, $response->data['items']);
        self::assertSame(['id', 'title', 'slug'], array_keys($response->data['items'][0]));
        self::assertSame($this->fixture->otherCourseId, $response->data['items'][0]['id']);
    }

    public function testLearnerRosterCarriesTheEntitlementBehindEachEnrolment(): void
    {
        $response = $this->fixture->call(
            'GET',
            $this->fixture->path('/manage/courses/{course}/learners'),
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['items', 'total', 'page', 'per_page'], array_keys($response->data));
        self::assertSame(1, $response->data['total']);

        $row = $response->data['items'][0];
        self::assertSame(self::enrolmentRecordKeys(), array_keys($row));
        self::assertSame(['id' => ManagementFixture::LEARNER_ID, 'name' => 'Lena Learner'], $row['user']);
        self::assertSame(['id', 'title', 'slug'], array_keys($row['course']));
        self::assertSame(self::entitlementKeys(), array_keys($row['entitlement']));
        self::assertSame('manual', $row['entitlement']['source']);
    }

    public function testOneLearnerInOneCourseShowsProgressAndLessons(): void
    {
        $response = $this->fixture->call(
            'GET',
            $this->fixture->path('/manage/courses/{course}/learners/{learner}'),
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['enrolment', 'summary', 'lessons'], array_keys($response->data));
        self::assertSame(self::enrolmentRecordKeys(), array_keys($response->data['enrolment']));
        self::assertNull($response->data['summary'], 'No recompute has run yet.');
        self::assertSame([], $response->data['lessons']);
    }

    public function testAnUnenrolledLearnerIsNotFoundInACourse(): void
    {
        $response = $this->fixture->call(
            'GET',
            $this->fixture->path('/manage/courses/{course}') . '/learners/' . ManagementFixture::MANAGER_ID,
            $this->fixture->teacher(),
        );

        self::assertSame(404, $response->status);
    }

    public function testEnrolLearnerAnswersTheEntitlementAndTheEnrolment(): void
    {
        $this->fixture->env->users->add(60, 'Nia Newcomer', 'nia@example.test');

        $response = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/courses/{course}/learners'),
            $this->fixture->teacher(),
            ['user_id' => 60],
        );

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['entitlement', 'enrolment'], array_keys($response->data));
        self::assertSame(self::entitlementKeys(), array_keys($response->data['entitlement']));
        self::assertIsArray($response->data['enrolment']);
        self::assertSame('active', $response->data['enrolment']['status']);
    }

    public function testAFutureStartProvisionsWithoutEnrolling(): void
    {
        $this->fixture->env->users->add(61, 'Fay Future', 'fay@example.test');

        $response = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/courses/{course}/learners'),
            $this->fixture->teacher(),
            ['user_id' => 61, 'starts_at' => '2030-01-01 00:00:00'],
        );

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertNull($response->data['enrolment']);
    }

    public function testAdminDashboardCarriesTheCountsAndWhatNeedsAttention(): void
    {
        $response = $this->fixture->call('GET', '/admin/dashboard', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            ['courses', 'enrolments', 'completions', 'certificates', 'attention', 'top_courses', 'generated_at', 'activity'],
            array_keys($response->data),
        );
        self::assertSame(['published' => 1, 'draft' => 1, 'archived' => 0], $response->data['courses']);
        self::assertSame(['active' => 1, 'suspended' => 0, 'completed' => 0], $response->data['enrolments']);
        self::assertSame(
            ['grading_waiting', 'expiring_entitlements', 'expiry_sweep', 'lessons_without_provider'],
            array_keys($response->data['attention']),
        );
        self::assertSame('hourly', $response->data['attention']['expiry_sweep']['schedule']);
        self::assertNull($response->data['attention']['expiry_sweep']['last_run_at']);
        self::assertSame(
            [['id' => $this->fixture->courseId, 'title' => 'Tea Tasting', 'slug' => 'tea-tasting', 'active_count' => 1,
                'enrolled_count' => 1, 'completed_count' => 0, 'lesson_count' => 3]],
            $response->data['top_courses'],
        );
    }

    public function testAdminEnrolmentsCarryFacetCounts(): void
    {
        $response = $this->fixture->call('GET', '/admin/enrolments', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['items', 'total', 'page', 'per_page', 'facets'], array_keys($response->data));
        self::assertSame(
            ['active', 'expiring', 'suspended', 'expiring_within_days'],
            array_keys($response->data['facets']),
        );
        self::assertSame(1, $response->data['facets']['active']);
        self::assertSame(14, $response->data['facets']['expiring_within_days']);
    }

    public function testLearnerRecordCarriesEveryEnrolmentAndCertificate(): void
    {
        $response = $this->fixture->call(
            'GET',
            $this->fixture->path('/admin/learners/{learner}'),
            $this->fixture->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['user', 'enrolments', 'certificates'], array_keys($response->data));
        self::assertSame(
            ['id' => ManagementFixture::LEARNER_ID, 'name' => 'Lena Learner', 'email' => 'lena@example.test'],
            $response->data['user'],
        );
        self::assertCount(1, $response->data['enrolments']);
        self::assertSame([], $response->data['certificates']);
    }

    public function testLearnerDirectoryCarriesAnExactPagedAggregate(): void
    {
        $response = $this->fixture->call('GET', '/admin/learners', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['items', 'total', 'page', 'per_page'], array_keys($response->data));
        self::assertSame(1, $response->data['total']);
        self::assertSame(
            [
                'user_id',
                'user',
                'course_count',
                'active_course_count',
                'completed_course_count',
                'average_progress',
                'last_activity_at',
                'last_enrolled_at',
            ],
            array_keys($response->data['items'][0]),
        );
        self::assertSame(ManagementFixture::LEARNER_ID, $response->data['items'][0]['user_id']);
        self::assertSame('lena@example.test', $response->data['items'][0]['user']['email']);
        self::assertSame(1, $response->data['items'][0]['course_count']);
    }

    public function testAnUnknownLearnerIsNotFound(): void
    {
        $response = $this->fixture->call('GET', '/admin/learners/999', $this->fixture->manager());

        self::assertSame(404, $response->status);
    }

    public function testCertificateListIsEmptyUntilOneIsIssued(): void
    {
        $response = $this->fixture->call('GET', '/admin/certificates', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['items', 'total', 'page', 'per_page'], array_keys($response->data));
        self::assertSame([], $response->data['items']);
    }

    public function testPlatformSettingsStateTheHostFactsAndTheCoreOnes(): void
    {
        $this->fixture->kernel->ensureDefaultTemplate->execute();

        $response = $this->fixture->call('GET', '/admin/settings', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            [
                'certificate_template',
                'verification_base_url',
                'heartbeat',
                'expiry_sweep',
                'commerce',
                'self_enrolment',
                'host_settings_url',
                'configuration',
                'publish_learner_name',
                'issuer',
                'background_actor',
            ],
            array_keys($response->data),
        );
        self::assertIsArray($response->data['certificate_template']);
        self::assertSame(['id', 'name', 'renderer'], array_keys($response->data['certificate_template']));
        self::assertSame('html', $response->data['certificate_template']['renderer']);
        self::assertSame(['interval_seconds' => 15, 'max_seconds_per_heartbeat' => 120], $response->data['heartbeat']);
        self::assertSame('cms-shop', $response->data['commerce']['source']);
        self::assertTrue($response->data['self_enrolment']);
        self::assertSame('https://example.test/administrator', $response->data['host_settings_url']);
        self::assertSame('https://example.test/verify', $response->data['verification_base_url']);
    }

    public function testUserSearchAnswersMatchingAccounts(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/admin/users',
            $this->fixture->manager(),
            [],
            ['q' => 'lena'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            [['id' => ManagementFixture::LEARNER_ID, 'name' => 'Lena Learner', 'email' => 'lena@example.test']],
            $response->data['items'],
        );
    }

    public function testUserSearchRefusesAnEnroller(): void
    {
        $enroller = Principal::user(77);
        $this->fixture->env->authorization->grant(77, Capability::ENROL_OTHERS);

        $response = $this->fixture->call('GET', '/admin/users', $enroller, [], ['q' => 'tara']);

        self::assertSame(403, $response->status);
    }

    public function testAnEnrollerWhoDoesNotManageTheSiteGetsNoDirectory(): void
    {
        $enroller = Principal::user(77);
        $this->fixture->env->authorization->grant(77, Capability::ENROL_OTHERS);

        foreach (['ex', 'Lena', 'lena@example.test', '@example.test'] as $term) {
            $response = $this->fixture->call('GET', '/admin/users', $enroller, [], ['q' => $term]);
            self::assertSame(403, $response->status);
            self::assertArrayNotHasKey('items', $response->data ?? []);
        }
    }

    public function testAManagerKeepsTheSubstringSearch(): void
    {
        $response = $this->fixture->call('GET', '/admin/users', $this->fixture->manager(), [], ['q' => 'example.test']);

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertNotSame([], $response->data['items']);
    }

    public function testAssignmentConfigurationIsReplacedWholesale(): void
    {
        $response = $this->fixture->call(
            'PUT',
            $this->fixture->path('/manage/lessons/{assignment_lesson}/assignment'),
            $this->fixture->teacher(),
            ['max_points' => 40, 'allowed_file_types' => ['.PDF', 'txt'], 'max_files' => 3],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(self::assignmentKeys(), array_keys($response->data));
        self::assertSame(40.0, $response->data['max_points']);
        self::assertSame(['pdf', 'txt'], $response->data['allowed_file_types']);
        self::assertSame(3, $response->data['max_files']);
        self::assertFalse($response->data['allow_resubmission']);
    }

    public function testMovingALessonAnswersItsAuthoringShape(): void
    {
        $teacher = $this->fixture->teacher();
        $second = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/courses/{course}/modules'),
            $teacher,
            ['title' => 'Advanced'],
        );

        self::assertNotNull($second->data);

        $moved = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/lessons/{lesson}/move'),
            $teacher,
            ['module_id' => $second->data['id']],
        );

        self::assertSame(200, $moved->status);
        self::assertNotNull($moved->data);
        self::assertSame(self::authoringLessonKeys(), array_keys($moved->data));
        self::assertSame($second->data['id'], $moved->data['module_id']);
    }

    public function testPublishRefusesACourseWithNoPublishedLesson(): void
    {
        $teacher = $this->fixture->teacher();
        $empty = $this->fixture->call('POST', '/manage/courses', $teacher, ['title' => 'Empty']);

        self::assertSame(201, $empty->status);
        self::assertNotNull($empty->data);

        $response = $this->fixture->call('POST', '/manage/courses/' . $empty->data['id'] . '/publish', $teacher);

        self::assertSame(409, $response->status);
    }

    public function testArchiveAnswersTheArchivedCourse(): void
    {
        $response = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/courses/{course}/archive'),
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('archived', $response->data['state']);
    }

    public function testRemovingAQuestionAnswersNoContentTwice(): void
    {
        $teacher = $this->fixture->teacher();
        $path = $this->fixture->path('/manage/questions/{question}');

        self::assertSame(204, $this->fixture->call('DELETE', $path, $teacher)->status);
        self::assertSame(204, $this->fixture->call('DELETE', $path, $teacher)->status);
    }

    /**
     * @return list<string>
     */
    private static function managedCourseKeys(): array
    {
        return [
            'id',
            'title',
            'slug',
            'summary',
            'image',
            'category_id',
            'level',
            'language',
            'visibility',
            'state',
            'price_mode',
            'featured',
            'completion_rule',
            'completion_threshold',
            'published_at',
            'updated_at',
            'lesson_count',
            'archived_lesson_count',
            'enrolled_count',
            'active_count',
            'completed_count',
            'suspended_count',
            'completion_rate',
            'instructors',
        ];
    }

    /**
     * @return list<string>
     */
    private static function authoringLessonKeys(): array
    {
        return [
            'id',
            'course_id',
            'module_id',
            'title',
            'slug',
            'type',
            'summary',
            'duration_seconds',
            'position',
            'state',
            'free_preview',
            'content',
            'content_available',
            'quiz',
            'assignment',
        ];
    }

    /**
     * @return list<string>
     */
    private static function quizSummaryKeys(): array
    {
        return [
            'id',
            'course_id',
            'lesson_id',
            'lesson',
            'title',
            'description',
            'pass_mark',
            'max_attempts',
            'time_limit_seconds',
            'is_final',
            'show_results',
            'question_count',
            // The whole quiz-level policy set, which the settings form
            // writes back as one object. None of it is visible to a question
            // type.
            'policy',
        ];
    }

    /**
     * @return list<string>
     */
    private static function assignmentKeys(): array
    {
        return [
            'lesson_id',
            'instructions',
            'max_points',
            'allowed_file_types',
            'max_file_size_kb',
            'max_files',
            'due_at',
            'allow_resubmission',
            'pass_percentage',
            'rubric',
        ];
    }

    /**
     * @return list<string>
     */
    private static function enrolmentRecordKeys(): array
    {
        return [
            'id',
            'user',
            'course',
            'status',
            'progress_percent',
            'enrolled_at',
            'completed_at',
            'last_activity_at',
            'entitlement',
        ];
    }

    /**
     * @return list<string>
     */
    private static function entitlementKeys(): array
    {
        return ['id', 'user_id', 'course_id', 'source', 'source_ref', 'status', 'granted_by', 'starts_at', 'expires_at'];
    }
}
