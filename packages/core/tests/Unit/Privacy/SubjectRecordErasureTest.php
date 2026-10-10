<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\SubjectOrganisationPrivacy;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Privacy\CoreLearningPrivacy;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesSubjectRecords;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\SqliteDatabase;
use Shondalai\VidyaLMS\Core\Tests\Support\SqliteSchemaInstaller;

/**
 * The direction the host's own privacy tooling asks for.
 *
 * Both host hooks used to hold their own table lists: a Joomla EXPORT_BY_USER
 * array of eight tuples with a second inline list of ten tables in the erase
 * routine, and a WordPress OWNED_BY_USER array of ten names beside an
 * ANONYMISE array of five pairs. Four copies of one list, and adding a table
 * meant editing two adapters and a language file in the same release; missing
 * one meant an export and an erasure disagreeing about what the site holds.
 *
 * The lists are one list now, in {@see CoreLearningPrivacy}, and this is what
 * the hosts loop over instead. A table added to that class reaches both hosts
 * with no adapter edit, which is the claim the two const arrays falsified.
 */
final class SubjectRecordErasureTest extends TestCase
{
    private SqliteDatabase $database;

    private CoreLearningPrivacy $contributor;

    protected function setUp(): void
    {
        $this->database = SqliteDatabase::inMemory();
        SqliteSchemaInstaller::install($this->database);
        $this->contributor = new CoreLearningPrivacy($this->database);
    }

    public function testTheContributorCarriesTheHostSubjectErasureCapability(): void
    {
        self::assertInstanceOf(ErasesSubjectRecords::class, $this->contributor);
    }

    /**
     * Every table both host arrays named is erased by the one routine.
     *
     * The list is the union of the two, asserted by name here rather than
     * derived from the class, so a table quietly dropped from RECORD_CLASSES
     * fails this test instead of silently narrowing what a host erases.
     */
    public function testEveryTableBothHostArraysNamedIsErasedByTheOneRoutine(): void
    {
        $this->seed(50);

        $outcome = $this->contributor->eraseSubjectRecords(50, 'lena@example.test');

        foreach (
            ['entitlements', 'enrolments', 'lesson_progress', 'video_positions', 'attempts',
            'submissions', 'certificates', 'activity_log', 'email_log', 'course_instructors'] as $recordClass
        ) {
            self::assertSame(1, $outcome['erased'][$recordClass] ?? 0, $recordClass . ' survived the erasure');
            self::assertSame(
                0,
                $this->database->count(Query::from($recordClass)->where('user_id', '=', 50)),
                $recordClass . ' still holds a row for the subject',
            );
        }

        self::assertSame([], $outcome['warnings']);
    }

    /**
     * The rows hanging off an attempt, a submission and an enrolment go with
     * them.
     *
     * Children before parents, because nothing here is enforced by a foreign
     * key and a child left behind is a row whose owner can no longer be found.
     * Both hosts used to do this for themselves, in two routines that agreed by
     * coincidence rather than by construction; it is one routine now.
     */
    public function testTheRowsHangingOffAParentGoWithIt(): void
    {
        $this->seed(50);

        $outcome = $this->contributor->eraseSubjectRecords(50, '');

        foreach (['attempt_answers', 'attempt_grades', 'submission_files', 'course_progress'] as $child) {
            self::assertSame(1, $outcome['erased'][$child] ?? 0, $child . ' survived the erasure');
            self::assertSame(0, $this->database->count(Query::from($child)), $child . ' still holds a child row');
        }
    }

    /**
     * A row that belongs to somebody else keeps its content and loses the
     * subject's identity.
     *
     * Deleting a course this person authored would erase every enrolment and
     * every result on it, and deleting a grade they awarded would take another
     * learner's mark away. The right is the subject's own record, not a third
     * party's.
     */
    public function testAThirdPartysRowKeepsItsContentAndLosesTheIdentity(): void
    {
        $this->seed(50);

        $outcome = $this->contributor->eraseSubjectRecords(50, '');

        self::assertSame(1, $outcome['anonymised']['courses.created_by'] ?? 0);
        self::assertSame(1, $outcome['anonymised']['import_jobs.created_by'] ?? 0);

        $course = $this->database->selectOne(Query::from('courses')->where('id', '=', 900));
        self::assertNotNull($course);
        self::assertSame(0, (int) $course['created_by']);
        self::assertSame('Third party course', (string) $course['title']);
    }

