<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationEnqueue;
use Shondalai\VidyaLMS\Core\Application\Platform\SendTransactionalMail;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptGraded;
use Shondalai\VidyaLMS\Core\Domain\Events\CertificateIssued;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionReceived;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\AttemptGradedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\CertificateIssuedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\CourseCompletedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\EnrolledTrigger;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\SubmissionReceivedTrigger;
use Shondalai\VidyaLMS\Core\Provider\Token\CertificateTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\CollectingEventBridge;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Shondalai\VidyaLMS\Core\Tests\Support\TransactionalMailHarness;

/**
 * The subscriber that used to be the one God object in this codebase.
 *
 * Every assertion the old test made is made here: the same template keys, the
 * same event references, the same parameters. What changed is where the
 * parameters come from, which is three trigger providers rather than three
 * hardcoded branches, and where they land, which is the queue's intent row
 * rather than a channel called inside a listener.
 */
final class SendTransactionalMailTest extends TestCase
{
    private const BASE_URL = 'https://example.test/verify';

    public function testEnrolmentEnqueuesTheEnrolledTemplate(): void
    {
        $harness = $this->build();

        ($harness->listener)(new LearnerEnrolled($this->at(), 3, 5, 9, 11));

        $rows = $harness->rows();
        self::assertCount(1, $rows);
        self::assertSame('vidyalms.mail.enrolled', $rows[0]->templateKey);
        self::assertSame('enrolment.created:3', $rows[0]->eventReference);
        self::assertSame(9, $rows[0]->parameters['course_id']);
        self::assertSame(11, $rows[0]->parameters['entitlement_id']);
        self::assertSame('Lena Learner', $rows[0]->parameters['learner_name']);
        self::assertSame('Foundations of Tea', $rows[0]->parameters['course_title']);
        self::assertSame(5, $rows[0]->parameters['recipient_user_id']);
    }

    public function testCourseCompletionEnqueuesTheCourseCompletedTemplate(): void
    {
        $harness = $this->build();

        ($harness->listener)(new CourseCompleted($this->at(), 3, 9, 5));

        $rows = $harness->rows();
        self::assertCount(1, $rows);
        self::assertSame('vidyalms.mail.course_completed', $rows[0]->templateKey);
        self::assertSame('course.completed:3', $rows[0]->eventReference);
        self::assertSame('Lena Learner', $rows[0]->parameters['learner_name']);
        self::assertSame('Foundations of Tea', $rows[0]->parameters['course_title']);
    }

    public function testCertificateEnqueuesTheCertificateIssuedTemplate(): void
    {
        $harness = $this->build();

        ($harness->listener)(new CertificateIssued($this->at(), 21, 'CERT-1', 3, 5, 9));

        $rows = $harness->rows();
        self::assertCount(1, $rows);
        self::assertSame('vidyalms.mail.certificate_issued', $rows[0]->templateKey);
        self::assertSame('certificate.issued:21', $rows[0]->eventReference);
        self::assertSame('CERT-1', $rows[0]->parameters['code']);
        self::assertSame('Lena Learner', $rows[0]->parameters['learner_name']);
        self::assertSame('Foundations of Tea', $rows[0]->parameters['course_title']);
    }

    /**
     * A terminal queue row keeps its identity forever, so the address and the
     * live credential URL are not written onto it. The address is re-resolved
     * from the people port at send and the URL is composed by the token
     * resolver from the code the row does carry.
     */
    public function testTheQueueRowCarriesNoAddressAndNoLiveCredentialUrl(): void
    {
        $harness = $this->build();

        ($harness->listener)(new CertificateIssued($this->at(), 21, 'CERT-1', 3, 5, 9));

        $parameters = $harness->rows()[0]->parameters;

        self::assertArrayNotHasKey('learner_email', $parameters);
        self::assertArrayNotHasKey('verification_url', $parameters);
    }

    public function testVerificationUrlIsComposedLikeTheRenderPath(): void
    {
        $resolver = new CertificateTokenResolver(self::BASE_URL . '/');

        self::assertSame(
            self::BASE_URL . '/CERT%201',
            $resolver->resolve('certificate.verification_url', TokenContext::create(['code' => 'CERT 1'])),
        );
    }

    public function testAnEmptyBaseUrlLeavesTheVerificationPlaceholderIntact(): void
    {
        $resolver = new CertificateTokenResolver('');

        self::assertNull(
            $resolver->resolve('certificate.verification_url', TokenContext::create(['code' => 'CERT-1'])),
        );
        self::assertSame(
            'CERT-1',
            $resolver->resolve('certificate.code', TokenContext::create(['code' => 'CERT-1'])),
        );
    }

    public function testAnUnknownUserAndCourseFallBackToEmptyStrings(): void
    {
        $harness = $this->build();

        ($harness->listener)(new LearnerEnrolled($this->at(), 3, 999, 888, 11));

        self::assertSame('', $harness->rows()[0]->parameters['learner_name']);
        self::assertSame('', $harness->rows()[0]->parameters['course_title']);
    }

    public function testUncoveredEventsEnqueueNothing(): void
    {
        $harness = $this->build();

        ($harness->listener)(new LessonCompleted($this->at(), 3, 4, 5, 9));

        self::assertSame([], $harness->rows());
    }

    public function testSubscribeWiresEveryRegisteredTriggersEventsIntoTheBus(): void
    {
        $harness = $this->build();
        $bus = new EventBus(new CollectingEventBridge(), new NullLogger());
        $harness->listener->subscribe($bus);

        $bus->dispatch(new LearnerEnrolled($this->at(), 3, 5, 9, 11));
        $bus->dispatch(new LessonCompleted($this->at(), 3, 4, 5, 9));

        self::assertCount(1, $harness->rows());
    }

