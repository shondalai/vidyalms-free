<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\FailingQueueDatabase;

/**
 * The outbox is a transaction and not a listener.
 *
 * The shipped event bus wraps every listener in a try and logs, by design, so
 * an outbox written from a listener commits the business state and loses the
 * row: the caller gets a 201, the enrolment exists, and the mail it promised
 * does not. That is the direction that loses mail. The opposite direction, a
 * queue row in an aborted transaction, is free: the row rolls back with
 * everything else.
 */
final class TransactionalOutboxTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');
    }

    /**
     * The direction that loses mail: the enqueue is forced to fail and the
     * whole operation rolls back rather than committing without it.
     */
    public function testAStateChangeWhoseEnqueueFailsRollsBack(): void
    {
        $database = $this->fixture->env->database;
        $before = $database->count(Query::from('enrolments'));

        // A kernel whose queue insert refuses. Everything else is the real
        // thing, so what this measures is the transaction rather than a
        // stubbed world.
        $kernel = new CoreKernel(new Ports(
            new FailingQueueDatabase($database),
            $this->fixture->env->ports->users,
            $this->fixture->env->ports->authorization,
            $this->fixture->env->ports->storage,
            $this->fixture->env->ports->mailer,
            $this->fixture->env->ports->scheduler,
            $this->fixture->env->ports->eventBridge,
            $this->fixture->env->ports->commerce,
            $this->fixture->env->ports->translator,
            $this->fixture->env->ports->cache,
            $this->fixture->env->ports->clock,
            $this->fixture->env->ports->random,
            $this->fixture->env->ports->logger,
        ));

        $entitlementId = $database->insert('entitlements', [
            'user_id' => 999,
            'course_id' => $this->fixture->courseId,
            'source' => 'manual',
            'status' => 'active',
            'starts_at' => '2026-04-01 10:00:00',
            'created_at' => '2026-04-01 10:00:00',
            'updated_at' => '2026-04-01 10:00:00',
        ]);

        try {
            $kernel->enrolLearner->execute(999, $this->fixture->courseId, $entitlementId);

            self::fail('a failed enqueue did not surface to the caller');
        } catch (\RuntimeException) {
            // Expected: the enqueue threw and the transaction took the
            // enrolment with it.
        }

        self::assertSame(
            $before,
            $database->count(Query::from('enrolments')),
            'the business state committed while its outbox row was lost',
        );
    }

    /**
     * The ordinary direction: enrolling a learner writes the row and the
     * intent together, and the caller sees both.
     */
    public function testEnrollingWritesTheStateAndTheIntentTogether(): void
    {
        $before = $this->fixture->env->database->count(
            Query::from('notification_queue')->where('trigger_key', '=', 'vidyalms.mail.enrolled'),
        );

        $enrolment = $this->fixture->kernel->enrolLearner->execute(
            999,
            $this->fixture->courseId,
            $this->entitlementFor(999),
        );

        self::assertInstanceOf(Enrolment::class, $enrolment);

        self::assertSame(
            $before + 1,
            $this->fixture->env->database->count(
                Query::from('notification_queue')->where('trigger_key', '=', 'vidyalms.mail.enrolled'),
            ),
            'the enrolment committed with no outbox row',
        );
    }

    /**
     * The listener stays as the safety net for an event reaching the bus by
     * another road, and the dedupe digest makes the two paths idempotent
     * rather than duplicative: dispatching the same event again writes no
     * second row.
     */
    public function testTheListenerIsIdempotentBesideTheDirectEnqueue(): void
    {
        $enrolment = $this->fixture->kernel->enrolLearner->execute(
            999,
            $this->fixture->courseId,
            $this->entitlementFor(999),
        );

        $after = $this->fixture->env->database->count(
            Query::from('notification_queue')->where('trigger_key', '=', 'vidyalms.mail.enrolled'),
        );

        $this->fixture->kernel->events->dispatch(new LearnerEnrolled(
            $this->fixture->env->clock->now(),
            $enrolment->id(),
            999,
            $this->fixture->courseId,
            $this->entitlementFor(999),
        ));

        self::assertSame(
            $after,
            $this->fixture->env->database->count(
                Query::from('notification_queue')->where('trigger_key', '=', 'vidyalms.mail.enrolled'),
            ),
        );
    }

    private function entitlementFor(int $userId): int
    {
        return $this->fixture->env->database->insert('entitlements', [
            'user_id' => $userId,
            'course_id' => $this->fixture->courseId,
            'source' => 'manual',
            'status' => 'active',
            'starts_at' => '2026-04-01 10:00:00',
            'created_at' => '2026-04-01 10:00:00',
            'updated_at' => '2026-04-01 10:00:00',
        ]);
    }
}