    /**
     * An email-only request still reaches the delivery log and nothing else.
     *
     * The delivery log is the one place an address is stored without an
     * account. It is reached by the address and never keyed on it.
     */
    public function testAnEmailOnlyRequestReachesTheDeliveryLogAndNothingElse(): void
    {
        $this->seed(50);
        $this->insertRow('email_log', [
            'user_id' => null,
            'recipient' => 'lena@example.test',
            'template_key' => 'welcome',
            'status' => 'sent',
        ]);

        $outcome = $this->contributor->eraseSubjectRecords(0, 'lena@example.test');

        self::assertSame(1, $outcome['erased']['email_log'] ?? 0);
        self::assertSame([], $outcome['anonymised']);
        self::assertSame(1, $this->database->count(Query::from('attempts')->where('user_id', '=', 50)));
    }

    /**
     * The loop both hosts call lives in the core, and it skips a contributor
     * that does not serve this direction.
     *
     * Two independently written loops are two readings of one contract, and the
     * day they disagreed the disagreement would look like a host difference
     * rather than a defect. A contributor over organisation-owned rows declares
     * nothing here and is passed over rather than asked and refused.
     */
    public function testTheRegistryLoopSkipsAContributorThatDoesNotServeThisDirection(): void
    {
        $this->seed(50);

        $registry = new PrivacyContributorRegistry();
        $registry->register($this->contributor);
        $registry->register(new OrganisationOnlyPrivacyDouble());

        $outcome = (new SubjectOrganisationPrivacy($registry, $this->database))
            ->eraseSubjectRecords(50, 'lena@example.test');

        self::assertSame(1, $outcome['erased']['attempts'] ?? 0);
        self::assertSame(1, $outcome['anonymised']['courses.created_by'] ?? 0);
        self::assertSame([], $outcome['warnings']);
    }

    /**
     * An ordinary learner's own rows are not a reason to refuse the erasure.
     *
     * The refusal exists for rows the host request must not touch: an
     * organisation relationship, a ledger entry, a report artefact somebody
     * else commissioned. Counting a contributor that erases its own rows would
     * have made every learner with one enrolment undeleteable on both hosts,
     * which is a statutory obligation failed silently rather than loudly.
     */
    public function testALearnersOwnRowsDoNotTriggerTheReviewRefusal(): void
    {
        $this->seed(50);

        $registry = new PrivacyContributorRegistry();
        $registry->register($this->contributor);
        $registry->register(new OrganisationOnlyPrivacyDouble());
        $service = new SubjectOrganisationPrivacy($registry, $this->database);

        self::assertFalse(
            $service->requiresReview(50, 'lena@example.test'),
            'a learner with an enrolment cannot be undeleteable',
        );

        // A contributor that does not erase its own rows still stops the host.
        $this->insertRow('org_memberships', [
            'organisation_id' => 1,
            'user_id' => 50,
            'joined_at' => '2026-03-01 09:00:00',
        ]);

        self::assertTrue($service->requiresReview(50, 'lena@example.test'));
    }

    /** Somebody else's rows are untouched by this person's request. */
    public function testAnotherLearnersRecordIsUntouched(): void
    {
        $this->seed(50);
        $this->seed(51);

        $this->contributor->eraseSubjectRecords(50, 'lena@example.test');

        self::assertSame(1, $this->database->count(Query::from('attempts')->where('user_id', '=', 51)));
        self::assertSame(1, $this->database->count(Query::from('enrolments')->where('user_id', '=', 51)));
    }

