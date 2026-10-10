<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Commerce\BackgroundActorResolver;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The site-wide management surface: what the course list sorts on, what the
 * dashboard notices, and the fact that both pickers are the registry rather
 * than a list somebody keeps in step by hand.
 */
final class SiteManagementTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testTheCourseListSortsOnACountAcrossTheWholeSet(): void
    {
        $manager = $this->fixture->manager();

        $ascending = $this->fixture->call(
            'GET',
            '/manage/courses',
            $manager,
            [],
            ['sort' => 'enrolled_count', 'order' => 'asc'],
        );
        $descending = $this->fixture->call(
            'GET',
            '/manage/courses',
            $manager,
            [],
            ['sort' => 'enrolled_count', 'order' => 'desc'],
        );

        self::assertNotNull($ascending->data);
        self::assertNotNull($descending->data);
        self::assertSame($this->fixture->otherCourseId, $ascending->data['items'][0]['id']);
        self::assertSame($this->fixture->courseId, $descending->data['items'][0]['id']);
        self::assertSame(2, $ascending->data['total']);
    }

    public function testTheCourseListFiltersOnStateAndTitle(): void
    {
        $manager = $this->fixture->manager();

        $drafts = $this->fixture->call('GET', '/manage/courses', $manager, [], ['state' => 'draft']);
        self::assertNotNull($drafts->data);
        self::assertSame(1, $drafts->data['total']);
        self::assertSame('draft', $drafts->data['items'][0]['state']);

        $search = $this->fixture->call('GET', '/manage/courses', $manager, [], ['q' => 'tea']);
        self::assertNotNull($search->data);
        self::assertSame(1, $search->data['total']);
        self::assertSame('Tea Tasting', $search->data['items'][0]['title']);
    }

    public function testTheSiteDashboardRequiresManage(): void
    {
        $reporter = 71;
        $manager = 72;
        $neither = 73;

        $this->fixture->env->authorization->grant($reporter, Capability::VIEW_REPORTS);
        $this->fixture->env->authorization->grant($manager, Capability::MANAGE);
        $this->fixture->env->authorization->grant($neither, Capability::GRADE);

        self::assertSame(
            403,
            $this->fixture->call('GET', '/admin/dashboard', Principal::user($reporter))->status,
        );
        self::assertSame(
            200,
            $this->fixture->call('GET', '/admin/dashboard', Principal::user($manager))->status,
            'every other /admin route lets a manager in; the dashboard is not the exception',
        );
        self::assertSame(
            403,
            $this->fixture->call('GET', '/admin/dashboard', Principal::user($neither))->status,
        );
        self::assertSame(
            401,
            $this->fixture->call('GET', '/admin/dashboard', Principal::guest())->status,
        );
    }

    public function testTheDashboardNoticesLessonsWhoseProviderIsGone(): void
    {
        $now = $this->fixture->env->clock->now()->format('Y-m-d H:i:s');

        // Pro data on a site that removed Pro: the row is intact and nothing
        // on the site can present it.
        $this->fixture->env->database->insert('lessons', [
            'course_id' => $this->fixture->courseId,
            'module_id' => $this->fixture->moduleId,
            'title' => 'Live session',
            'slug' => 'live-session',
            'type' => 'live',
            'position' => 9,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $response = $this->fixture->call('GET', '/admin/dashboard', $this->fixture->manager());

        self::assertNotNull($response->data);
        self::assertSame(
            ['count' => 1, 'types' => ['live']],
            $response->data['attention']['lessons_without_provider'],
        );
    }

    public function testTheDashboardCountsEntitlementsExpiringInsideTheWindow(): void
    {
        $this->fixture->env->database->update(
            'entitlements',
            ['expires_at' => '2026-01-20 10:00:00'],
            ['id' => $this->fixture->entitlementId],
        );

        $inside = $this->fixture->call('GET', '/admin/dashboard', $this->fixture->manager());
        self::assertNotNull($inside->data);
        self::assertSame(1, $inside->data['attention']['expiring_entitlements']['count']);
        self::assertSame(14, $inside->data['attention']['expiring_entitlements']['within_days']);

        $narrow = $this->fixture->call(
            'GET',
            '/admin/dashboard',
            $this->fixture->manager(),
            [],
            ['expiring_days' => '2'],
        );
        self::assertNotNull($narrow->data);
        self::assertSame(0, $narrow->data['attention']['expiring_entitlements']['count']);
        self::assertSame(2, $narrow->data['attention']['expiring_entitlements']['within_days']);
    }

    public function testTheExpirySweepRecordsWhenItRan(): void
    {
        $before = $this->fixture->call('GET', '/admin/settings', $this->fixture->manager());
        self::assertNotNull($before->data);
        self::assertNull($before->data['expiry_sweep']['last_run_at']);

        $this->fixture->kernel->expireEntitlements->execute();

        $after = $this->fixture->call('GET', '/admin/settings', $this->fixture->manager());
        self::assertNotNull($after->data);
        self::assertSame('2026-01-15 10:00:00', $after->data['expiry_sweep']['last_run_at']);
        self::assertSame('hourly', $after->data['expiry_sweep']['schedule']);

        $dashboard = $this->fixture->call('GET', '/admin/dashboard', $this->fixture->manager());
        self::assertNotNull($dashboard->data);
        self::assertSame('2026-01-15 10:00:00', $dashboard->data['attention']['expiry_sweep']['last_run_at']);
    }

    public function testThePickersAreTheRegistrySoAnAddedProviderAppears(): void
    {
        $teacher = $this->fixture->teacher();

        $before = $this->fixture->call('GET', '/question-types', $teacher);
        self::assertNotNull($before->data);
        self::assertCount(6, $before->data['items'], 'A free site lists six.');

        $this->fixture->kernel->registries->questionTypes->register($this->matchingQuestionType());
        $this->fixture->kernel->registries->lessonTypes->register($this->liveLessonType());

        $questionTypes = $this->fixture->call('GET', '/question-types', $teacher);
        $lessonTypes = $this->fixture->call('GET', '/lesson-types', $teacher);

        self::assertNotNull($questionTypes->data);
        self::assertNotNull($lessonTypes->data);
        self::assertCount(7, $questionTypes->data['items']);
        // A type that declares no group or order sorts first, because the
        // picker orders on the declaration and this one makes none. That is
        // the point of declaring it: a third-party type lands where its
        // author says, not where boot order happened to put it.
        self::assertSame('matching', $questionTypes->data['items'][0]['key']);
        self::assertCount(6, $lessonTypes->data['items']);
        self::assertSame(
            ['key' => 'live', 'label' => 'Live session', 'completion_mode' => 'view'],
            $lessonTypes->data['items'][5],
        );
    }

    public function testThePickersAreRefusedToACallerWhoCannotAuthor(): void
    {
        $reporter = Principal::user(88);
        $this->fixture->env->authorization->grant(88, Capability::VIEW_REPORTS);

        self::assertSame(403, $this->fixture->call('GET', '/lesson-types', $reporter)->status);
        self::assertSame(403, $this->fixture->call('GET', '/question-types', $reporter)->status);
    }

    public function testTheCertificateListNarrowsOnStatusAndSearch(): void
    {
        $manager = $this->fixture->manager();
        $enrolmentId = $this->enrolmentId();
        $certificate = $this->fixture->kernel->issueCertificate->execute(
            $enrolmentId,
            ManagementFixture::LEARNER_ID,
            $this->fixture->courseId,
        );

        $all = $this->fixture->call('GET', '/admin/certificates', $manager);
        self::assertNotNull($all->data);
        self::assertCount(1, $all->data['items']);

        $record = $all->data['items'][0];
        // The list reports the stored vocabulary now: the status enumeration
        // has six values and "active" is the one an in-force certificate
        // carries. "valid" survives only as a filter value.
        self::assertSame('active', $record['status']);
        self::assertSame(['id' => ManagementFixture::LEARNER_ID, 'name' => 'Lena Learner'], $record['learner']);
        self::assertSame('https://example.test/verify/' . $certificate->code, $record['verification_url']);

        $byCode = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['q' => $certificate->code]);
        self::assertNotNull($byCode->data);
        self::assertCount(1, $byCode->data['items']);

        // A revocation records why. It is permanent and public, and a
        // reason nobody wrote down is an answer a relying party cannot act
        // on, so the route refuses without one.
        $refused = $this->fixture->call('POST', '/admin/certificates/' . $certificate->id . '/revoke', $manager);
        self::assertSame(422, $refused->status);

        $revoked = $this->fixture->call(
            'POST',
            '/admin/certificates/' . $certificate->id . '/revoke',
            $manager,
            ['reason' => 'issued_in_error'],
        );
        self::assertSame(200, $revoked->status);
        self::assertNotNull($revoked->data);
        self::assertSame('revoked', $revoked->data['status']);

        $stillRevoked = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['status' => 'revoked']);
        self::assertNotNull($stillRevoked->data);
        self::assertCount(1, $stillRevoked->data['items']);

        $valid = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['status' => 'valid']);
        self::assertNotNull($valid->data);
        self::assertSame([], $valid->data['items']);
    }

    public function testAdminEnrolmentsNarrowOnTheExpiringWindow(): void
    {
        $this->fixture->env->database->update(
            'entitlements',
            ['expires_at' => '2026-01-20 10:00:00'],
            ['id' => $this->fixture->entitlementId],
        );

        $manager = $this->fixture->manager();

        $expiring = $this->fixture->call(
            'GET',
            '/admin/enrolments',
            $manager,
            [],
            ['status' => 'active', 'expiring_within_days' => '14'],
        );
        self::assertNotNull($expiring->data);
        self::assertCount(1, $expiring->data['items']);
        self::assertSame(1, $expiring->data['facets']['expiring']);

        $narrow = $this->fixture->call(
            'GET',
            '/admin/enrolments',
            $manager,
            [],
            ['status' => 'active', 'expiring_within_days' => '2'],
        );
        self::assertNotNull($narrow->data);
        self::assertSame([], $narrow->data['items']);
    }

    private function enrolmentId(): int
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('user_id', '=', ManagementFixture::LEARNER_ID)
                ->where('course_id', '=', $this->fixture->courseId),
        );

        self::assertNotNull($row);

        return (int) $row['id'];
    }

    private function matchingQuestionType(): QuestionTypeInterface
    {
        return new class ($this->fixture->env->translator) implements QuestionTypeInterface {
            use DefaultDefinitionVersion;

            public function __construct(private readonly TranslatorPort $translator)
            {
            }

            public function key(): string
            {
                return 'matching';
            }

            public function label(): string
            {
                return $this->translator->translate('Matching');
            }

            public function validateDefinition(array $definition): void
            {
                if (!isset($definition['prompt'])) {
                    throw ValidationException::forField('prompt', 'A prompt is required.');
                }
            }

            public function presentForLearner(array $definition): array
            {
                return ['prompt' => $definition['prompt'] ?? ''];
            }

            public function grade(array $definition, mixed $answer): GradeResult
            {
                return GradeResult::correct();
            }
        };
    }

    private function liveLessonType(): LessonTypeInterface
    {
        return new class implements LessonTypeInterface {
            use DefaultContentVersion;

            public function key(): string
            {
                return 'live';
            }

            public function label(): string
            {
                return 'Live session';
            }

            public function completionMode(): string
            {
                return self::COMPLETION_ON_VIEW;
            }

            public function validateContent(array $content): void
            {
            }

            public function presentForLearner(array $content, bool $hasAccess): array
            {
                return $hasAccess ? $content : [];
            }
        };
    }

    /* ── The commerce background actor ──────────────────────────────── */

    /**
     * The one setting every gateway delivery reads, and the writer it never
     * had.
     *
     * `commerce.background_actor_user_id` is declared by
     * {@see BackgroundActorResolver::SETTING} and was written by nothing in
     * the shipped product: no route, no screen and no installer seed, only
     * tests. On a stock site the resolver therefore answered nobody for every
     * webhook capture, the delivery deferred with "The current user may not
     * perform vidyalms.commerce", and at the attempt ceiling it settled
     * failed, so a payment was taken and the course was never granted.
     */
    public function testAStockSiteHasNoBackgroundActorSoNoBackgroundGrantResolves(): void
    {
        // The reproduction, and the whole of the defect: nothing in the
        // shipped product writes this row, so the resolver outside a request
        // answers nobody on every site that has not been reached into by
        // hand.
        self::assertNull(
            $this->fixture->kernel->backgroundActor->resolve(),
            'no configured actor, no background grant, and no writer to configure one with',
        );
    }

    public function testTheBackgroundActorIsUnsetUntilSomebodyWritesItAndThenResolves(): void
    {
        $manager = $this->fixture->manager();
        $kernel = $this->fixture->kernel;

        $before = $this->fixture->call('GET', '/admin/settings', $manager);

        self::assertSame(200, $before->status);
        self::assertNotNull($before->data);
        self::assertSame(
            ['user_id' => null, 'name' => null, 'may_grant' => false],
            $before->data['background_actor'],
        );

        $written = $this->fixture->call(
            'PUT',
            '/admin/settings',
            $manager,
            ['background_actor_user_id' => ManagementFixture::TEACHER_ID],
        );

        self::assertSame(200, $written->status, (string) json_encode($written->data));
        self::assertNotNull($written->data);
        self::assertSame(
            [
                'user_id' => ManagementFixture::TEACHER_ID,
                'name' => 'Tara Teacher',
                'may_grant' => true,
            ],
            $written->data['background_actor'],
        );

        $resolved = $kernel->backgroundActor->resolve();

        self::assertNotNull($resolved, 'the row the route wrote is the row the resolver reads');
        self::assertSame(ManagementFixture::TEACHER_ID, $resolved->userId);

        // And it survives a read by somebody else, because it is a setting
        // rather than something the request carried.
        $read = $this->fixture->call('GET', '/admin/settings', $manager);

        self::assertNotNull($read->data);
        self::assertSame(ManagementFixture::TEACHER_ID, $read->data['background_actor']['user_id']);
    }

    /**
     * An account that cannot grant is refused at write time.
     *
     * Storing one would be a setting whose only visible effect is an order
     * that defers for hours and then settles failed, with the resolver
     * reporting a configuration gap on a setting somebody had made.
     */
    public function testAnAccountThatCannotEnrolOthersIsRefusedAsTheBackgroundActor(): void
    {
        $refused = $this->fixture->call(
            'PUT',
            '/admin/settings',
            $this->fixture->manager(),
            ['background_actor_user_id' => ManagementFixture::LEARNER_ID],
        );

        self::assertSame(422, $refused->status);
        self::assertNull(
            $this->fixture->kernel->backgroundActor->resolve(),
            'a refused write leaves the row unwritten',
        );

        $missing = $this->fixture->call(
            'PUT',
            '/admin/settings',
            $this->fixture->manager(),
            ['background_actor_user_id' => 987654],
        );

        self::assertSame(422, $missing->status);

        $notManage = $this->fixture->call(
            'PUT',
            '/admin/settings',
            $this->fixture->learner(),
            ['background_actor_user_id' => ManagementFixture::TEACHER_ID],
        );

        self::assertSame(403, $notManage->status);
    }

    /** Null is the site owner saying nobody, and it clears a written row. */
    public function testNullClearsTheBackgroundActor(): void
    {
        $manager = $this->fixture->manager();

        $this->fixture->call(
            'PUT',
            '/admin/settings',
            $manager,
            ['background_actor_user_id' => ManagementFixture::TEACHER_ID],
        );

        self::assertNotNull($this->fixture->kernel->backgroundActor->resolve());

        $cleared = $this->fixture->call(
            'PUT',
            '/admin/settings',
            $manager,
            ['background_actor_user_id' => null],
        );

        self::assertSame(200, $cleared->status);
        self::assertNotNull($cleared->data);
        self::assertNull($cleared->data['background_actor']['user_id']);
        self::assertNull($this->fixture->kernel->backgroundActor->resolve());
    }
}