    /**
     * A replayed event writes one intent row and not two, because the
     * intent's digest carries no recipient and no channel.
     */
    public function testAReplayedEventWritesOneIntentRow(): void
    {
        $harness = $this->build();
        $event = new LearnerEnrolled($this->at(), 3, 5, 9, 11);

        ($harness->listener)($event);
        ($harness->listener)($event);

        self::assertCount(1, $harness->rows());
    }

    /**
     * A rule that is not enabled fires nothing, which is what shipping the
     * machinery with the rules disabled means.
     */
    public function testADisabledRuleEnqueuesNothing(): void
    {
        $harness = $this->build(enabled: false);

        ($harness->listener)(new LearnerEnrolled($this->at(), 3, 5, 9, 11));

        self::assertSame([], $harness->rows());
    }

    /**
     * Guard against catalogue drift: every placeholder any mail template in
     * the catalogue references must be covered by a parameter the trigger
     * that owns it produces.
     */
    public function testEveryCatalogueMailPlaceholderIsCoveredByTheTriggerParameters(): void
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';

        $tokensByTemplate = [];

        foreach ($catalogue as $key => $text) {
            if (!str_starts_with($key, 'vidyalms.mail.')) {
                continue;
            }

            $templateKey = substr($key, 0, (int) strrpos($key, '.'));
            preg_match_all('/\{([a-z0-9_]+)\}/', $text, $matches);

            foreach ($matches[1] as $token) {
                $tokensByTemplate[$templateKey][$token] = true;
            }
        }

        self::assertNotSame([], $tokensByTemplate, 'The catalogue must define mail templates.');

        $harness = $this->build();

        foreach ($tokensByTemplate as $templateKey => $tokens) {
            $before = \count($harness->rows());
            ($harness->listener)($this->eventFor($templateKey));
            $rows = $harness->rows();

            self::assertCount($before + 1, $rows, sprintf('No intent row produced for "%s".', $templateKey));
            self::assertSame($templateKey, $rows[$before]->templateKey);

            foreach (array_keys($tokens) as $token) {
                self::assertArrayHasKey(
                    $token,
                    $rows[$before]->parameters,
                    sprintf('Template "%s" references {%s} but its trigger does not provide it.', $templateKey, $token),
                );
            }
        }
    }

    public function testWithheldQuizResultsNeverEnterTheNotificationQueue(): void
    {
        $harness = $this->build(showResults: 'never');
        ($harness->listener)($this->eventFor('vidyalms.mail.attempt_graded'));
        self::assertCount(0, $harness->rows());
    }

    private function eventFor(string $templateKey): DomainEvent
    {
        return match ($templateKey) {
            'vidyalms.mail.enrolled' => new LearnerEnrolled($this->at(), 3, 5, 9, 11),
            'vidyalms.mail.course_completed' => new CourseCompleted($this->at(), 3, 9, 5),
            'vidyalms.mail.certificate_issued' => new CertificateIssued($this->at(), 21, 'CERT-1', 3, 5, 9),
            'vidyalms.mail.submission_received' => new SubmissionReceived($this->at(), 31, 41, 51, 3, 5, 9),
            'vidyalms.mail.attempt_graded' => new AttemptGraded($this->at(), 61, 71, 51, 3, 5, 9, 82.5, true),
            default => self::fail(sprintf(
                'The catalogue defines the mail template "%s" but this test knows no event for it; add a fixture.',
                $templateKey,
            )),
        };
    }

    private function build(string $baseUrl = self::BASE_URL, bool $enabled = true, string $showResults = 'after_submit'): TransactionalMailHarness
    {
        $ports = TestPorts::create();
        $ports->users->add(5, 'Lena Learner');
        $ports->database->insert('courses', [
            'id' => 9,
            'title' => 'Foundations of Tea',
            'slug' => 'foundations-of-tea',
            'created_by' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $ports->database->insert('quizzes', [
            'id' => 71, 'course_id' => 9, 'title' => 'Tea quiz', 'show_results' => $showResults,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        $triggers = new NotificationTriggerRegistry();
        $triggers->register(new EnrolledTrigger());
        $triggers->register(new CourseCompletedTrigger());
        $triggers->register(new CertificateIssuedTrigger());
        $triggers->register(new SubmissionReceivedTrigger());
        $triggers->register(new AttemptGradedTrigger());

        $rules = new SqlNotificationRuleRepository($ports->database, $ports->clock);
        $queue = new SqlNotificationQueueRepository($ports->database, $ports->clock);
        $state = $enabled ? NotificationRule::STATE_ENABLED : NotificationRule::STATE_DISABLED;

        foreach ($triggers->all() as $trigger) {
            $rules->save(new NotificationRule(
                null,
                $trigger->key(),
                $trigger->key(),
                $trigger->defaultAudienceKey(),
                $trigger->categoryKey(),
                $trigger->defaultTemplateKey(),
                ['email'],
                state: $state,
            ));
        }

        $enqueue = new NotificationEnqueue(
            $triggers,
            $rules,
            $queue,
            $ports->clock,
            $ports->database,
            $ports->users,
            [CertificateIssuedTrigger::FACT_VERIFICATION_BASE_URL => $baseUrl],
        );

        $listener = new SendTransactionalMail($triggers, $enqueue);

        return new TransactionalMailHarness($listener, $queue, $ports);
    }

    private function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
    }
}