    /**
     * The columns each table refuses a row without, so the seed is a row the
     * shipped schema actually accepts rather than a stub.
     *
     * Keyed off the subject so two seeded learners do not collide on a unique
     * index: a learner has their own enrolment, their own lesson and their own
     * certificate code, which is what a real site looks like.
     *
     * @return array<string, array<string, mixed>>
     */
    private function required(int $userId): array
    {
        return [
            'entitlements' => ['course_id' => $userId, 'source' => 'manual'],
            'enrolments' => [
                'course_id' => $userId,
                'entitlement_id' => $userId,
                'enrolled_at' => '2026-03-01 09:00:00',
            ],
            'lesson_progress' => ['enrolment_id' => $userId, 'lesson_id' => $userId],
            'video_positions' => ['lesson_id' => $userId],
            'attempts' => [
                'quiz_id' => $userId,
                'enrolment_id' => $userId,
                'attempt_number' => 1,
                'started_at' => '2026-03-01 09:00:00',
            ],
            'submissions' => ['assignment_id' => $userId, 'enrolment_id' => $userId],
            'certificates' => [
                'course_id' => $userId,
                'enrolment_id' => $userId,
                'template_id' => 1,
                'code' => 'CERT-' . $userId,
                'issued_at' => '2026-03-01 09:00:00',
            ],
            'activity_log' => ['action' => 'lesson.completed', 'occurred_at' => '2026-03-01 09:00:00'],
            'email_log' => [
                'template_key' => 'welcome',
                'recipient' => 'learner' . $userId . '@example.test',
                'status' => 'sent',
            ],
            'course_instructors' => ['course_id' => $userId],
            'shop_access_links' => [
                'shop' => 'woocommerce', 'object_type' => 'order', 'object_id' => (string) $userId,
                'entitlements' => '{}', 'last_reason' => 'woocommerce_completed',
            ],
        ];
    }

    /**
     * One row, with the two stamps every shipped table carries.
     *
     * @param array<string, mixed> $row
     */
    private function insertRow(string $table, array $row): void
    {
        $this->database->insert($table, $row + [
            'created_at' => '2026-03-01 09:00:00',
            'updated_at' => '2026-03-01 09:00:00',
        ]);
    }

    private function seed(int $userId): void
    {
        foreach (CoreLearningPrivacy::RECORD_CLASSES as $definition) {
            if (!isset($this->required($userId)[$definition['table']])) { continue; }
            $row = [$definition['column'] => $userId] + ($this->required($userId)[$definition['table']] ?? []);

            $this->insertRow($definition['table'], $row);
        }

        $attemptId = (int) ($this->database->selectOne(
            Query::from('attempts')->select(['id'])->where('user_id', '=', $userId)->orderBy('id'),
        )['id'] ?? 0);
        $submissionId = (int) ($this->database->selectOne(
            Query::from('submissions')->select(['id'])->where('user_id', '=', $userId)->orderBy('id'),
        )['id'] ?? 0);
        $enrolmentId = (int) ($this->database->selectOne(
            Query::from('enrolments')->select(['id'])->where('user_id', '=', $userId)->orderBy('id'),
        )['id'] ?? 0);

        $this->insertRow('attempt_answers', [
            'attempt_id' => $attemptId,
            'question_id' => 11,
            'answer' => '{"choice":"a"}',
            'answered_at' => '2026-03-01 09:05:00',
        ]);
        $this->insertRow('attempt_grades', [
            'attempt_id' => $attemptId,
            'grader_id' => $userId,
            'points_awarded' => 1,
            'max_points' => 1,
            'graded_at' => '2026-03-01 09:06:00',
        ]);
        $this->insertRow('submission_files', [
            'submission_id' => $submissionId,
            'path' => 'submissions/essay.pdf',
            'filename' => 'essay.pdf',
            'size_bytes' => 12,
            'mime' => 'application/pdf',
        ]);
        $this->insertRow('course_progress', [
            'enrolment_id' => $enrolmentId,
            'computed_at' => '2026-03-01 09:07:00',
        ]);

        $this->insertRow('courses', [
            'id' => 900 + $userId - 50,
            'title' => 'Third party course',
            'slug' => 'third-party-course-' . $userId,
            'created_by' => $userId,
        ]);
        $this->insertRow('import_jobs', ['importer' => 'json-course', 'created_by' => $userId]);
    }
}
