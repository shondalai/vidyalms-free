<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The full journey through the composition root and the HTTP surface: author
 * a course, publish, enrol, read, take the final quiz, and watch completion
 * fan out into certificate, mail and the activity log, exactly once each.
 */
final class SpineTest extends TestCase
{
    private const INSTRUCTOR_ID = 1;
    private const LEARNER_ID = 2;

    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;
    private Principal $instructor;
    private Principal $learner;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::INSTRUCTOR_ID, Capability::TEACH);
        $this->env->users->add(self::INSTRUCTOR_ID, 'Ivy Instructor');
        $this->env->users->add(self::LEARNER_ID, 'Lena Learner', 'lena@example.test');

        $this->kernel = new CoreKernel($this->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->api = $this->kernel->apiKernel();
        $this->instructor = Principal::user(self::INSTRUCTOR_ID);
        $this->learner = Principal::user(self::LEARNER_ID);
    }

    public function testBootRegistersEveryFreeDefaultProviderAndRecordsTheLedger(): void
    {
        $registries = $this->kernel->registries;

        self::assertSame(['reading', 'video', 'resource', 'quiz', 'assignment'], $registries->lessonTypes->keys());
        self::assertSame(
            ['single_choice', 'multiple_choice', 'true_false', 'fill_blank', 'short_answer', 'ordering'],
            $registries->questionTypes->keys(),
        );
        self::assertSame(['open', 'manual'], $registries->enrolmentRules->keys());
        self::assertSame(['cms-shop'], $registries->commerceSources->keys());
        self::assertSame(['email'], $registries->notificationChannels->keys());
        // Free reports include both scalar summaries and tabular learning records.
        // The tabular ones
        // are what give the free csv and json writers something to write: a
        // seam whose only free report returned six numbers would have shipped
        // two export providers no free code path could reach.
        //
        // Three describe the organisation catalogue.
        // org-overview is the summary route re-homed as a registered definition,
        // so the shipped alias and the report read one set of queries; org-roster
        // is built on membership rather than enrolment and names the people with
        // nothing to do; org-access-log answers Article 28(3)(h) for free,
        // because a free table with a free writer and no free reader is a
        // padlock by omission.
        self::assertSame(
            [
                'course-overview',
                'site-learning',
                'enrolment-roster',
                'assignment-history',
                'active-learning-time',
                'training-activity',
                'org-access-log',
                // The early-warning report, advisory by design. Every flagged
                // row names a reason in words, a person to contact and an
                // acted-on flag, which is the shape GDPR Article 22 leaves
                // alone: a score that prompts a human tutor engages nothing,
                // and a score that fails somebody by itself engages everything.
                // It is free because a site that cannot see who is falling
                // behind until it buys something loses those learners first.
                'at-risk-learners',
                'org-overview',
                'org-roster',
                // The AI usage report. It is a registered report rather than a
                // screen query precisely so it declares its own required
                // capability and a site wanting a different cut of the spend
                // registers one instead of waiting for a route.
                'ai.usage',
            ],
            $registries->reports->keys(),
        );
        // The five reporting seams, each with its free providers registered
        // through the same public method a package outside the core uses.
        self::assertSame(['csv', 'json'], $registries->exportFormats->keys());
        self::assertSame(['download', 'notification-link'], $registries->reportDeliveries->keys());
        // Five, not two: `weight_bp`, `drop_lowest` and `extra_credit` were
        // columns the gradebook wrote and nothing folded, so the three
        // strategies that read them are free rather than paid.
        self::assertSame(
            ['natural', 'mean', 'weighted-mean', 'drop-lowest', 'extra-credit'],
            $registries->gradeAggregations->keys(),
        );
        // And the display half of the same finding: display_mode stored all
        // three words and nothing rendered any of them.
        self::assertSame(['real', 'percentage', 'letter'], $registries->gradeDisplays->keys());
        self::assertSame(['log'], $registries->statementForwarders->keys());
        // Two renderers, and the second is registered beside the first rather
        // than over it: replacing the html key would silently reinterpret
        // every stored row that names it. The third answers "pdf" only while
        // no PDF port is seated, so designs published under 1.0.0 keep
        // issuing as HTML.
        self::assertSame(['html', 'elements', 'pdf'], $registries->certificateRenderers->keys());
        // The three that shipped first, then the six format importers of Wave
        // 6: three reading a WordPress product's tables and three reading a
        // Joomla one's. All nine are free and all nine register through the
        // same extension point.
        self::assertSame(
            [
                'json-course',
                'moodle-backup',
                'community-quiz',
                'learndash',
                'tutor-lms',
                'lifterlms',
                'shika',
                'guru',
                'oscampus',
                // The two free roster importers, which are what finally make
                // the free build's own sentence about adding people by CSV
                // reachable. They register through the same extension point as
                // the nine above rather than through a roster registry of their
                // own, because a second importer registry would be a second
                // answer to one question.
                'org-members-csv:in',
                'oneroster-csv-1.2:in',
            ],
            $registries->importers->keys(),
        );

        // The six registries the assessment foundation adds. Every one of
        // them is filled in the free build, including the three the free six
        // barely stretch, because a seam only the paid build uses is a seam
        // nobody tests.
        self::assertSame(
            [
                'match_correct',
                'map_response',
                'per_target',
                'plus_minus',
                'guess_neutral',
                'text_match',
                'kendall_tau',
                'absolute_position',
                'external',
                'none',
            ],
            $registries->scoringPolicies->keys(),
        );
        self::assertSame(
            ['select', 'order', 'associate', 'text', 'scalar', 'point', 'artifact', 'signal'],
            $registries->interactionPrimitives->keys(),
        );
        self::assertSame(['deferred_feedback', 'manual'], $registries->attemptPolicies->keys());
        self::assertSame([], $registries->definitionUpgraders->keys());
        self::assertSame([], $registries->itemMappers->keys());
        self::assertSame(
            ['single_choice', 'multiple_choice', 'true_false', 'short_answer', 'fill_blank', 'ordering'],
            $registries->questionPresets->keys(),
        );

        // The three quiz-level seams. Composition decides which questions an
        // attempt is served, aggregation decides which attempt is reported,
        // and the negative marking registry decides what a wrong answer
        // costs. The free build fills the first two and deliberately fills
        // none of the third: the seam exists so a rule can be added without
        // a core edit, and offering none is a decision the design argues for
        // rather than a gap.
        self::assertSame(['quiz_questions'], $registries->quizCompositions->keys());
        self::assertSame(['highest', 'latest'], $registries->aggregationMethods->keys());
        self::assertSame([], $registries->negativeMarking->keys());

        $active = $this->kernel->featureProviders->listActive();

        // 20 before the assessment foundation, plus ten scoring policies,
        // eight interaction primitives, two attempt behaviours and six
        // presets, plus one composition and two aggregation methods, plus
        // the five publication checks and eight integrity event kinds the
        // free build fills its own two seams with. Those thirteen were
        // missing from the ledger, so an admin removing Pro was told a check
        // or a signal kind belonged to no package at all.
        // Plus the twenty-one the credentials wave adds: seventeen elements,
        // three credential formats and one credit scheme, on the ledger for
        // the sharpest version of the same reason, because an element key is
        // stored on a template row and a format key on a certificate. The
        // third format is the Open Badges 2.0 hosted Assertion the baked
        // badge's own address resolves to.
        // The organization privacy contributor is recorded so an unavailable
        // provider is visible on export and stops erasure after package removal.
        // The reporting contributor joins it because report_runs rows and their
        // artefacts are personal data, and an erasure request that had to hunt
        // object storage by hand is exactly what the untracked blob forced.
        // The learning contributor joins them as the seam's whole point: the
        // eighteen core learning tables used to live in two closed const arrays
        // inside the two host privacy hooks, so adding a table meant editing
        // two adapter routines and adding a language key, and a Drupal adapter
        // would have reimplemented both lists. Registered here, adding a table
        // reaches both hosts with no adapter edit.
        // The AI contributor joins them for the same reason again:
        // ai_run_subjects is the table that answers whose data a run was
        // about, and no contributor named an ai_ table at all, so the shipped
        // subject access export and the erasure path were blind to every one
        // of them while GET /me/ai-activity was being offered as the Article
        // 15 answer.
        self::assertSame(
            ['core-organisations', 'core-learning', 'core-ai', 'core-reporting'],
            $registries->privacyContributors->keys(),
        );
        // Plus the eight the reporting wave adds: three more reports, one
        // privacy contributor, two export formats and two deliveries. The two
        // export seams are on the ledger because report_runs.format_key and
        // report_runs.delivery_key are real columns holding a provider key;
        // grade aggregation and statement forwarding are deliberately not,
        // because neither key is stored anywhere yet.
        // Plus the six format importers of Wave 6, which are ledger entries
        // like every other free provider: an import job stores its importer
        // key, so a package removed after a migration has to stay visible.
        // Plus the five of the governance wave: the two free roster importers,
        // the two organisation reports and the core learning privacy
        // contributor. The field disclosure seam is deliberately absent, on the
        // ledger's own stated rule: no column stores a disclosure key yet, so a
        // ledger row for it would record ownership of a key no row references.
        // Plus the AI usage report, which is a ledger entry for the same
        // reason every other report is: report_runs.report_key stores a
        // provider key, so a package removed after a run has to stay visible.
        // Plus the AI privacy contributor, for the same reason the reporting
        // one is on the ledger: a package removed after a run has to stay
        // visible, and the AI tables hold subject-bearing rows.
        // Plus the at-risk report of the reporting wave, on the same rule as
        // every other report: report_runs.report_key stores a provider key, so
        // a package removed after a run has to stay visible. That wave's other
        // registration, the reports.schedule task type, adds no row, because a
        // scheduled task type is not a ledgered provider.
        // The assignment-history, active-learning-time and training-activity
        // reports, and the colour panel question type, are ledgered too.
        // Plus the "pdf" certificate renderer that stands in while no PDF
        // port is seated: certificate_templates.renderer stores that key, and
        // the 1.0.0 ledger already held it under the core package.
        self::assertCount(112, $active);

        foreach ($active as $provider) {
            self::assertSame('vidyalms-core', $provider->package);
        }
    }

    public function testFullLearnerJourneyOverTheHttpSurface(): void
    {
        [$courseId, $readingLessonId, $quizLessonId, $quizId, $questionId] = $this->authorAndPublishCourse();

        // Self-enrol over HTTP with an idempotency key.
        $enrolResponse = $this->api->handle($this->request(
            'POST',
            '/enrolments',
            $this->learner,
            body: ['course_id' => $courseId],
            idempotencyKey: 'enrol-once',
        ));
        self::assertSame(201, $enrolResponse->status);
        self::assertNotNull($enrolResponse->data);
        $enrolmentId = $enrolResponse->data['id'];
        self::assertIsInt($enrolmentId);
        self::assertSame('active', $enrolResponse->data['status']);
        self::assertCount(1, $this->publishedEvents(LearnerEnrolled::class));

        // The curriculum now carries the learner's (empty) progress context.
        $curriculum = $this->api->handle($this->request('GET', '/courses/' . $courseId . '/curriculum', $this->learner));
        self::assertSame(200, $curriculum->status);
        self::assertNotNull($curriculum->data);
        self::assertIsArray($curriculum->data['enrolment']);
        self::assertSame($enrolmentId, $curriculum->data['enrolment']['id']);

        // Heartbeat the reading lesson to completion.
        $heartbeat = $this->api->handle($this->request('POST', '/lessons/' . $readingLessonId . '/progress', $this->learner, body: [
            'enrolment_id' => $enrolmentId,
            'progress_percent' => 100,
            'seconds_delta' => 90,
            'completed' => true,
        ]));
        self::assertSame(204, $heartbeat->status);

        // Start the quiz, answer correctly, submit.
        $start = $this->api->handle($this->request('POST', '/quizzes/' . $quizId . '/attempts', $this->learner));
        self::assertSame(201, $start->status);
        self::assertNotNull($start->data);
        $attemptId = $start->data['id'];
        self::assertIsInt($attemptId);

        // 200 with a per-question validity report rather than 204: an
        // invalid answer is stored and reported, never refused, because
        // refusing one key of an autosave payload wedges the whole attempt.
        $save = $this->api->handle($this->request('PUT', '/attempts/' . $attemptId . '/answers', $this->learner, body: [
            'answers' => [$questionId => 'a'],
        ]));
        self::assertSame(200, $save->status);
        self::assertNotNull($save->data);
        self::assertTrue($save->data['questions'][0]['valid']);
        self::assertSame('answered', $save->data['questions'][0]['answered_status']);

        $submit = $this->api->handle($this->request('POST', '/attempts/' . $attemptId . '/submit', $this->learner));
        self::assertSame(200, $submit->status);
        self::assertNotNull($submit->data);
        self::assertIsArray($submit->data['attempt']);
        self::assertSame('graded', $submit->data['attempt']['status']);
        self::assertTrue($submit->data['attempt']['passed']);
        self::assertEqualsWithDelta(100.0, (float) $submit->data['attempt']['percentage'], 0.001);

        // Both lessons completed, course progress 100, enrolment completed.
        foreach ([$readingLessonId, $quizLessonId] as $lessonId) {
            $row = $this->env->database->selectOne(
                Query::from('lesson_progress')
                    ->where('enrolment_id', '=', $enrolmentId)
                    ->where('lesson_id', '=', $lessonId),
            );
            self::assertNotNull($row);
            self::assertSame('completed', $row['status']);
        }

        $courseProgress = $this->env->database->selectOne(
            Query::from('course_progress')->where('enrolment_id', '=', $enrolmentId),
        );
        self::assertNotNull($courseProgress);
        self::assertEqualsWithDelta(100.0, (float) $courseProgress['percent'], 0.001);
        self::assertSame(2, (int) $courseProgress['lessons_completed']);

        $enrolmentRow = $this->env->database->selectOne(Query::from('enrolments')->where('id', '=', $enrolmentId));
        self::assertNotNull($enrolmentRow);
        self::assertSame('completed', $enrolmentRow['status']);
        self::assertNotNull($enrolmentRow['completed_at']);

        // CourseCompleted exactly once, certificate issued and verifiable.
        self::assertCount(1, $this->publishedEvents(CourseCompleted::class));

        $certificateRow = $this->env->database->selectOne(
            Query::from('certificates')->where('enrolment_id', '=', $enrolmentId),
        );
        self::assertNotNull($certificateRow);
        $code = $certificateRow['code'];
        self::assertIsString($code);

        $verify = $this->api->handle($this->request('GET', '/certificates/' . $code . '/verify', Principal::guest()));
        self::assertSame(200, $verify->status);
        self::assertNotNull($verify->data);
        self::assertTrue($verify->data['valid']);
        // Public names require explicit site opt-in; the credential remains
        // verifiable when its holder's name is withheld.
        self::assertNull($verify->data['learner_name']);
        self::assertSame('Foundations of Tea', $verify->data['course_title']);

        // Enrolled, completed and certificate mails went out exactly once
        // each, through the queue rather than from inside the listener that
        // heard the event: the intent row, the fan-out and the drain are
        // three steps and the mail is the last of them.
        $this->runQueue();
        self::assertSame(1, $this->mailCount('vidyalms.mail.enrolled'));
        self::assertSame(1, $this->mailCount('vidyalms.mail.course_completed'));
        self::assertSame(1, $this->mailCount('vidyalms.mail.certificate_issued'));

        // A redelivered enrolment event is deduped by the queue digest.
        $this->kernel->events->dispatch(new LearnerEnrolled(
            $this->env->clock->now(),
            $enrolmentId,
            self::LEARNER_ID,
            $courseId,
            (int) $enrolmentRow['entitlement_id'],
        ));
        $this->runQueue();
        self::assertSame(1, $this->mailCount('vidyalms.mail.enrolled'));

        // The activity log carries the whole journey.
        $actions = array_map(
            static fn (array $row): string => (string) $row['action'],
            $this->env->database->selectAll(Query::from('activity_log')->where('user_id', '=', self::LEARNER_ID)),
        );
        self::assertContains('vidyalms.enrolment.created', $actions);
        self::assertContains('vidyalms.attempt.submitted', $actions);
        self::assertContains('vidyalms.course.completed', $actions);
        self::assertContains('vidyalms.certificate.issued', $actions);
        self::assertSame(2, \count(array_keys($actions, 'vidyalms.lesson.completed', true)));

        // My progress and my certificates read back over HTTP.
        $progress = $this->api->handle($this->request(
            'GET',
            '/me/progress',
            $this->learner,
            query: ['course_id' => (string) $courseId],
        ));
        self::assertSame(200, $progress->status);
        self::assertNotNull($progress->data);
        self::assertSame('completed', $progress->data['enrolment_status']);
        self::assertIsArray($progress->data['summary']);
        self::assertEqualsWithDelta(100.0, (float) $progress->data['summary']['percent'], 0.001);

        $certificates = $this->api->handle($this->request('GET', '/me/certificates', $this->learner));
        self::assertSame(200, $certificates->status);
        self::assertNotNull($certificates->data);
        self::assertIsArray($certificates->data['items']);
        self::assertCount(1, $certificates->data['items']);
        self::assertSame($code, $certificates->data['items'][0]['code']);

        // Replaying the enrolment with the same idempotency key returns the
        // stored outcome unchanged and emits no second LearnerEnrolled.
        $learnerEnrolledBefore = \count($this->publishedEvents(LearnerEnrolled::class));
        $replay = $this->api->handle($this->request(
            'POST',
            '/enrolments',
            $this->learner,
            body: ['course_id' => $courseId],
            idempotencyKey: 'enrol-once',
        ));
        self::assertSame(201, $replay->status);
        self::assertNotNull($replay->data);
        self::assertSame($enrolmentId, $replay->data['id']);
        self::assertSame('active', $replay->data['status']);
        self::assertCount($learnerEnrolledBefore, $this->publishedEvents(LearnerEnrolled::class));
        self::assertSame(1, $this->env->database->count(Query::from('enrolments')));
    }

    /**
     * @return array{int, int, int, int, int}
     */
    private function authorAndPublishCourse(): array
    {
        $course = $this->kernel->createCourse->execute($this->instructor, 'Foundations of Tea');
        $module = $this->kernel->addModule->execute($this->instructor, $course->id, 'Module One');

        $reading = $this->kernel->addLesson->execute(
            $this->instructor,
            $module->id,
            'Read the leaves',
            'reading',
            ['body' => '<p>Steep for four minutes.</p>'],
        );

        $quiz = $this->kernel->createQuiz->execute(
            $this->instructor,
            $course->id,
            'Final Quiz',
            passMark: 60,
            isFinal: true,
        );
        $quizId = $quiz->id;
        self::assertIsInt($quizId);

        $quizLesson = $this->kernel->addLesson->execute(
            $this->instructor,
            $module->id,
            'Prove it',
            'quiz',
            ['quiz_id' => $quizId],
        );

        $this->kernel->updateQuiz->execute(
            $this->instructor,
            $quizId,
            'Final Quiz',
            lessonId: $quizLesson->id,
            passMark: 60,
            isFinal: true,
        );

        $question = $this->kernel->addQuestion->execute($this->instructor, $quizId, 'single_choice', [
            'prompt' => 'Ideal green tea temperature?',
            'options' => [
                ['key' => 'a', 'label' => '80 C'],
                ['key' => 'b', 'label' => '100 C'],
            ],
            'correct' => 'a',
        ], 5.0);
        $questionId = $question->id;
        self::assertIsInt($questionId);

        $this->kernel->publishCourse->execute($this->instructor, $course->id);

        return [$course->id, $reading->id, $quizLesson->id, $quizId, $questionId];
    }

    /**
     * @param array<string, string> $query
     * @param array<string, mixed>  $body
     */
    private function request(
        string $method,
        string $path,
        Principal $principal,
        array $query = [],
        array $body = [],
        ?string $idempotencyKey = null,
    ): ApiRequest {
        return new ApiRequest($method, $path, $principal, $query, $body, $idempotencyKey);
    }

    /**
     * @param class-string<DomainEvent> $eventClass
     *
     * @return list<DomainEvent>
     */
    private function publishedEvents(string $eventClass): array
    {
        return array_values(array_filter(
            $this->env->eventBridge->published,
            static fn (DomainEvent $event): bool => $event instanceof $eventClass,
        ));
    }

    /**
     * The two queue jobs, run in the order a host's scheduler would run
     * them: the fan-out expands the intent rows the use cases wrote, and the
     * drain claims and sends.
     */
    private function runQueue(): void
    {
        $this->kernel->notificationFanout->run();
        $this->kernel->notificationSender->drain(100);
    }

    private function mailCount(string $templateKey): int
    {
        return \count(array_filter(
            $this->env->mailer->sent,
            static fn (MailMessage $message): bool => $message->templateKey === $templateKey,
        ));
    }
}
