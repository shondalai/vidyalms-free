<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Access\EnrolLearner;
use Shondalai\VidyaLMS\Core\Application\Access\ExpireEntitlements;
use Shondalai\VidyaLMS\Core\Application\Access\ExtendEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\ListEnrolmentsForCourse;
use Shondalai\VidyaLMS\Core\Application\Access\ListMyEnrolments;
use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\SelfEnrol;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEnrolmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEntitlementRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseCourseRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Commerce\CmsShopCommerceSource;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\ManualEnrolmentRule;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\OpenEnrolmentRule;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Wires the Access context against the SQLite harness for integration tests.
 */
final class AccessFixture
{
    private int $courseSequence = 0;

    /** @var list<int> staff who hold a capability in this fixture */
    private array $staff = [];

    private function __construct(
        public readonly TestPorts $env,
        public readonly DatabaseEntitlementRepository $entitlements,
        public readonly DatabaseEnrolmentRepository $enrolments,
        public readonly EventBus $eventBus,
        public readonly AccessGuard $guard,
        public readonly CourseAccess $courseAccess,
        public readonly DatabaseCourseRepository $courses,
        public readonly EnrolmentRuleRegistry $enrolmentRules,
        public readonly EnrolLearner $enrolLearner,
        public readonly GrantEntitlement $grantEntitlement,
        public readonly SelfEnrol $selfEnrol,
        public readonly RevokeEntitlement $revokeEntitlement,
        public readonly ExtendEntitlement $extendEntitlement,
        public readonly ExpireEntitlements $expireEntitlements,
        public readonly ListMyEnrolments $listMyEnrolments,
        public readonly ListEnrolmentsForCourse $listEnrolmentsForCourse,
    ) {
    }

    public static function create(): self
    {
        $env = TestPorts::create();

        $entitlements = new DatabaseEntitlementRepository($env->database, $env->clock);
        $enrolments = new DatabaseEnrolmentRepository($env->database, $env->clock);
        $eventBus = new EventBus($env->eventBridge, new NullLogger());
        $guard = new AccessGuard($env->authorization);
        $courses = new DatabaseCourseRepository($env->database, $env->clock);
        $courseAccess = new CourseAccess($guard, $courses, $enrolments);

        $rules = new EnrolmentRuleRegistry();
        $rules->register(new OpenEnrolmentRule($env->translator));
        $rules->register(new ManualEnrolmentRule($env->translator));

        $enrolLearner = new EnrolLearner($enrolments, $eventBus, $env->clock);

        // The free storefront, because which sources may be granted is the
        // registry's answer and not a list inside the use case.
        $commerceSources = new CommerceSourceRegistry();
        $commerceSources->register(new CmsShopCommerceSource($env->commerce, $env->translator));

        return new self(
            $env,
            $entitlements,
            $enrolments,
            $eventBus,
            $guard,
            $courseAccess,
            $courses,
            $rules,
            $enrolLearner,
            new GrantEntitlement(
                $courseAccess,
                $env->users,
                $env->database,
                $entitlements,
                $enrolLearner,
                $eventBus,
                $env->clock,
                $commerceSources,
            ),
            new SelfEnrol($guard, $env->database, $entitlements, $enrolments, $enrolLearner, $rules, $eventBus, $env->clock),
            new RevokeEntitlement($courseAccess, $entitlements, $enrolments, $eventBus, $env->clock),
            new ExtendEntitlement($courseAccess, $entitlements, $enrolments, $enrolLearner, $env->clock),
            new ExpireEntitlements(
                $entitlements,
                $enrolments,
                $enrolLearner,
                $eventBus,
                $env->clock,
                new Settings(new DatabaseSettingsRepository($env->database, $env->clock)),
            ),
            new ListMyEnrolments($guard, $enrolments),
            new ListEnrolmentsForCourse($courseAccess, $enrolments),
        );
    }

    /**
     * The same sweep, wired over a different entitlement repository, which
     * is how a test interleaves a concurrent write with the sweep's own.
     */
    public function expireEntitlementsOver(EntitlementRepository $entitlements): ExpireEntitlements
    {
        return new ExpireEntitlements(
            $entitlements,
            $this->enrolments,
            $this->enrolLearner,
            $this->eventBus,
            $this->env->clock,
            new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock)),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public function createCourse(array $overrides = []): int
    {
        $this->courseSequence++;
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        $row = array_merge([
            'title' => 'Course ' . $this->courseSequence,
            'slug' => 'course-' . $this->courseSequence,
            'state' => 'published',
            'price_mode' => 'free',
            'created_by' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        $courseId = $this->env->database->insert('courses', $row);

        foreach ($this->staff as $userId) {
            $this->courses->assignInstructor($courseId, $userId, 'lead');
        }

        return $courseId;
    }

    public function createUser(int $id): int
    {
        $this->env->users->add($id);

        return $id;
    }

    public function grantCapability(int $userId, string $capability = Capability::ENROL_OTHERS): void
    {
        $this->env->authorization->grant($userId, $capability);

        // The per-course rule grants a non-manager only the courses they are
        // an instructor of, so a fixture that hands out a capability also
        // makes that person staff on the fixture's courses.
        if (!\in_array($userId, $this->staff, true)) {
            $this->staff[] = $userId;

            foreach ($this->env->database->selectAll(Query::from('courses')->select(['id'])) as $row) {
                $this->courses->assignInstructor((int) $row['id'], $userId, 'lead');
            }
        }
    }

    public function insertLessonProgress(int $enrolmentId, int $lessonId, int $userId): int
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        return $this->env->database->insert('lesson_progress', [
            'enrolment_id' => $enrolmentId,
            'lesson_id' => $lessonId,
            'user_id' => $userId,
            'status' => 'in_progress',
            'progress_percent' => 40,
            'seconds_spent' => 120,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function entitlementRow(int $id): ?array
    {
        return $this->env->database->selectOne(Query::from('entitlements')->where('id', '=', $id));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function enrolmentRow(int $userId, int $courseId): ?array
    {
        return $this->env->database->selectOne(
            Query::from('enrolments')
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId),
        );
    }

    /**
     * @param class-string<DomainEvent> $eventClass
     *
     * @return list<DomainEvent>
     */
    public function eventsOfType(string $eventClass): array
    {
        return array_values(array_filter(
            $this->env->eventBridge->published,
            static fn (DomainEvent $event): bool => $event instanceof $eventClass,
        ));
    }
}
