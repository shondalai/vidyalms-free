<?php

/**
 * VidyaLMS: end to end verification of the WordPress adapter.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 *
 * Drives the access spine (course, curriculum, publish, entitlement, enrolment,
 * progress, completion, certificate, verification) through the CoreKernel this
 * plugin builds, against a real WordPress and a real MySQL, so every step
 * exercises the real port implementations rather than a fake.
 *
 * What it proves that a unit test cannot:
 *   - WpDatabase compiles the core Query into working MySQL
 *   - update() reports genuinely affected rows, so the guarded completion
 *     update fires CourseCompleted exactly once
 *   - upsert() dedupes on the unique key, so replayed heartbeats do not
 *     multiply progress rows
 *   - the certificate code round-trips through the real tables
 *   - a signed private URL survives the two layers of encoding WordPress puts
 *     on a query argument, and a tampered signature is refused
 *   - manage_options reaches the operational capabilities but not vidyalms.admin
 *
 * Usage (from any directory):
 *   php build/smoke.php [--wp=<wordpress root>] [--actor=<user id>]
 *   php build/smoke.php --cleanup
 *
 * --cleanup removes everything a previous run seeded and leaves the plugin
 * installed and active.
 *
 * The script writes to the site it is pointed at. Point it at a development
 * site, never at production.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "smoke.php runs on the command line only.\n");
    exit(1);
}

// No built-in site: --wp or VIDYALMS_WORDPRESS_SITE names it, because this
// script works against whatever site it is pointed at.
$wpRoot = rtrim(str_replace('\\', '/', (string) getenv('VIDYALMS_WORDPRESS_SITE')), '/');
$actorId = 0;
$cleanup = false;
$keep = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--wp=')) {
        $wpRoot = rtrim(str_replace('\\', '/', substr($argument, 5)), '/');
    } elseif (str_starts_with($argument, '--actor=')) {
        $actorId = (int) substr($argument, 8);
    } elseif ($argument === '--cleanup') {
        $cleanup = true;
    } elseif ($argument === '--keep') {
        $keep = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php build/smoke.php [--wp=<root>] [--actor=<user id>] [--cleanup] [--keep]\n";
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(1);
    }
}

if (!is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, 'Not a WordPress root (pass --wp=<wordpress root> or set VIDYALMS_WORDPRESS_SITE): ' . $wpRoot . "\n");
    exit(1);
}

// WordPress reads the request environment even on the command line, and
// home_url() would otherwise build links against an empty host.
$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_URI'] ??= '/' . basename($wpRoot) . '/';
$_SERVER['SCRIPT_NAME'] ??= '/' . basename($wpRoot) . '/index.php';
$_SERVER['PHP_SELF'] ??= $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD'] ??= 'GET';

define('WP_USE_THEMES', false);

require_once __DIR__ . '/../../../tooling/fixtures/bootstrap.php';
require_once $wpRoot . '/wp-load.php';

// After wp-load.php and not before it. The contract bootstrap loads by require
// and not by autoload, and several of its files extend a core class
// (SmokeOrganisationRosterImporter extends Registry\Importer\AbstractImporter).
// The core is autoloaded by the plugin's own vendor map, registered while
// WordPress loads plugins, so requiring the contracts first resolves the
// parent through an autoloader that has never heard of it.
require_once __DIR__ . '/../../../tooling/contract/bootstrap.php';

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\WordPress\Jobs\QueueRunner;
use Shondalai\VidyaLMS\WordPress\Plugin;
use Shondalai\VidyaLMS\WordPress\Port\WpAuthorization;
use Shondalai\VidyaLMS\WordPress\Port\WpScheduler;
use Shondalai\VidyaLMS\WordPress\Port\WpStorage;
use Shondalai\VidyaLMS\WordPress\Rest\RestController;
use Shondalai\VidyaLMS\Tooling\Fixtures\SchemaSeal;
use Shondalai\VidyaLMS\WordPress\Setup\Activator;
use Shondalai\VidyaLMS\WordPress\Setup\Capabilities;

if (!function_exists('vidyalms_kernel')) {
    fwrite(STDERR, "The vidyalms plugin is not active on this site.\n");
    exit(1);
}

// This harness seeds and removes rows. It creates no table and drops none, on
// this site or any other, and the seal makes that a checked fact rather than an
// intention: if the table list differs on the way out, however the run ends,
// the run fails and names what moved. A script that genuinely needs tables of
// its own creates a dedicated database and drops the database, never a table in
// a schema it does not own. See tooling/fixtures/README.md.
$schemaSeal = new SchemaSeal(
    'wordpress smoke',
    static fn (): array => array_map('strval', (array) $GLOBALS['wpdb']->get_col('SHOW TABLES')),
);
$schemaSeal->verifyOnShutdown();

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------

$failures = 0;
$pendings = 0;
$step = 0;

function out(string $line = ''): void
{
    echo $line, "\n";
}

function heading(string $title): void
{
    out();
    out('== ' . $title);
}

function step(string $what, string $result): void
{
    global $step;

    printf("  %2d. %-46s %s\n", ++$step, $what, $result);
}

function assertThat(bool $condition, string $what, string $evidence): void
{
    global $failures, $step;

    $failures += $condition ? 0 : 1;
    printf("  %2d. [%s] %-41s %s\n", ++$step, $condition ? 'PASS' : 'FAIL', $what, $evidence);
}

/**
 * Run one deliberately narrow queue pass. The per-process option override is
 * removed even when a task throws, and the site's stored setting is never
 * changed. The caller first gives its exact rows an ancient run_at, so this
 * pass cannot reach later environmental backlog rows.
 *
 * @return array{claimed: int, done: int, failed: int, reclaimed: int, pruned: int, sources_pruned: int}
 */
function drainSmokeOwnedBatch(int $batchSize): array
{
    $bounded = max(1, min(200, $batchSize));
    $filter = 'pre_option_' . Activator::OPTION_JOB_BATCH_SIZE;
    $override = static fn (mixed $previous): string => (string) $bounded;

    add_filter($filter, $override);

    try {
        return QueueRunner::drain();
    } finally {
        remove_filter($filter, $override);
    }
}

/**
 * The drain window that is guaranteed to contain one named queue row.
 *
 * NotificationSendTask takes a limit and nothing else, and the queue hands
 * the drain its due pending rows ordered by run_at. A section that queues a
 * row now and drains a fixed five therefore proves nothing on a site that
 * already holds five due rows: its own row is the sixth and the assertions
 * report a queue defect that is really a backlog. That is not hypothetical.
 * This site failed the envelope section on seven pending rows an earlier run
 * of this same harness had left behind, and the Joomla harness reproduces it
 * on its own second run.
 *
 * So the window is measured rather than assumed: every due pending row at or
 * before this row's own run_at occupies a slot, deferred ones included, since
 * the LIMIT is applied in SQL and the next_attempt_at skip happens after it.
 * The floor of five keeps the shape of the check unchanged on a clean site.
 *
 * Nothing is weakened: the section still asserts that THIS row reached the
 * transport, was assembled, and reached sent with its own tag.
 */
function smokeEnvelopeDrainLimit(DatabasePort $storage, int $queueId): int
{
    $own = $storage->selectOne(Query::from('notification_queue')->where('id', '=', $queueId));

    if ($own === null) {
        return 5;
    }

    $ahead = $storage->selectAll(
        Query::from('notification_queue')
            ->where('state', '=', \Shondalai\VidyaLMS\Core\Domain\Notification\QueueState::Pending->value)
            ->where('run_at', '<=', (string) $own['run_at'])
    );

    return max(5, count($ahead));
}

/**
 * A check this build cannot make yet, named rather than skipped silently.
 *
 * The adapter half of a feature can be finished and provable while the core
 * route or provider it calls is still being written, and the two ship in
 * different packages. A section that quietly returned early in that state
 * would look identical to a section that passed, which is the one thing a
 * harness may never do. So it is counted separately, printed with what is
 * missing, and reported in the summary: the run is green on what it can
 * assert, and it says out loud what it could not.
 *
 * It is never a substitute for a failing assertion. A check that CAN run and
 * does not hold is a FAIL.
 */
function pending(string $what, string $missing): void
{
    global $pendings, $step;

    $pendings++;
    printf("  %2d. [PEND] %-41s %s\n", ++$step, $what, $missing);
}

/**
 * A scheduled task the clock has to start, for the section that proves the
 * periodic turn end to end.
 *
 * It exists because the four task types this wave introduces are shipped by
 * the core and by the paid package, not by this plugin, and the path they
 * travel is entirely this plugin's: the drain that starts them, the queue they
 * land in, the registry that resolves them. A probe under the same key proves
 * that path before the shipped handler exists and keeps proving it afterwards,
 * and the section skips the probe for any key already registered so a shipped
 * handler is never shadowed.
 */
final class SmokeProbeSweepTask implements
    \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface,
    \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically
{
    public function __construct(
        private readonly string $type,
        private readonly \ArrayObject $log,
    ) {
    }

    public function key(): string
    {
        return $this->type;
    }

    public function binCount(): int
    {
        return 2;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return 'smoke-' . $now->format('YmdH');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return 'vidyalms-smoke-sweep:' . $this->type . ':' . $periodBucket . ':' . $binIndex;
    }

    public function run(\Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun $run): string
    {
        $this->log[] = $this->type . '#' . $run->int('binIndex');

        return 'smoke bin ' . $run->int('binIndex') . ' of ' . $run->int('binCount');
    }
}

/**
 * Every registered key whose provider class comes from outside the free build.
 *
 * The model bridge seam wants to say "the free build registers exactly this
 * one", because a second one nobody registered would be a model call nobody
 * reviewed. That equality can only be asserted on a
 * free-only site, and this harness runs on a site that may have Pro installed.
 * The property that survives an edition is the same one in a form a merged
 * registry can answer: the free provider is still there, and every other key
 * belongs to a class from outside the free build.
 *
 * Attribution is by the provider class's own namespace rather than by the
 * feature-provider ledger, because the ledger is written by the package that
 * registers and a bridge registered straight into the registry by a host plugin
 * has no ledger row.
 *
 * **The Joomla harness has had this since the wave that hit the problem, and
 * this one did not.** It went unnoticed because the WordPress Pro plugin could
 * not attach at all: its packager never staged the org tenancy declaration, so
 * every Pro registration threw inside attach() and the free-only equality held
 * on a site with Pro activated. Both halves are fixed together, because either
 * one alone leaves a false green.
 *
 * @param list<string> $freeKeys the keys the free build itself registers
 *
 * @return list<string> every other registered key whose provider is not a free one
 */
function providersOutsideTheFreeBuild(
    \Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry $registry,
    array $freeKeys,
): array {
    $outside = [];

    foreach ($registry->keys() as $key) {
        if (\in_array($key, $freeKeys, true)) {
            continue;
        }

        if (!str_starts_with(\get_class($registry->get($key)), 'Shondalai\\VidyaLMS\\Core\\')) {
            $outside[] = $key;
        }
    }

    return $outside;
}

/**
 * Whether the core refused the call. Only NotAuthorizedException counts: a
 * missing row or a validation failure would be a different answer, and
 * accepting any throwable would let a typo pass for a refusal.
 *
 * @param callable(): mixed $call
 */
function refused(callable $call): bool
{
    try {
        $call();
    } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException) {
        return true;
    } catch (\Throwable $other) {
        out('    (refused() saw ' . \get_class($other) . ': ' . $other->getMessage() . ')');

        return false;
    }

    return false;
}

/**
 * Every row one seeded course owns, removed in dependency order.
 *
 * Used twice: by the gated-quiz section, which removes its own course as soon
 * as it is finished with it, and by --cleanup, which removes whatever a run
 * that died half way left behind. One function rather than two lists, because
 * a table added to one and not the other is a leak nobody notices until a
 * later run trips over the row.
 *
 * It removes rows only. It creates no table and drops none, which is what the
 * schema seal checks on the way out.
 */
function removeSmokeCourse(DatabasePort $storage, int $courseId): int
{
    $removed = 0;

    // The permalink shell goes with the course, and it goes from here rather
    // than from the caller. CourseSync mirrors a course into a vidyalms_course
    // post when it is created or published, so every seeded course leaves one,
    // and a caller that removed the rows without the post left a published page
    // pointing at a course that no longer exists. That is how eight
    // parity-authoring-course posts accumulated on the test site.
    $shellId = \Shondalai\VidyaLMS\WordPress\Content\CourseCpt::shellIdForCourse($courseId);

    if ($shellId > 0) {
        wp_delete_post($shellId, true);
        \Shondalai\VidyaLMS\WordPress\Content\CourseCpt::forgetShell($courseId);
        $removed++;
    }

    foreach ($storage->selectAll(Query::from('enrolments')->where('course_id', '=', $courseId)) as $seeded) {
        $enrolmentId = (int) $seeded['id'];
        $removed += $storage->delete('course_progress', ['enrolment_id' => $enrolmentId]);
        $removed += $storage->delete('lesson_progress', ['enrolment_id' => $enrolmentId]);
    }

    foreach ($storage->selectAll(Query::from('quizzes')->where('course_id', '=', $courseId)) as $seededQuiz) {
        $quizId = (int) $seededQuiz['id'];

        foreach ($storage->selectAll(Query::from('attempts')->where('quiz_id', '=', $quizId)) as $seededAttempt) {
            $attemptId = (int) $seededAttempt['id'];

            foreach (['attempt_answer_events', 'attempt_answers', 'attempt_questions', 'attempt_grades'] as $child) {
                $removed += $storage->delete($child, ['attempt_id' => $attemptId]);
            }

            $removed += $storage->delete('attempts', ['id' => $attemptId]);
        }

        $removed += $storage->delete('questions', ['quiz_id' => $quizId]);
        $removed += $storage->delete('availability_rules', ['subject_type' => 'quiz', 'subject_id' => $quizId]);
        $removed += $storage->delete('availability_rule_refs', ['owner_type' => 'quiz', 'owner_id' => $quizId]);
        $removed += $storage->delete('quizzes', ['id' => $quizId]);
    }

    foreach ($storage->selectAll(Query::from('lessons')->where('course_id', '=', $courseId)) as $seededLesson) {
        $lessonId = (int) $seededLesson['id'];
        $removed += $storage->delete('lesson_content', ['lesson_id' => $lessonId]);
        $removed += $storage->delete('video_positions', ['lesson_id' => $lessonId]);
        $removed += $storage->delete('notification_queue', ['subject_type' => 'lesson', 'subject_id' => $lessonId]);
        $removed += $storage->delete('availability_rules', ['subject_type' => 'lesson', 'subject_id' => $lessonId]);
        $removed += $storage->delete('availability_rule_refs', ['owner_type' => 'lesson', 'owner_id' => $lessonId]);

        // The submission children hang off the assignment rather than the
        // lesson, so they go before the assignment does or they are orphaned.
        foreach (
            $storage->selectAll(Query::from('assignments')->where('lesson_id', '=', $lessonId)) as $seededAssignment
        ) {
            $seededAssignmentId = (int) $seededAssignment['id'];

            foreach (
                $storage->selectAll(
                    Query::from('submissions')->where('assignment_id', '=', $seededAssignmentId)
                ) as $seededSubmission
            ) {
                $removed += $storage->delete('submission_files', ['submission_id' => (int) $seededSubmission['id']]);
            }

            $removed += $storage->delete('submissions', ['assignment_id' => $seededAssignmentId]);
            $removed += $storage->delete('assignments', ['id' => $seededAssignmentId]);
        }
    }

    // The gradebook, child first: a change names its cell and a cell names its
    // item, so removing the item first would leave a log row nobody can read.
    foreach (['grade_changes', 'grade_cells', 'grade_items'] as $gradeTable) {
        $removed += $storage->delete($gradeTable, ['course_id' => $courseId]);
    }

    foreach ($storage->selectAll(Query::from('modules')->where('course_id', '=', $courseId)) as $seededModule) {
        $removed += $storage->delete(
            'availability_rules',
            ['subject_type' => 'module', 'subject_id' => (int) $seededModule['id']],
        );
    }

    // The credential child rows hang off the certificate rather than the
    // course, so they go before the certificates do or they are orphaned.
    foreach ($storage->selectAll(Query::from('certificates')->where('course_id', '=', $courseId)) as $seededCertificate) {
        $seededCertificateId = (int) $seededCertificate['id'];
        $removed += $storage->delete('certificate_credits', ['certificate_id' => $seededCertificateId]);
        $removed += $storage->delete(
            'credential_status_entries',
            ['subject_type' => 'certificate', 'subject_id' => $seededCertificateId],
        );
    }

    $removed += $storage->delete('course_credits', ['course_id' => $courseId]);
    $removed += $storage->delete('availability_rules', ['subject_type' => 'course', 'subject_id' => $courseId]);
    $removed += $storage->delete('availability_rule_refs', ['owner_type' => 'course', 'owner_id' => $courseId]);
    $removed += $storage->delete('notification_queue', ['subject_type' => 'course', 'subject_id' => $courseId]);

    foreach (['certificates', 'enrolments', 'entitlements', 'activity_log', 'course_instructors'] as $table) {
        $removed += $storage->delete($table, ['course_id' => $courseId]);
    }

    $removed += $storage->delete('lessons', ['course_id' => $courseId]);
    $removed += $storage->delete('modules', ['course_id' => $courseId]);
    $removed += $storage->delete('courses', ['id' => $courseId]);

    return $removed;
}

/**
 * @return array<string, mixed>|null
 */
function row(DatabasePort $database, string $table, string $column, mixed $value): ?array
{
    return $database->selectOne(Query::from($table)->where($column, '=', $value));
}

// ---------------------------------------------------------------------------
// Fixture identity
// ---------------------------------------------------------------------------

const COURSE_SLUG = 'vidyalms-smoke-course';
const SECOND_COURSE_SLUG = 'vidyalms-smoke-other-course';

/**
 * A third course, for the section that proves a gated quiz refuses an attempt.
 *
 * It needs an enrolment that is still active, and the spine's is completed by
 * the time that section runs, so it cannot borrow the spine's course.
 */
const GATED_COURSE_SLUG = 'vidyalms-smoke-gated-course';

/**
 * A fourth course, for the seat contract.
 *
 * It needs a course nothing else in this run has finished, released to two
 * throwaway organisations and carrying a quiz the contract authors, so it
 * cannot borrow the spine's course either.
 */
const SEAT_COURSE_SLUG = 'vidyalms-smoke-seat-course';

/**
 * A fifth course, for the free AI build.
 *
 * Its own course because the AI section runs a reading level check over the
 * text it holds and accepts one generated item into a quiz of its own, and
 * because a course removed at the end of the section is the cheapest way to be
 * sure no generated correct answer is left on the site.
 */
const AI_COURSE_SLUG = 'vidyalms-smoke-ai-course';
/**
 * A sixth course, for the gradebook and the scheduled report.
 *
 * Its own course for two reasons. The gradebook section enrols the learner and
 * marks an assignment, and the spine's enrolment is already completed, so it
 * cannot borrow it. And the schedule it saves names a course in its stored
 * parameters, so a course removed at the end of the section is the cheapest way
 * to be sure no schedule is left pointing at rows this run created.
 */
const GRADEBOOK_COURSE_SLUG = 'vidyalms-smoke-gradebook-course';

/**
 * The catalogue section's own course and accounts.
 *
 * It used to reuse the seat section's, and the seat section deletes its four
 * accounts and removes its course two hundred lines earlier, so every write
 * 10y made was refused: "The CMS account is unavailable", then "Only a
 * published course may be released", then a cascade of fourteen. The Joomla
 * harness seeds its own for this section and this one now does too.
 */
const CATALOGUE_COURSE_SLUG = 'vidyalms-smoke-catalogue-course';
const CATALOGUE_ADMIN_A_LOGIN = 'vidyalms_smoke_cat_admin_a';
const CATALOGUE_ADMIN_A_EMAIL = 'vidyalms.smoke.cat.admin.a@example.invalid';
const CATALOGUE_ADMIN_B_LOGIN = 'vidyalms_smoke_cat_admin_b';
const CATALOGUE_ADMIN_B_EMAIL = 'vidyalms.smoke.cat.admin.b@example.invalid';
const CATALOGUE_LEARNER_ONE_LOGIN = 'vidyalms_smoke_cat_learner_one';
const CATALOGUE_LEARNER_ONE_EMAIL = 'vidyalms.smoke.cat.learner.one@example.invalid';
const CATALOGUE_LEARNER_TWO_LOGIN = 'vidyalms_smoke_cat_learner_two';
const CATALOGUE_LEARNER_TWO_EMAIL = 'vidyalms.smoke.cat.learner.two@example.invalid';

/** @var list<string> */
const CATALOGUE_ACCOUNT_LOGINS = [
    CATALOGUE_ADMIN_A_LOGIN,
    CATALOGUE_ADMIN_B_LOGIN,
    CATALOGUE_LEARNER_ONE_LOGIN,
    CATALOGUE_LEARNER_TWO_LOGIN,
];
const SEAT_ADMIN_A_LOGIN = 'vidyalms_smoke_seat_admin_a';
const SEAT_ADMIN_A_EMAIL = 'vidyalms.smoke.seat.admin.a@example.invalid';
const SEAT_ADMIN_B_LOGIN = 'vidyalms_smoke_seat_admin_b';
const SEAT_ADMIN_B_EMAIL = 'vidyalms.smoke.seat.admin.b@example.invalid';
const SEAT_LEARNER_ONE_LOGIN = 'vidyalms_smoke_seat_learner_one';
const SEAT_LEARNER_ONE_EMAIL = 'vidyalms.smoke.seat.learner.one@example.invalid';
const SEAT_LEARNER_TWO_LOGIN = 'vidyalms_smoke_seat_learner_two';
const SEAT_LEARNER_TWO_EMAIL = 'vidyalms.smoke.seat.learner.two@example.invalid';

/** @var list<string> */
const SEAT_ACCOUNT_LOGINS = [
    SEAT_ADMIN_A_LOGIN,
    SEAT_ADMIN_B_LOGIN,
    SEAT_LEARNER_ONE_LOGIN,
    SEAT_LEARNER_TWO_LOGIN,
];
const INSTRUCTOR_LOGIN = 'vidyalms_smoke_instructor';
const INSTRUCTOR_EMAIL = 'vidyalms.smoke.instructor@example.invalid';
const LEARNER_LOGIN = 'vidyalms_smoke_learner';
const LEARNER_EMAIL = 'vidyalms.smoke@example.invalid';
const MANAGER_LOGIN = 'vidyalms_smoke_manager';
const MANAGER_EMAIL = 'vidyalms.smoke.manager@example.invalid';
const SUBSCRIBER_LOGIN = 'vidyalms_smoke_subscriber';
const SUBSCRIBER_EMAIL = 'vidyalms.smoke.subscriber@example.invalid';
const MANAGER_ROLE = 'vidyalms_smoke_manager_role';
const CERTIFICATE_IDEMPOTENCY_PREFIX = 'vidyalms-smoke-certificate-';

/** Remove only retry receipts whose keys this smoke harness minted. */
function removeSmokeCertificateReceipts(DatabasePort $storage): int
{
    $removed = 0;
    $receipts = $storage->selectAll(
        Query::from('idempotency_keys')->whereContains('idem_key', CERTIFICATE_IDEMPOTENCY_PREFIX),
    );

    foreach ($receipts as $receipt) {
        $key = (string) ($receipt['idem_key'] ?? '');
        $scope = (string) ($receipt['scope'] ?? '');

        if ($scope !== '' && str_starts_with($key, CERTIFICATE_IDEMPOTENCY_PREFIX)) {
            $removed += $storage->delete('idempotency_keys', ['scope' => $scope, 'idem_key' => $key]);
        }
    }

    return $removed;
}

function userIdByLogin(string $login): int
{
    $user = get_user_by('login', $login);

    return $user instanceof WP_User ? (int) $user->ID : 0;
}

/**
 * WordPress caches capabilities on the WP_User object and in the object cache,
 * so a role created or changed inside this process is invisible to a user
 * loaded before it. Every capability check below re-reads the user, or the test
 * would pass for the wrong reason.
 */
function freshUser(int $userId): ?WP_User
{
    clean_user_cache($userId);
    wp_cache_delete($userId, 'user_meta');

    $user = get_userdata($userId);

    return $user instanceof WP_User ? $user : null;
}

function makeUser(string $login, string $email, string $role, string $displayName): int
{
    $existing = userIdByLogin($login);

    if ($existing > 0) {
        return $existing;
    }

    $id = wp_insert_user([
        'user_login' => $login,
        'user_email' => $email,
        'user_pass' => wp_generate_password(24, true, true),
        'display_name' => $displayName,
        'role' => $role,
    ]);

    if (is_wp_error($id)) {
        fwrite(STDERR, 'Could not create ' . $login . ': ' . $id->get_error_message() . "\n");
        exit(1);
    }

    return (int) $id;
}

function removeUser(string $login): string
{
    $id = userIdByLogin($login);

    if ($id <= 0) {
        return 'not present';
    }

    require_once ABSPATH . 'wp-admin/includes/user.php';

    return wp_delete_user($id) ? 'deleted ' . $id : 'FAILED to delete ' . $id;
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

if ($cleanup) {
    heading('VidyaLMS WordPress smoke cleanup');

    $database = Plugin::database();

    foreach (
        [COURSE_SLUG, SECOND_COURSE_SLUG, GATED_COURSE_SLUG, SEAT_COURSE_SLUG,
        AI_COURSE_SLUG, GRADEBOOK_COURSE_SLUG, CATALOGUE_COURSE_SLUG] as $slug
    ) {
        $course = row($database, 'courses', 'slug', $slug);

        if ($course === null) {
            step('course ' . $slug, 'not present');

            continue;
        }

        $courseId = (int) $course['id'];

        // One list of tables, shared with the gated-quiz section, which
        // removes its own course as soon as it is finished with it. A table
        // added to one list and not the other is a leak nobody notices until a
        // later run trips over the row.
        step(
            'deleted course ' . $slug,
            removeSmokeCourse($database, $courseId) . ' row(s), permalink shell included'
        );
    }

    // The seat contract removes its own organisations, pools and ledger rows
    // inside its own finally, so all that can survive a run that died inside
    // it is the four accounts and anything addressed to them.
    $seatStranded = 0;

    foreach ($database->selectAll(Query::from('organisations')
        ->whereContains('slug', \Shondalai\VidyaLMS\Tooling\Contract\OrganisationSeatContract::MARKER)) as $strandedOrg) {
        $strandedId = (int) $strandedOrg['id'];

        foreach (['seat_events', 'seat_assignments', 'seat_pools', 'invitations', 'org_memberships',
            'org_membership_events', 'org_course_releases', 'admin_access_log', 'org_learning_locks'] as $strandedTable) {
            $seatStranded += $database->delete($strandedTable, ['organisation_id' => $strandedId]);
        }

        $seatStranded += $database->delete('organisations', ['id' => $strandedId]);
    }

    step('deleted stranded seat contract rows', $seatStranded . ' row(s)');

    // The import contract removes its own rows in its own finally too, so this
    // catches only a run that died inside it. Jobs are found by the harness
    // importer's key, which no shipped importer answers to, and their row
    // problems go first because they name the job.
    $importStranded = 0;

    foreach ($database->selectAll(Query::from('import_jobs')->select(['id'])
        ->where('importer', '=', \Shondalai\VidyaLMS\Tooling\Contract\SmokeOrganisationRosterImporter::KEY)) as $strandedJob) {
        $importStranded += $database->delete('import_row_problems', ['import_job_id' => (int) $strandedJob['id']]);
        $importStranded += $database->delete('import_jobs', ['id' => (int) $strandedJob['id']]);
    }

    $importStranded += $database->delete(
        'import_map',
        ['importer' => \Shondalai\VidyaLMS\Tooling\Contract\SmokeOrganisationRosterImporter::KEY],
    );

    foreach ($database->selectAll(Query::from('organisations')
        ->whereContains('slug', \Shondalai\VidyaLMS\Tooling\Contract\OrganisationImportContract::MARKER)) as $strandedOrg) {
        $strandedImportOrg = (int) $strandedOrg['id'];

        foreach (['org_membership_events', 'org_memberships'] as $strandedTable) {
            $importStranded += $database->delete($strandedTable, ['organisation_id' => $strandedImportOrg]);
        }

        $importStranded += $database->delete('organisations', ['id' => $strandedImportOrg]);
    }

    step('deleted stranded import contract rows', $importStranded . ' row(s)');

    foreach (SEAT_ACCOUNT_LOGINS as $seatLogin) {
        step('seat contract account ' . $seatLogin, removeUser($seatLogin));
    }

    foreach (CATALOGUE_ACCOUNT_LOGINS as $catalogueLogin) {
        step('catalogue contract account ' . $catalogueLogin, removeUser($catalogueLogin));
    }

    foreach (['ada', 'grace', 'alan'] as $rosterGiven) {
        $rosterLogin = str_replace(
            '-',
            '_',
            $rosterGiven . '_' . \Shondalai\VidyaLMS\Tooling\Contract\OrganisationIdentityContract::MARKER
        );
        step('roster contract account ' . $rosterLogin, removeUser($rosterLogin));
    }

    // The certificate template the credentials section creates, removed by the
    // key it mints rather than by a pattern, so nothing a site owner authored
    // can go with it. It is normally removed inside that section; this covers
    // a run that died between creating it and getting there.
    foreach (
        $database->selectAll(Query::from('certificate_templates')->where('template_key', '=', 'vidyalms.smoke.template'))
        as $smokeTemplate
    ) {
        $smokeTemplateId = (int) $smokeTemplate['id'];

        foreach (
            $database->selectAll(Query::from('certificate_template_pages')->where('template_id', '=', $smokeTemplateId))
            as $smokePage
        ) {
            $database->delete('certificate_template_elements', ['page_id' => (int) $smokePage['id']]);
            $database->delete('certificate_template_pages', ['id' => (int) $smokePage['id']]);
        }

        step(
            'deleted smoke certificate template',
            $database->delete('certificate_templates', ['id' => $smokeTemplateId]) . ' row(s)'
        );
    }

    step('deleted certificate retry receipts', removeSmokeCertificateReceipts($database) . ' row(s)');

    // The envelope sender section's own rows, addressed by the keys it mints
    // rather than by a pattern, so nothing another run wrote can go with them.
    $envelopeRows = Plugin::database()->delete('notification_queue', array('trigger_key' => 'vidyalms.smoke.envelope'))
        + Plugin::database()->delete('email_log', array('template_key' => 'vidyalms.smoke.envelope'))
        + Plugin::database()->delete('notification_templates', array('template_key' => 'vidyalms.smoke.envelope'));

    step('deleted smoke envelope rows', $envelopeRows . ' row(s)');

    foreach ([LEARNER_LOGIN, MANAGER_LOGIN, SUBSCRIBER_LOGIN, INSTRUCTOR_LOGIN] as $login) {
        $id = userIdByLogin($login);

        if ($id > 0) {
            Plugin::database()->delete('email_log', ['user_id' => $id]);
            Plugin::database()->delete('activity_log', ['user_id' => $id]);
            Plugin::database()->delete('notification_queue', ['recipient_user_id' => $id]);
        }

        step('learner/manager ' . $login, removeUser($login));
    }

    if (get_role(MANAGER_ROLE) !== null) {
        remove_role(MANAGER_ROLE);
        step('removed the throwaway role', MANAGER_ROLE);
    }

    $jobs = 0;

    foreach (Plugin::database()->selectAll(Query::from(WpScheduler::TABLE)) as $job) {
        if (str_starts_with((string) ($job['dedupe_key'] ?? ''), 'vidyalms-smoke-')) {
            $jobs += Plugin::database()->delete(WpScheduler::TABLE, ['id' => (int) $job['id']]);
        }
    }

    step('deleted seeded job rows', $jobs . ' row(s)');

    /* The same sweep the run itself ends with, so a cleanup that removes a
       fixture cannot leave a child row waiting for the next run to notice. */
    $cleanupSweep = sweepOrphanedChildren(Plugin::database(), orphanParents());

    step(
        'orphaned child rows swept by parent',
        $cleanupSweep === [] ? 'none' : implode(', ', array_map(
            static fn (string $table, array $ids): string => $table . ' ' . implode('/', $ids),
            array_keys($cleanupSweep),
            $cleanupSweep,
        )),
    );

    heading('Cleanup finished');

    exit(0);
}

// ---------------------------------------------------------------------------
// 1. Kernel and schema
// ---------------------------------------------------------------------------

global $wpdb;

heading('VidyaLMS WordPress adapter smoke test');
out('  wordpress : ' . $wpRoot . ' (' . get_bloginfo('version') . ')');
out('  database  : ' . DB_NAME . ' prefix ' . $wpdb->prefix);
out('  php       : ' . PHP_VERSION);

heading('1. Kernel and schema');

$kernel = vidyalms_kernel();
step('CoreKernel from vidyalms_kernel()', get_class($kernel));

// The same object every shared contract body is handed. It was used by five
// sections below and bound by none of them, so a run reached the first of
// those and died on a null: this file is written and rehearsed offline and
// every live run of it is deferred, which is exactly how that survived.
$ports = Plugin::ports();

assertThat(
    $kernel instanceof CoreKernel && vidyalms_kernel() === $kernel && Plugin::kernel() === $kernel,
    'the kernel is a singleton, not rebuilt per call',
    'vidyalms_kernel(), Plugin::kernel() and the first call are the same object'
);

assertThat(
    apply_filters('vidyalms_kernel', null) === $kernel,
    'the vidyalms_kernel filter answers with the same instance',
    'apply_filters returned the shared kernel'
);

$database = Plugin::database();

assertThat(
    apply_filters('vidyalms_database', null) === $database,
    'the vidyalms_database filter answers with the same instance',
    'apply_filters returned the shared database port'
);

// A failure here means the activator has not run. Deactivate and reactivate the
// plugin, which installs and migrates the schema. Do not create these tables by
// hand and do not have this script create them: nothing in this repo issues DDL
// outside this run.
$tables = (array) $wpdb->get_col(
    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'vidyalms_') . '%')
);

assertThat(
    count($tables) >= 29,
    'the schema is installed',
    count($tables) . ' vidyalms_ tables, including ' . $wpdb->prefix . 'vidyalms_wp_jobs'
);

assertThat(
    in_array($wpdb->prefix . 'vidyalms_wp_jobs', $tables, true),
    'the adapter job queue table exists',
    $wpdb->prefix . 'vidyalms_wp_jobs'
);

step('verification base url', $kernel->verificationBaseUrl);

// ---------------------------------------------------------------------------
// 2. Actors
// ---------------------------------------------------------------------------

heading('2. Actors');

if ($actorId <= 0) {
    $administrators = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID']);
    $actorId = (int) ($administrators[0] ?? 0);
}

if ($actorId <= 0) {
    fwrite(STDERR, "No administrator to act as the author; pass --actor=<id>.\n");
    exit(1);
}

$actor = Principal::user($actorId);
step('author principal', 'user ' . $actorId . ' (' . (Plugin::ports()->users->findById($actorId)?->name ?? '?') . ')');

assertThat(
    Plugin::ports()->authorization->can($actorId, Capability::TEACH),
    'the author holds vidyalms.teach through WordPress capabilities',
    'AuthorizationPort::can returned true'
);

$learnerId = makeUser(LEARNER_LOGIN, LEARNER_EMAIL, 'subscriber', 'VidyaLMS Smoke Learner');
$learner = Principal::user($learnerId);
step('learner account', LEARNER_LOGIN . ' (id ' . $learnerId . ')');

// ---------------------------------------------------------------------------
// 3. Curriculum
// ---------------------------------------------------------------------------

heading('3. Curriculum');

if (row($database, 'courses', 'slug', COURSE_SLUG) !== null) {
    fwrite(STDERR, "\nThe smoke course already exists. Run with --cleanup first.\n");
    exit(1);
}

$course = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke course',
    COURSE_SLUG,
    'A course created by build/smoke.php to prove the adapter works end to end.',
    '<p>Seeded by the VidyaLMS WordPress adapter smoke test.</p>',
);

step('CreateCourse', 'course ' . $course->id . ' slug ' . $course->slug . ' state ' . $course->state->value);

$module = $kernel->addModule->execute($actor, $course->id, 'Getting started');
step('AddModule', 'module ' . $module->id . ' position ' . $module->position);

// Two lessons, so a heartbeat on the first can be replayed while the course is
// still incomplete: that is what proves upsert dedupes rather than piling rows.
$lesson = $kernel->addLesson->execute(
    $actor,
    $module->id,
    'What VidyaLMS is',
    'reading',
    ['body' => '<p>One learning engine, two thin shells.</p>'],
);
step('AddLesson (reading)', 'lesson ' . $lesson->id . ' slug ' . $lesson->slug);

$second = $kernel->addLesson->execute(
    $actor,
    $module->id,
    'How the ports fit together',
    'reading',
    ['body' => '<p>The adapter translates and wires; it never re-implements a rule.</p>'],
);
step('AddLesson (reading)', 'lesson ' . $second->id . ' slug ' . $second->slug);

// Kept under its own name because $second is reused later in this run for a
// drain result, and a later section that read $second->id off an array would
// gate lesson 0 and report the route missing rather than the mistake.
$secondLessonId = (int) $second->id;

// PublishCourse dispatches CoursePublished, WpEventBridge turns it into
// vidyalms/course_published, and Content\CourseSync is listening: nothing here
// fires an action, and the permalink shell appears anyway. That is the whole
// point of the change, so the assertion below is what proves it.
$published = $kernel->publishCourse->execute($actor, $course->id);

assertThat(
    $published->isPublished(),
    'PublishCourse moved the course to published',
    'state=' . $published->state->value . ' published_at=' . ($published->publishedAt?->format('Y-m-d H:i:s') ?? 'null')
);

$permalink = \Shondalai\VidyaLMS\WordPress\Content\CourseCpt::permalink($course->id, (string) $course->slug);

assertThat(
    \Shondalai\VidyaLMS\WordPress\Content\CourseCpt::shellIdForCourse($course->id) > 0,
    'the course event alone created the permalink shell',
    'post ' . \Shondalai\VidyaLMS\WordPress\Content\CourseCpt::shellIdForCourse($course->id) . ' at ' . $permalink
);

// The shell follows a rename too, and again with nothing but the event: an
// adapter that only reacted to publishing would leave the old URL behind.
$renamed = $kernel->updateCourse->execute($actor, $course->id, ['title' => 'VidyaLMS smoke course (renamed)']);
$renamedShell = get_post(\Shondalai\VidyaLMS\WordPress\Content\CourseCpt::shellIdForCourse($course->id));

assertThat(
    $renamedShell instanceof WP_Post && $renamedShell->post_title === 'VidyaLMS smoke course (renamed)',
    'the course event alone renamed the permalink shell',
    'post title ' . ($renamedShell instanceof WP_Post ? '"' . $renamedShell->post_title . '"' : 'missing')
);

$kernel->updateCourse->execute($actor, $course->id, ['title' => $published->title]);

// ---------------------------------------------------------------------------
// 4. Access
// ---------------------------------------------------------------------------

heading('4. Access');

$entitlement = $kernel->grantEntitlement->execute(
    $actor,
    $learnerId,
    $course->id,
    Entitlement::SOURCE_MANUAL,
    'smoke-test'
);
step('GrantEntitlement', 'entitlement ' . $entitlement->id() . ' source ' . $entitlement->source);

$enrolment = null;

foreach ($kernel->listMyEnrolments->execute($learner) as $candidate) {
    if ((int) $candidate['course_id'] === $course->id) {
        $enrolment = $candidate;
    }
}

assertThat(
    $enrolment !== null,
    'granting an entitlement created the enrolment',
    $enrolment === null ? 'no enrolment row' : 'enrolment ' . $enrolment['id'] . ' status ' . $enrolment['status']
);

if ($enrolment === null) {
    exit(1);
}

$enrolmentId = (int) $enrolment['id'];

// ---------------------------------------------------------------------------
// 5. Progress and completion
// ---------------------------------------------------------------------------

heading('5. Progress and completion');

// Three identical heartbeats on an incomplete course: WpDatabase::upsert has to
// collapse them onto one row through the unique key, exactly as the SQLite
// harness and the Joomla adapter do.
for ($beat = 0; $beat < 3; $beat++) {
    $kernel->recordLessonProgress->execute($learner, $enrolmentId, $lesson->id, progressPercent: 40.0, secondsDelta: 30);
}

$progressRows = $database->count(
    Query::from('lesson_progress')->where('enrolment_id', '=', $enrolmentId)->where('lesson_id', '=', $lesson->id)
);

assertThat(
    $progressRows === 1,
    'upsert deduped three replayed heartbeats',
    'lesson_progress rows = ' . $progressRows
);

$heartbeat = row($database, 'lesson_progress', 'enrolment_id', $enrolmentId);

assertThat(
    (int) ($heartbeat['seconds_spent'] ?? 0) === 90,
    'the replays accumulated rather than overwrote',
    'seconds_spent=' . ($heartbeat['seconds_spent'] ?? 'null')
);

$midway = row($database, 'enrolments', 'id', $enrolmentId);

assertThat(
    ($midway['status'] ?? '') === 'active',
    'one lesson of two leaves the enrolment active',
    'status=' . ($midway['status'] ?? 'null') . ' percent=' . ($midway['progress_percent'] ?? 'null')
);

$kernel->recordLessonProgress->execute($learner, $enrolmentId, $lesson->id, completed: true);
$kernel->recordLessonProgress->execute($learner, $enrolmentId, $second->id, completed: true);
step('RecordLessonProgress (both lessons completed)', 'lessons ' . $lesson->id . ' and ' . $second->id);

$after = row($database, 'enrolments', 'id', $enrolmentId);

assertThat(
    ($after['status'] ?? '') === 'completed',
    'the enrolment completed',
    'status=' . ($after['status'] ?? 'null') . ' completed_at=' . ($after['completed_at'] ?? 'null')
);

assertThat(
    (float) ($after['progress_percent'] ?? 0) === 100.0,
    'progress reached 100 percent',
    'progress_percent=' . ($after['progress_percent'] ?? 'null')
);

// A completed enrolment stays open for review: the heartbeat is accepted, but
// the guarded completion update matched exactly one row the first time, so the
// enrolment stays terminal, completed_at does not move, and no second
// CourseCompleted fires (the exactly-once check below runs after this replay).
$kernel->recordLessonProgress->execute($learner, $enrolmentId, $second->id, completed: true);
$reviewed = row($database, 'enrolments', 'id', $enrolmentId);

assertThat(
    ($reviewed['status'] ?? '') === 'completed'
        && ($reviewed['completed_at'] ?? null) === ($after['completed_at'] ?? null),
    'a replay after completion is accepted for review, not double-counted',
    'status=' . ($reviewed['status'] ?? 'null') . ' completed_at=' . ($reviewed['completed_at'] ?? 'null')
);

// ---------------------------------------------------------------------------
// 6. Credentials
// ---------------------------------------------------------------------------

heading('6. Credentials');

$certificates = $database->selectAll(Query::from('certificates')->where('enrolment_id', '=', $enrolmentId));

assertThat(
    count($certificates) === 1,
    'exactly one certificate was issued',
    'certificate rows = ' . count($certificates)
);

$completedEvents = $database->count(
    Query::from('activity_log')
        ->where('enrolment_id', '=', $enrolmentId)
        ->where('action', '=', 'vidyalms.course.completed')
);

assertThat(
    $completedEvents === 1,
    'CourseCompleted was emitted exactly once',
    'activity_log rows = ' . $completedEvents
);

$code = (string) ($certificates[0]['code'] ?? '');

assertThat($code !== '', 'the certificate carries a verification code', 'code=' . $code);

$verification = $kernel->verifyCertificate->execute(Principal::guest(), $code);

assertThat(
    $verification->valid,
    'VerifyCertificate answers valid for that code',
    'valid=' . var_export($verification->valid, true)
        . ' course=' . ($verification->courseTitle ?? 'null')
        . ' learner=' . ($verification->learnerName ?? 'null')
);

$unknown = $kernel->verifyCertificate->execute(Principal::guest(), 'NOTACODE');

assertThat(
    !$unknown->valid && $unknown->reason === 'not_found',
    'an unknown code answers not_found rather than throwing',
    'reason=' . ($unknown->reason ?? 'null')
);

// ---------------------------------------------------------------------------
// 7. Catalogue
// ---------------------------------------------------------------------------

heading('7. Catalogue');

$page = $kernel->getCatalogue->execute(Principal::guest(), 'smoke', null, 1, 12);

assertThat(
    $page->total >= 1,
    'GetCatalogue finds the published course',
    'total=' . $page->total . ' first=' . ($page->items[0]->title ?? 'none')
);

step('email_log rows for the learner', (string) $database->count(
    Query::from('email_log')->where('user_id', '=', $learnerId)
));

// ---------------------------------------------------------------------------
// 8. Private storage and signed URLs
// ---------------------------------------------------------------------------

heading('8. Private storage and signed URLs');

$storage = Plugin::storage();

// The name carries a space and a percent sign on purpose. Both are rewritten by
// the URL encoding on the way out, so a reader that decodes one layer too many
// or one too few produces a different path, the HMAC stops matching, and every
// certificate link 403s. A plain name would pass either way.
$filePath = 'smoke/' . gmdate('Ymd-His') . ' 100%.txt';
$body = "VidyaLMS smoke file.\n";

$storage->putPrivate($filePath, $body);

assertThat(
    is_file($storage->privatePath($filePath)),
    'putPrivate wrote inside the private root',
    $storage->privatePath($filePath)
);

$signed = $storage->signedUrl($filePath, 300);
parse_str((string) parse_url($signed, PHP_URL_QUERY), $query);

assertThat(
    ($query['action'] ?? '') === WpStorage::DOWNLOAD_ACTION,
    'the signed URL points at the download endpoint',
    'action=' . ($query['action'] ?? 'null')
);

// parse_str decodes exactly as PHP does into $_GET, so this is the value
// Files\DownloadEndpoint reads. It must already be the path, with no further
// decoding: WpStorage encodes once and add_query_arg leaves new arguments
// alone.
$decodedPath = (string) ($query[WpStorage::QUERY_PATH] ?? '');

assertThat(
    $decodedPath === $filePath,
    'the path arrives undecoded exactly once',
    'received=' . $decodedPath
);

assertThat(
    WpStorage::verifySignature($decodedPath, (int) ($query[WpStorage::QUERY_EXPIRES] ?? 0), (string) ($query[WpStorage::QUERY_SIGNATURE] ?? '')),
    'the signature verifies, so the endpoint would serve it',
    'expires=' . ($query[WpStorage::QUERY_EXPIRES] ?? 'null')
);

assertThat(
    !WpStorage::verifySignature($decodedPath, (int) ($query[WpStorage::QUERY_EXPIRES] ?? 0), (string) ($query[WpStorage::QUERY_SIGNATURE] ?? '') . '0'),
    'a tampered signature is rejected',
    'hash_equals returned false'
);

assertThat(
    !WpStorage::verifySignature($decodedPath, time() - 60, (string) ($query[WpStorage::QUERY_SIGNATURE] ?? '')),
    'an expired link is rejected',
    'expiry in the past refused'
);

assertThat(
    $storage->readPrivate($filePath) === $body,
    'the private file reads back byte for byte',
    'readPrivate matched ' . strlen($body) . ' bytes'
);

assertThat(
    has_action('admin_post_' . WpStorage::DOWNLOAD_ACTION) !== false
        && has_action('admin_post_nopriv_' . WpStorage::DOWNLOAD_ACTION) !== false,
    'the download endpoint is registered for signed in and logged out callers',
    'admin_post_ and admin_post_nopriv_' . WpStorage::DOWNLOAD_ACTION
);

$storage->delete($filePath);

assertThat(!$storage->exists($filePath), 'the private file was removed', 'exists() is false');

// ---------------------------------------------------------------------------
// 9. Job queue
// ---------------------------------------------------------------------------

heading('9. Job queue');

$queueSuffix = gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
$dedupe = 'vidyalms-smoke-job-' . $queueSuffix;

// A busy development site may have thousands of legitimate due jobs. Give
// this collision-resistant, test-owned row a fixed ancient instant so it is
// deterministically first, then ask the runner for exactly one row.
$smokeQueueRunAt = new DateTimeImmutable('1970-01-02 00:00:00', new DateTimeZone('UTC'));
$task = new ScheduledTask(
    'vidyalms.entitlements.expire',
    ['actor_user_id' => $actorId],
    $smokeQueueRunAt,
    $dedupe
);

Plugin::scheduler()->schedule($task);

$queued = row($database, WpScheduler::TABLE, 'dedupe_key', $dedupe);

assertThat(
    $queued !== null && ($queued['status'] ?? '') === WpScheduler::STATUS_PENDING,
    'the scheduler port wrote a pending job row',
    'job ' . ($queued['id'] ?? 'none') . ' status ' . ($queued['status'] ?? 'null')
);

// Rescheduling the same key replaces the row rather than adding a second, which
// is what keeps a re-queued drip release from firing twice.
Plugin::scheduler()->schedule($task);

assertThat(
    $database->count(Query::from(WpScheduler::TABLE)->where('dedupe_key', '=', $dedupe)) === 1,
    'rescheduling a keyed task upserts rather than duplicates',
    'rows for the dedupe key = ' . $database->count(Query::from(WpScheduler::TABLE)->where('dedupe_key', '=', $dedupe))
);

$drained = drainSmokeOwnedBatch(1);
$ranJob = row($database, WpScheduler::TABLE, 'dedupe_key', $dedupe);

assertThat(
    ($ranJob['status'] ?? '') === WpScheduler::STATUS_DONE,
    'the queue runner claimed and finished the job',
    'status=' . ($ranJob['status'] ?? 'null') . ' attempts=' . ($ranJob['attempts'] ?? 'null')
        . ' claimed=' . $drained['claimed'] . ' done=' . $drained['done'] . ' failed=' . $drained['failed']
);

assertThat(
    $ranJob !== null && $ranJob['last_error'] === null,
    'a finished job carries no error',
    'last_error = ' . ($ranJob === null ? 'row missing' : var_export($ranJob['last_error'], true))
);

// A second pass must not take this row again: it is no longer pending, and the
// claim is a conditional update on that status. Put one new test-owned guard
// row first and bound the pass to one, so the pass is real without consuming a
// single row from the site's unrelated backlog.
$secondPassDedupe = 'vidyalms-smoke-job-second-' . $queueSuffix;
Plugin::scheduler()->schedule(new ScheduledTask(
    'vidyalms.entitlements.expire',
    ['actor_user_id' => $actorId],
    $smokeQueueRunAt,
    $secondPassDedupe
));

$second = drainSmokeOwnedBatch(1);
$afterSecond = row($database, WpScheduler::TABLE, 'dedupe_key', $dedupe);
$secondPassRow = row($database, WpScheduler::TABLE, 'dedupe_key', $secondPassDedupe);

assertThat(
    ($afterSecond['status'] ?? '') === WpScheduler::STATUS_DONE
        && (int) ($afterSecond['attempts'] ?? 0) === (int) ($ranJob['attempts'] ?? -1)
        && ($secondPassRow['status'] ?? '') === WpScheduler::STATUS_DONE
        && $second['claimed'] === 1,
    'a finished job is not claimed again',
    'status=' . ($afterSecond['status'] ?? 'null')
        . ' attempts=' . ($afterSecond['attempts'] ?? 'null')
        . ' (was ' . ($ranJob['attempts'] ?? 'null') . '), controlled second pass claimed '
        . $second['claimed'] . ' guard row(s), guard status=' . ($secondPassRow['status'] ?? 'null')
);

$database->delete(WpScheduler::TABLE, ['dedupe_key' => $dedupe]);
$database->delete(WpScheduler::TABLE, ['dedupe_key' => $secondPassDedupe]);

// ---------------------------------------------------------------------------
// 10. Authorization parity
// ---------------------------------------------------------------------------

heading('10. Authorization parity');

// A role holding manage_options and nothing of ours must be able to work every
// operational screen, because that is what "can administer this WordPress site"
// means. It must not reach vidyalms.admin, which is the plugin's own top
// privilege. This is the WordPress half of the implication the Joomla adapter
// supplies for core.manage.
if (get_role(MANAGER_ROLE) === null) {
    add_role(MANAGER_ROLE, 'VidyaLMS smoke manager', ['read' => true, 'manage_options' => true]);
}

$managerId = makeUser(MANAGER_LOGIN, MANAGER_EMAIL, MANAGER_ROLE, 'VidyaLMS Smoke Manager');
$subscriberId = makeUser(SUBSCRIBER_LOGIN, SUBSCRIBER_EMAIL, 'subscriber', 'VidyaLMS Smoke Subscriber');

$manager = freshUser($managerId);
$subscriber = freshUser($subscriberId);

step('throwaway manager', MANAGER_LOGIN . ' (id ' . $managerId . ') role ' . MANAGER_ROLE);

assertThat(
    $manager instanceof WP_User && $manager->has_cap('manage_options') && !$manager->has_cap(Capabilities::ADMIN),
    'the manager holds manage_options and no vidyalms capability of its own',
    'manage_options=true vidyalms_admin=false'
);

$authorization = Plugin::ports()->authorization;

foreach ([
    'course authoring' => Capability::TEACH,
    'grading' => Capability::GRADE,
    'enrolling others' => Capability::ENROL_OTHERS,
    'reports' => Capability::VIEW_REPORTS,
    'management' => Capability::MANAGE,
] as $screen => $capability) {
    assertThat(
        $authorization->can($managerId, $capability),
        'manage_options reaches ' . $screen,
        $capability . ' = true'
    );
}

assertThat(
    !$authorization->can($managerId, Capability::ADMIN),
    'manage_options does NOT grant vidyalms.admin',
    'vidyalms.admin = false'
);

assertThat(
    $subscriber instanceof WP_User && !$authorization->can($subscriberId, Capability::VIEW_REPORTS)
        && !$authorization->can($subscriberId, Capability::TEACH),
    'a plain subscriber is refused',
    'vidyalms.reports and vidyalms.teach = false for user ' . $subscriberId
);

assertThat(
    !$authorization->can(0, Capability::VIEW_REPORTS),
    'a guest is refused',
    'user id 0 = false'
);

assertThat(
    Capabilities::wordpressCapability(Capability::TEACH) === Capabilities::TEACH,
    'the core key maps to the WordPress capability the menu gates on',
    Capability::TEACH . ' -> ' . Capabilities::TEACH
);

// ---------------------------------------------------------------------------
// 10b. Per-course teaching
// ---------------------------------------------------------------------------

heading('10b. Per-course teaching');

// The workbench is reachable by anyone holding one of the operational
// capabilities, and what they may then do is CourseAccess's answer, not the
// screen's. The vidyalms_instructor role activation creates holds teach,
// grade, enrol and reports and not vidyalms_manage, so it has to reach the
// courses it instructs and no others: without that, delegating teaching would
// hand over every course on the site.
$instructorId = makeUser(
    INSTRUCTOR_LOGIN,
    INSTRUCTOR_EMAIL,
    Capabilities::INSTRUCTOR_ROLE,
    'VidyaLMS Smoke Instructor'
);

$instructor = Principal::user($instructorId);

step('instructor', INSTRUCTOR_LOGIN . ' (id ' . $instructorId . ') role ' . Capabilities::INSTRUCTOR_ROLE);

assertThat(
    $authorization->can($instructorId, Capability::TEACH),
    'the instructor role answers the TEACH capability',
    'vidyalms.teach = true'
);

assertThat(
    !$authorization->can($instructorId, Capability::MANAGE),
    'the instructor role does NOT grant management',
    'vidyalms.manage = false'
);

assertThat(
    refused(static fn () => $kernel->getManagedCourse->execute($instructor, $course->id)),
    'a course the instructor is not assigned to is refused',
    'GetManagedCourse on course ' . $course->id . ' throws'
);

$kernel->assignInstructor->execute($actor, $course->id, $instructorId);
step('AssignInstructor', 'user ' . $instructorId . ' on course ' . $course->id);

$reached = null;

try {
    $reached = $kernel->getManagedCourse->execute($instructor, $course->id);
} catch (\Throwable) {
    $reached = null;
}

assertThat(
    $reached !== null && $reached->managed->course->id === $course->id,
    'the course the instructor was assigned to opens its workbench',
    'GetManagedCourse on course ' . $course->id . ' answers'
);

if (row($database, 'courses', 'slug', SECOND_COURSE_SLUG) !== null) {
    fwrite(STDERR, "\nThe second smoke course already exists. Run with --cleanup first.\n");
    exit(1);
}

$otherCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke second course',
    SECOND_COURSE_SLUG,
    'A second course, to prove per-course teaching is per course.'
);

step('CreateCourse (second)', 'course ' . $otherCourse->id . ' slug ' . $otherCourse->slug);

assertThat(
    refused(static fn () => $kernel->getManagedCourse->execute($instructor, $otherCourse->id)),
    'the other course stays refused to the same instructor',
    'GetManagedCourse on course ' . $otherCourse->id . ' throws'
);

assertThat(
    refused(static fn () => $kernel->addModule->execute($instructor, $otherCourse->id, 'Not mine')),
    'and so does writing to it',
    'AddModule on course ' . $otherCourse->id . ' throws'
);

// vidyalms_user_can_course is the seam a site narrows a per-course answer
// with. Nothing installs a listener by default, so the section also proves it
// is asked at all rather than only documented.
$asked = 0;
$deny = static function ($allowed, $userId, $capability, $courseId) use (&$asked, $instructorId, $course) {
    if ((int) $userId === $instructorId && (int) $courseId === $course->id) {
        ++$asked;

        return false;
    }

    return $allowed;
};

add_filter(WpAuthorization::COURSE_FILTER, $deny, 10, 4);

$refusedByFilter = refused(static fn () => $kernel->getManagedCourse->execute($instructor, $course->id));

remove_filter(WpAuthorization::COURSE_FILTER, $deny, 10);

assertThat(
    $asked > 0 && $refusedByFilter,
    'vidyalms_user_can_course can narrow a course the capability allowed',
    'filter asked ' . $asked . ' time(s), the course was refused'
);

$kernel->removeInstructor->execute($actor, $course->id, $instructorId);

assertThat(
    refused(static fn () => $kernel->getManagedCourse->execute($instructor, $course->id)),
    'removing the assignment closes the workbench again',
    'GetManagedCourse on course ' . $course->id . ' throws'
);

// ---------------------------------------------------------------------------
// 11. Public URLs
// ---------------------------------------------------------------------------

heading('11. Public URLs');

out('  course      : ' . $permalink);
out('  catalogue   : ' . \Shondalai\VidyaLMS\WordPress\Content\Rewrites::catalogueUrl());
out('  certificate : ' . rtrim($kernel->verificationBaseUrl, '/') . '/' . $code);
out('  rest        : ' . rest_url('vidyalms/v1/courses'));
out('  upload      : ' . rest_url(
    \Shondalai\VidyaLMS\WordPress\Rest\UploadController::REST_NAMESPACE
    . \Shondalai\VidyaLMS\WordPress\Rest\UploadController::ROUTE
));

// The shared port contract, the same assertions the core runs against its
// SQLite harness and the Joomla adapter runs against JoomlaDatabase. It writes
// inside a transaction it rolls back and then checks the rollback happened, so
// it leaves this site exactly as it found it. That check is worth more here
// than anywhere: the tables have to be InnoDB, and on MyISAM the transaction
// statements are accepted and do nothing. build/port-contract.php runs the
// same thing on its own when only this is in question.
heading('11b. Database port contract');

$contractFailures = \Shondalai\VidyaLMS\Tooling\Contract\DatabasePortContract::failures($database);

foreach ($contractFailures as $contractFailure) {
    out('    ' . $contractFailure);
}

assertThat(
    $contractFailures === [],
    'WpDatabase satisfies the database port contract',
    $contractFailures === []
        ? 'aggregates, conditional counts, rates, nulls last, OR groups, grouped count'
        : \count($contractFailures) . ' expectation(s) broken',
);

// Schema 1.1.0 on this site, and what it did to the attempts that were here
// before it. Read only, like the port contract above: it asserts on rows that
// already exist rather than seeding any, which is the only way to test the
// pre-manifest fallback at all, because an attempt written today gets a
// manifest.
heading('11c. Assessment foundation');

['failures' => $assessmentFailures, 'facts' => $assessmentFacts] =
    \Shondalai\VidyaLMS\Tooling\Contract\AssessmentFoundationContract::run($kernel, Plugin::ports());

foreach ($assessmentFacts as $fact) {
    out('    ' . $fact);
}

foreach ($assessmentFailures as $assessmentFailure) {
    out('    ' . $assessmentFailure);
}

assertThat(
    $assessmentFailures === [],
    'the free schema landed and the attempts here still read',
    $assessmentFailures === []
        ? 'four tables, the 1.1.0 and 1.3.0 columns, the pins, the manifest fallback, the task registry'
        : count($assessmentFailures) . ' expectation(s) broken'
);

// The schema version the plugin records is what decides whether a site ever
// runs dbDelta and the migrations again. A site whose files are newer than its
// option row is mid-upgrade and its columns may not be there.
assertThat(
    !Activator::needsUpgrade(),
    'the recorded schema version matches the shipped one',
    'recorded ' . (Activator::recordedVersion() ?? 'none') . ', shipped ' . Activator::SCHEMA_VERSION
);

// The Settings and Dashboard screens state the sweep cadence from the WP-Cron
// event this plugin registers and heals. No event, no queue drain, and no
// sweep: the screens then say so rather than quoting a cadence nothing keeps.
$sweepSchedules = wp_get_schedules();

assertThat(
    wp_next_scheduled(Plugin::HOOK_RUN_JOBS) !== false
        && isset($sweepSchedules[Plugin::CRON_SCHEDULE]),
    'the queue drain event and its schedule are registered',
    Plugin::HOOK_RUN_JOBS . ' on ' . Plugin::CRON_SCHEDULE
        . ' (' . (int) ($sweepSchedules[Plugin::CRON_SCHEDULE]['interval'] ?? 0) . 's)'
);

// ---------------------------------------------------------------------------
// 11d. The frontend instructor page
// ---------------------------------------------------------------------------
//
// The public page that mounts the instructor workbench for anyone the core
// says may teach. Four facts a unit test cannot answer, because each one is a
// property of a real WordPress: the rewrite rule is registered and flushed,
// the shortcode is registered, the URL builder answers the shape this site's
// permalink structure actually serves, and the mount contract comes out with
// the same keys wp-admin writes, because it is the same bundle reading it.
//
// The two URL templates matter most. add_query_arg percent-encodes what it is
// given, so the tokens travel as plain words and are swapped for the brace
// form afterwards; a template that came back with "%7Bcourse_id%7D" in it
// leaves the app's fillUrlTemplate matching nothing, which is a link that
// silently goes nowhere rather than an error anybody sees.

heading('11d. The frontend instructor page');

$instructorRoute = \Shondalai\VidyaLMS\WordPress\Content\Rewrites::ROUTE_INSTRUCTOR;
$instructorSlug = \Shondalai\VidyaLMS\WordPress\Content\Rewrites::slug($instructorRoute);
$instructorUrl = \Shondalai\VidyaLMS\WordPress\Content\Rewrites::instructorUrl();

out('  instructor  : ' . $instructorUrl);

assertThat(
    $instructorSlug !== '' && str_contains($instructorUrl, '/' . $instructorSlug . '/'),
    'the instructor route has a slug and a URL built from it',
    $instructorSlug . ' => ' . $instructorUrl,
);

$rewriteRules = get_option('rewrite_rules');
$instructorRules = [];

if (is_array($rewriteRules)) {
    foreach (array_keys($rewriteRules) as $pattern) {
        if (str_starts_with((string) $pattern, $instructorSlug . '/')) {
            $instructorRules[] = (string) $pattern;
        }
    }
}

// A site left on plain permalinks has no rewrite rules at all and answers
// these routes through the query var instead, which is why Rewrites registers
// both. Saying which case this site is in is worth more than a pass or a fail.
assertThat(
    !\Shondalai\VidyaLMS\WordPress\Content\Rewrites::needsPrettyPermalinks()
        ? $instructorRules !== []
        : true,
    'the instructor rewrite rule is flushed into this site',
    \Shondalai\VidyaLMS\WordPress\Content\Rewrites::needsPrettyPermalinks()
        ? 'permalinks are plain on this site, so the query var is what routes it'
        : implode(', ', $instructorRules),
);

assertThat(
    shortcode_exists(\Shondalai\VidyaLMS\WordPress\Content\InstructorPage::SHORTCODE),
    'the instructor shortcode is registered',
    '[' . \Shondalai\VidyaLMS\WordPress\Content\InstructorPage::SHORTCODE . ']',
);

$siteMount = \Shondalai\VidyaLMS\WordPress\Content\InstructorPage::mountConfig();
$adminMount = \Shondalai\VidyaLMS\WordPress\Admin\AdminApps::workbenchConfig(0);

$siteMountKeys = array_keys($siteMount);
$adminMountKeys = array_keys($adminMount);
sort($siteMountKeys);
sort($adminMountKeys);

assertThat(
    $siteMountKeys === $adminMountKeys,
    'the site mount carries the keys the wp-admin workbench mount carries',
    implode(', ', $siteMountKeys),
);

/** @var array<string, mixed> $siteMountUrls */
$siteMountUrls = is_array($siteMount['urls'] ?? null) ? $siteMount['urls'] : [];

assertThat(
    str_contains((string) ($siteMountUrls['workbench'] ?? ''), '{course_id}')
        && str_contains((string) ($siteMountUrls['preview'] ?? ''), '{slug}'),
    'the URL templates keep the tokens the app substitutes',
    'workbench ' . ($siteMountUrls['workbench'] ?? '-') . ', preview ' . ($siteMountUrls['preview'] ?? '-'),
);

assertThat(
    ((array) ($siteMount['api'] ?? []))['mode'] === 'prefix'
        && isset(((array) (((array) $siteMount['api'])['headers'] ?? []))['X-WP-Nonce'])
        && str_contains((string) $siteMountUrls['courses'], $instructorSlug),
    'the api block is this plugin\'s REST namespace with the wp_rest nonce',
    (string) (((array) ($siteMount['api'] ?? []))['base'] ?? '-'),
);

assertThat(
    array_key_exists('course', $siteMount) && $siteMount['course'] === null,
    'no course parameter means the caller\'s own course list',
    'course: ' . (array_key_exists('course', $siteMount) ? json_encode($siteMount['course']) : 'absent'),
);

foreach (
    [
        'the page template' => Plugin::path('templates/instructor.php'),
        'the mount part'    => Plugin::path('templates/part-instructor.php'),
    ] as $what => $file
) {
    assertThat(is_file($file), $what . ' is installed', $file);
}

// ---------------------------------------------------------------------------
// 11e. The Import screen
// ---------------------------------------------------------------------------
//
// The screen is registry driven: it offers every registered importer and holds
// no list of its own, so a mapper package that registers one appears without an
// edit here. What is new is that it also draws the options an importer
// declares, and the two things worth asserting on a real site are that the
// declarations survive the round trip through the descriptor the screen renders
// from, and that a checkbox comes back as a boolean.
//
// That second one is not pedantry. The form posts strings, and every importer
// reads its flags as ( $options['enrolments'] ?? false ) === true, which a
// string "1" fails: the run would quietly do the opposite of what was ticked.
// ImportOption::read() is in the core so this form and the Joomla one cannot
// disagree, and this is where it is exercised against the real registry rather
// than a fixture.

heading('11e. The Import screen');

$importerDescriptors = \Shondalai\VidyaLMS\WordPress\Admin\ImportSources::descriptors(
    $kernel->registries->importers
);

$describedKeys = array_map(
    static fn (array $descriptor): string => $descriptor['key'],
    $importerDescriptors
);

assertThat(
    $describedKeys === $kernel->registries->importers->keys(),
    'the screen offers exactly the registered importers, in registration order',
    implode(', ', $describedKeys),
);

// Every importer that reads a database has to be describable from this
// screen, and until R4 three of them were not: the probe was the literal
// 'cms-db:' and learndash-db:, tutor-db: and lifterlms-db: all answer
// false to it on purpose. The descriptor now names the scheme the importer
// DECLARES, and databaseSource() composes onto that scheme.
$databaseImporters = [];
$startable = [];
$schemesRoundTrip = true;

foreach ($importerDescriptors as $descriptor) {
    if (!$descriptor['accepts_database']) {
        continue;
    }

    $databaseImporters[] = $descriptor['key'] . ' => ' . $descriptor['database_scheme'];
    $composed = \Shondalai\VidyaLMS\WordPress\Admin\ImportSources::databaseSource(
        'courses=7',
        $descriptor['database_scheme']
    );
    // The round trip is the SHAPE: the screen composes onto the scheme the
    // importer declared. Whether the product is on this site is a separate
    // fact and the descriptor carries it separately, so this asserts the two
    // agree rather than folding one into the other. Calling supports() alone
    // here asked both questions at once, which is the defect one layer up: a
    // screen that hid learndash-db: on a site with no LearnDash described the
    // importer wrongly, and a harness that read the same answer called it
    // correct.
    $schemesRoundTrip = $schemesRoundTrip
        && $descriptor['database_scheme'] !== ''
        && str_starts_with($composed, $descriptor['database_scheme'])
        && $descriptor['source_installed']
            === $kernel->registries->importers->get($descriptor['key'])->supports($composed);

    if ($descriptor['source_installed']) {
        $startable[] = $descriptor['key'];
    }
}

assertThat(
    $schemesRoundTrip && count($databaseImporters) >= 4,
    'every database importer names a scheme the screen composes and the importer accepts',
    'describes a database source: ' . implode(', ', $databaseImporters)
    . '; product present on this site: ' . ($startable === [] ? 'none' : implode(', ', $startable))
);

$declaredOptions = [];
$optionShapeOk = true;

foreach ($importerDescriptors as $descriptor) {
    foreach ($descriptor['options'] as $option) {
        $declaredOptions[] = $descriptor['key'] . '.' . $option['key'];

        $optionShapeOk = $optionShapeOk
            && $option['label'] !== ''
            && in_array($option['kind'], \Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption::KINDS, true)
            && ($option['kind'] !== 'choice' || $option['choices'] !== array());
    }
}

assertThat(
    $optionShapeOk && $declaredOptions !== [],
    'every declared importer option arrives with a label and a drawable kind',
    count($declaredOptions) . ' option(s): ' . implode(', ', $declaredOptions),
);

$readBack = \Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption::read(
    array_values(
        \Shondalai\VidyaLMS\WordPress\Admin\ImportSources::declaredOptions(
            $kernel->registries->importers,
            'community-quiz'
        )
    ),
    array('enrolments' => '1', 'table_prefix' => ' wp_cq_ ', 'dry_run' => '1')
);

assertThat(
    ($readBack['enrolments'] ?? null) === true
        && ($readBack['publish'] ?? null) === false
        && ($readBack['table_prefix'] ?? null) === 'wp_cq_'
        && !array_key_exists('dry_run', $readBack),
    'a ticked box reaches the importer as a boolean and dry_run stays the pipeline\'s',
    (string) wp_json_encode($readBack),
);

// ---------------------------------------------------------------------------
// 11f. The mail port: the header map, the envelope sender and part ordering
// ---------------------------------------------------------------------------
//
// Three of the four obligations in MailerPort's docblock are legal rather than
// aesthetic, and none of them can be checked without assembling a real MIME
// document: RFC 8058 one-click is honoured only when both List-Unsubscribe
// header names survive intact, RFC 2046 section 5.1.4 orders a
// multipart/alternative plain part before the HTML part, and the envelope
// sender is what makes an asynchronous bounce attributable at all.
//
// wp_mail is what the port calls, so the message is captured at
// phpmailer_init, which fires immediately before the send: preSend() assembles
// MIMEHeader and MIMEBody, and clearing the recipients then makes PHPMailer
// refuse the send on every machine rather than depending on this one having no
// mail transport. Nothing leaves the site, and what is asserted is the exact
// document a transport would have been handed.

heading('11f. Mail headers, the envelope sender and part ordering');

$capturedMime = '';
$capturedSender = '';
$capturedMailer = '';

$mailCapture = static function ($phpmailer) use (&$capturedMime, &$capturedSender, &$capturedMailer): void {
    // SMTP is the transport a site that sends bulk mail uses, and it is the
    // one that carries a structured header intact: PHPMailer encodes any
    // custom header value over 47 characters when the transport is PHP mail(),
    // which the case below asserts is detected rather than shipped.
    $phpmailer->isSMTP();
    $phpmailer->preSend();

    $capturedMime = $phpmailer->getSentMIMEMessage();
    $capturedSender = (string) $phpmailer->Sender;
    $capturedMailer = (string) $phpmailer->Mailer;

    // Deterministic refusal: PHPMailer throws "You must provide at least one
    // recipient", wp_mail catches it and answers false, and the port turns
    // that into the exception this section catches.
    $phpmailer->clearAllRecipients();
};

// WordPress defaults the From address to wordpress@<host>, and on a
// development host with no dot in its name PHPMailer rejects that before
// phpmailer_init ever fires, so nothing would be assembled and the section
// would report a port defect that is really a site setting. The From address
// is the site's business and not this port's, so it is supplied here.
$mailFrom = static fn (): string => 'smoke@example.test';
$mailFromName = static fn (): string => 'VidyaLMS smoke';

add_filter('wp_mail_from', $mailFrom, 999);
add_filter('wp_mail_from_name', $mailFromName, 999);
add_action('phpmailer_init', $mailCapture, 999, 1);

$unsubscribeUri = 'https://example.test/unsubscribe/SMOKE-TOKEN_1';
$mailHeaders = \Shondalai\VidyaLMS\Core\Port\Mail\MailHeaders::build(
    \Shondalai\VidyaLMS\Core\Port\Mail\MailHeaders::OPTIONAL,
    $unsubscribeUri,
    'mailto:unsubscribe@example.test',
    'smoke-queue-42',
    'email',
);

$renderedMessage = new \Shondalai\VidyaLMS\Core\Port\Mail\MailMessage(
    $learnerId,
    LEARNER_EMAIL,
    'vidyalms.mail.enrolled',
    ['learner_name' => 'Smoke Learner'],
    'smoke:mail-headers',
    'A course is waiting for you',
    "The plain part.\nIt has to come first.",
    '<p>The HTML part. It has to come last.</p>',
    $mailHeaders,
    'bounce+smoke-queue-42@example.test',
);

$mailOutcome = 'sent';

try {
    Plugin::ports()->mailer->send($renderedMessage);
} catch (\Throwable $mailFailure) {
    // Expected: the capture cleared the recipients so nothing could be sent.
    $mailOutcome = $mailFailure->getMessage();
}

step('the send stopped after the message was assembled', $mailOutcome);

remove_action('phpmailer_init', $mailCapture, 999);
remove_filter('wp_mail_from', $mailFrom, 999);
remove_filter('wp_mail_from_name', $mailFromName, 999);

$headerEnd = strpos($capturedMime . "\r\n\r\n", "\r\n\r\n");
$headerBlock = $capturedMime === '' ? '' : substr($capturedMime, 0, (int) $headerEnd);

assertThat(
    stripos($headerBlock, 'List-Unsubscribe: <' . $unsubscribeUri . '>') !== false
        && stripos($headerBlock, 'List-Unsubscribe-Post: List-Unsubscribe=One-Click') !== false,
    'both List-Unsubscribe header names reach the wire intact',
    $capturedMime === '' ? 'nothing was assembled' : 'the https URI is first and the POST variant is present',
);

assertThat(
    stripos($headerBlock, 'Auto-Submitted: auto-generated') !== false
        && stripos($headerBlock, 'Precedence: bulk') !== false,
    'the RFC 3834 stamp and the bulk precedence are emitted verbatim',
    $capturedMime === '' ? 'nothing was assembled' : 'Auto-Submitted and Precedence both present',
);

assertThat(
    stripos($headerBlock, 'Feedback-ID: smoke-queue-42:optional:vidyalms') !== false,
    'the feedback identifier correlates a later bounce to the queue row',
    $capturedMime === '' ? 'nothing was assembled' : 'Feedback-ID carries the queue digest and the class',
);

$plainAt = $capturedMime === '' ? false : stripos($capturedMime, 'Content-Type: text/plain');
$htmlAt = $capturedMime === '' ? false : stripos($capturedMime, 'Content-Type: text/html');

assertThat(
    $plainAt !== false && $htmlAt !== false && $plainAt < $htmlAt
        && stripos($headerBlock, 'multipart/alternative') !== false,
    'the plain part precedes the HTML part, per RFC 2046 section 5.1.4',
    $capturedMime === ''
        ? 'nothing was assembled'
        : 'multipart/alternative, text/plain at ' . var_export($plainAt, true)
            . ' before text/html at ' . var_export($htmlAt, true),
);

assertThat(
    $capturedSender === 'bounce+smoke-queue-42@example.test',
    'the envelope sender is the SMTP MAIL FROM and not the From header',
    'Sender="' . $capturedSender . '" on transport "' . $capturedMailer . '"',
);

// A transactional message carries neither the unsubscribe pair nor a bulk
// precedence. Adding them would invite a receiver to file a certificate mail
// as bulk, and a site is sometimes obliged to deliver exactly those.
$transactionalHeaders = \Shondalai\VidyaLMS\Core\Port\Mail\MailHeaders::build(
    \Shondalai\VidyaLMS\Core\Port\Mail\MailHeaders::TRANSACTIONAL,
    $unsubscribeUri,
    'mailto:unsubscribe@example.test',
    'smoke-queue-43',
    'email',
);

assertThat(
    !isset($transactionalHeaders['List-Unsubscribe'])
        && !isset($transactionalHeaders['List-Unsubscribe-Post'])
        && !isset($transactionalHeaders['Precedence'])
        && ($transactionalHeaders['Auto-Submitted'] ?? '') === 'auto-generated',
    'a transactional message carries the RFC 3834 stamp and nothing else',
    implode(', ', array_keys($transactionalHeaders)),
);

// ---------------------------------------------------------------------------
// 11g. The unsubscribe page, the preference centre and the feedback intake
// ---------------------------------------------------------------------------
//
// All three are host surfaces over core contract routes, and what this section
// proves is the host half: that the rewrite rules exist and carry the token,
// that a GET of the unsubscribe page acts on nothing, and that a raw ARF body
// reaches the router instead of being rejected as bad JSON.
//
// The GET is the legal obligation. Mail security scanners follow every link in
// a message, which is the whole reason RFC 8058 specifies a POST for one-click;
// a GET that unsubscribed would turn a scanner into an opt-out.

heading('11g. The unsubscribe page, the preference centre and the feedback intake');

// A token is URL-safe base64: letters, digits, hyphen, underscore and dot.
$smokeToken = 'eyJ1IjoxfQ.SG1hYy1zbW9rZQ-Value_1';

$unsubscribeUrl = \Shondalai\VidyaLMS\WordPress\Content\Rewrites::unsubscribeUrl($smokeToken);
$preferencesUrl = \Shondalai\VidyaLMS\WordPress\Content\Rewrites::preferencesUrl();

out('  unsubscribe : ' . $unsubscribeUrl);
out('  preferences : ' . $preferencesUrl);
out('  feedback    : ' . rest_url(
    \Shondalai\VidyaLMS\WordPress\Rest\FeedbackController::REST_NAMESPACE . '/feedback/email'
));

assertThat(
    str_contains($unsubscribeUrl, rawurlencode($smokeToken)) || str_contains($unsubscribeUrl, $smokeToken),
    'the unsubscribe URL carries the token as a path segment',
    'so the List-Unsubscribe header is a path rather than a query string',
);

// The rewrite rules are what turn that URL back into a route. Reading the
// stored rules rather than fetching the page is the check that matters: a rule
// that was never flushed is exactly the failure a page fetch on a warm site
// would hide.
$storedRules = (array) get_option('rewrite_rules', []);
$unsubscribeSlug = \Shondalai\VidyaLMS\WordPress\Content\Rewrites::slug(
    \Shondalai\VidyaLMS\WordPress\Content\Rewrites::ROUTE_UNSUBSCRIBE
);
$preferencesSlug = \Shondalai\VidyaLMS\WordPress\Content\Rewrites::slug(
    \Shondalai\VidyaLMS\WordPress\Content\Rewrites::ROUTE_PREFERENCES
);

$matchedUnsubscribe = '';
$matchedPreferences = '';

foreach ($storedRules as $pattern => $target) {
    $target = (string) $target;

    if ($matchedUnsubscribe === '' && str_contains($target, 'vidyalms_route=unsubscribe')) {
        $matchedUnsubscribe = (string) $pattern;
    }

    if ($matchedPreferences === '' && str_contains($target, 'vidyalms_route=preferences')) {
        $matchedPreferences = (string) $pattern;
    }
}

assertThat(
    $matchedUnsubscribe !== '' && str_contains($matchedUnsubscribe, $unsubscribeSlug),
    'the unsubscribe rewrite rule is stored, so the path resolves',
    $matchedUnsubscribe === '' ? 'no rule matched' : $matchedUnsubscribe,
);

assertThat(
    $matchedPreferences !== '' && str_contains($matchedPreferences, $preferencesSlug),
    'the preference centre rewrite rule is stored, so the path resolves',
    $matchedPreferences === '' ? 'no rule matched' : $matchedPreferences,
);

// The GET writes nothing. Proven against the tables the route would write to
// rather than against the page's own report of itself.
$preferenceRowsBefore = $database->count(Query::from('notification_preferences'));
$consentRowsBefore = $database->count(Query::from('notification_consents'));

$previewResult = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
    'GET',
    '/notifications/unsubscribe/' . rawurlencode($smokeToken),
    Principal::guest(),
);

assertThat(
    $database->count(Query::from('notification_preferences')) === $preferenceRowsBefore
        && $database->count(Query::from('notification_consents')) === $consentRowsBefore,
    'a GET of the unsubscribe landing page writes nothing',
    'preferences ' . $preferenceRowsBefore . ' and consents ' . $consentRowsBefore . ' unchanged'
        . ' (the route answered ' . $previewResult->status . ')',
);

// Whether the route exists is asked of the kernel and never inferred from the
// status. This token is deliberately not a real one, so a registered route
// answers it 404 or 422, which is exactly what an unregistered route answers
// too: reading the status would report the working case as the missing one.
$servedTemplates = $kernel->apiKernel()->servedTemplates();

if (!\in_array('/notifications/unsubscribe/{token}', $servedTemplates, true)) {
    pending(
        'the landing page refuses a token that was never issued',
        'GET /notifications/unsubscribe/{token} is not registered on this build',
    );
} else {
    assertThat(
        $previewResult->status === 404 || $previewResult->status === 422,
        'the landing page refuses a token that was never issued',
        'status ' . $previewResult->status . ', and nothing was written',
    );
}

$centreResult = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
    'GET',
    '/me/notification-preferences',
    Principal::guest(),
);

if (!\in_array('/me/notification-preferences', $servedTemplates, true)) {
    pending(
        'the preference centre refuses a guest with 401',
        'GET /me/notification-preferences is not registered on this build',
    );
} else {
    assertThat(
        $centreResult->unauthorized(),
        'the preference centre refuses a guest rather than answering an empty list',
        'status ' . $centreResult->status,
    );
}

// The inbound feedback intake, driven exactly as an ESP or a mailbox forwarder
// drives it: a raw message/feedback-report body and a per-channel secret, with
// no session. The transport is the half that used to fail before the core
// could see the payload, because an adapter that decoded every body as JSON
// answered an ARF report 400.
$feedbackResult = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
    'POST',
    '/notifications/feedback/email',
    Principal::guest(),
    [],
    [],
    "Feedback-Type: abuse\r\nUser-Agent: SMOKE/1\r\nVersion: 1\r\n",
    'message/feedback-report',
    ['x-vidyalms-feedback-secret' => 'smoke-secret'],
);

assertThat(
    $feedbackResult->status !== 400,
    'a raw ARF body reaches the router instead of being rejected as bad JSON',
    'the kernel answered ' . $feedbackResult->status . ' rather than 400',
);

if (!\in_array('/notifications/feedback/{channelKey}', $servedTemplates, true)) {
    pending(
        'the feedback route refuses a request with no valid shared secret',
        'POST /notifications/feedback/{channelKey} is not registered on this build',
    );
} else {
    // No secret is configured for the email channel on this site, so the
    // refusal is the correct answer and is what proves the route is guarded
    // by provider_settings rather than by a session.
    assertThat(
        $feedbackResult->status === 401,
        'the feedback route refuses a request with no valid shared secret',
        'status ' . $feedbackResult->status . ', without saying whether the channel exists',
    );
}

// ---------------------------------------------------------------------------
// 11h. The mount discovery key
// ---------------------------------------------------------------------------
//
// The workbench bundle and this plugin ship separately, and a paid package
// adds its routes from a kernel-ready listener, so which of the contract's
// paths exist on an install is decided at boot. Without the key the bundle had
// to guess, and it guessed wrong in the one direction that matters: the quiz
// export control was on the contract, in the SDK and in the shipped workbench,
// and answered 404 on every site without the package that serves it.

heading('11h. The mount discovery key');

$workbenchConfig = \Shondalai\VidyaLMS\WordPress\Admin\AdminApps::workbenchConfig($course->id);
$declaredRoutes = $workbenchConfig['routes'] ?? null;
$declaredRoutes = \is_array($declaredRoutes) ? $declaredRoutes : null;

assertThat(
    $declaredRoutes !== null && \in_array('/manage/courses/{id}', $declaredRoutes, true),
    'the workbench mount declares the contract paths this install serves',
    $declaredRoutes === null ? 'the key is absent' : \count($declaredRoutes) . ' template(s)',
);

assertThat(
    $declaredRoutes !== null && $declaredRoutes === array_values(array_unique($declaredRoutes)),
    'each path is declared once, whatever the methods on it',
    'no duplicates',
);

$proRouteTemplate = '/manage/quizzes/{id}/export';
$declaresProRoute = $declaredRoutes !== null && \in_array($proRouteTemplate, $declaredRoutes, true);
$proInstalled = \in_array(
    'vidyalms-pro',
    array_map(
        static fn (\Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProvider $row): string => $row->package,
        $kernel->featureProviders->listActive()
    ),
    true
);

if ($proInstalled) {
    assertThat(
        $declaresProRoute,
        'a route a paid package contributed is declared to the workbench',
        $proRouteTemplate . ' is present, so the export control is drawn',
    );
} else {
    assertThat(
        !$declaresProRoute,
        'a route no package serves is not declared, so no control is drawn for it',
        $proRouteTemplate . ' is absent on a free build',
    );
}

// The front-end instructor page mounts the same bundle and has to answer the
// same question. Two copies of the answer would be two answers.
assertThat(
    \Shondalai\VidyaLMS\WordPress\Admin\AdminApps::servedRoutes() === $declaredRoutes,
    'the front-end instructor page declares the identical route list',
    'both surfaces answer from the running kernel rather than from a copy',
);

// ---------------------------------------------------------------------------
// 11i. The periodic task turn
// ---------------------------------------------------------------------------
//
// A sweep has no event behind it: nothing happens when a drip window opens,
// when a digest period closes or when a learner fails to log in, so the host's
// own clock has to start it. This section drives that turn end to end through
// the drain WP-Cron calls, with probe handlers registered under the four type
// names this wave introduces, so the path is proven before the core's own
// handlers exist and stays proven after.
//
// The runner still knows no task type. It resolves each queued row in the
// core's scheduled task registry, and the drain asks the registry which
// handlers the clock has to start; neither holds a list.

heading('11i. The periodic task turn');

// Every handler that says the clock has to start it, and the rows one turn
// owes. The list is asked of the registry rather than written down, so a sweep
// a paid or third-party package registers is under test the day it registers.
$periodicHandlers = [];

foreach ($kernel->registries->scheduledTasks->all() as $registeredTask) {
    if ($registeredTask instanceof \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically) {
        $periodicHandlers[] = $registeredTask;
    }
}

// A build that registers none is a build where nothing can be asserted, and a
// probe under a name nobody uses keeps the path proven until one ships.
$sweepLog = new \ArrayObject();
$probeTask = null;

if ($periodicHandlers === []) {
    $probeTask = new SmokeProbeSweepTask('vidyalms.smoke.periodic', $sweepLog);
    $kernel->registries->scheduledTasks->register($probeTask);
    $periodicHandlers[] = $probeTask;
}

$periodicKeys = [];
$expectedRows = 0;
$startedNames = [];
// What the clock owes this turn, minus what the queue is already holding.
//
// EnqueuePeriodicTasks holds a handler back when the queue already carries a
// period's worth of its unstarted work, which is what stops a site whose
// drain has fallen behind taking another period's rows every turn without
// bound. Both development sites carry exactly such a backlog, so a harness
// that expected one row per bin for every registered handler would be
// asserting the behaviour the shipped rule deliberately no longer has.
//
// Nothing is deleted to make this pass. The backlog is read, the expectation
// is split in two, and both halves are then asserted: the handlers the clock
// owes rows are queued in full, and the handlers it does not are reported as
// held back by name. That is one assertion more than this harness made
// before, not one fewer.
$turnClock = Plugin::ports()->clock->now();

$sweepScheduler = Plugin::ports()->scheduler;
$pendingByType = $sweepScheduler instanceof \Shondalai\VidyaLMS\Core\Port\Scheduler\CountsPendingTasks
    ? $sweepScheduler->pendingCountsByType()
    : [];
$expectedBacklog = [];
$allPeriodicNames = [];

// And a build every one of whose handlers is already backlogged is the same
// problem wearing a different hat: the turn would owe no rows at all, and the
// dedupe assertion below would pass while asserting nothing. The probe carries
// a task type nothing else uses, so it never has a backlog and the property
// stays checkable on a development site whose queue has fallen behind.
if ($probeTask === null) {
    $everyHandlerBacklogged = true;

    foreach ($periodicHandlers as $candidate) {
        if (($pendingByType[$candidate->key()] ?? 0) < max(1, $candidate->binCount())) {
            $everyHandlerBacklogged = false;

            break;
        }
    }

    if ($everyHandlerBacklogged) {
        $probeTask = new SmokeProbeSweepTask('vidyalms.smoke.periodic', $sweepLog = new \ArrayObject());
        $kernel->registries->scheduledTasks->register($probeTask);
        $periodicHandlers[] = $probeTask;
    }
}

foreach ($periodicHandlers as $periodicHandler) {
    $bins = max(1, $periodicHandler->binCount());
    $bucket = $periodicHandler->periodBucket($turnClock);

    $allPeriodicNames[] = $periodicHandler->key();

    if (($pendingByType[$periodicHandler->key()] ?? 0) >= $bins) {
        $expectedBacklog[] = $periodicHandler->key();

        continue;
    }

    for ($bin = 0; $bin < $bins; $bin++) {
        $periodicKeys[] = $periodicHandler->periodicDedupeKey($bucket, $bin);
    }

    $expectedRows += $bins;
    $startedNames[] = $periodicHandler->key();
}

step(
    'handlers the clock has to start',
    \count($allPeriodicNames) . ' registered; this turn owes rows to '
    . ($startedNames === [] ? 'none' : implode(', ', $startedNames))
    . ($expectedBacklog === [] ? '' : '; already queued: ' . implode(', ', $expectedBacklog))
    . ($probeTask === null ? '' : ' (a probe, because nothing else here owes a row)'),
);

// Named rather than merely counted, because this one has no event behind it
// by construction: completion is terminal and CourseCompleted cannot re-fire,
// so a completed enrolment whose issuance failed is a gap only a sweep will
// ever look for. It is provisioned the way every registered task type is, by
// the one recurring event this host schedules, and this says so out loud.
assertThat(
    \in_array('vidyalms.certificate.backfill', $allPeriodicNames, true),
    'the certificate backfill sweep is among the handlers the clock starts',
    // Every registered periodic handler, not only the ones this turn
    // owes rows: whether the clock starts this sweep at all is a fact
    // about the registry, and a site whose queue is behind must not be
    // able to make that question answer no.
    'registered periodic handlers: ' . implode(', ', $allPeriodicNames),
);

/** How many of the rows this turn owns are in the queue right now. */
$queuedTurnRows = static function () use ($database, $periodicKeys): int {
    $found = 0;

    foreach ($periodicKeys as $periodicKey) {
        $found += $database->count(Query::from(WpScheduler::TABLE)->where('dedupe_key', '=', $periodicKey));
    }

    return $found;
};

$turn = new \Shondalai\VidyaLMS\Core\Application\Platform\EnqueuePeriodicTasks(
    $kernel->registries->scheduledTasks,
    Plugin::ports()->scheduler,
    Plugin::ports()->clock,
);

$firstTurn = $turn->execute();
$queuedFirst = $queuedTurnRows();

assertThat(
    array_values(array_intersect($startedNames, $firstTurn['tasks'])) === $startedNames
        && $queuedFirst === $expectedRows,
    'the clock starts every task that declares it needs starting, one row per bin',
    'started ' . \count($firstTurn['tasks']) . ' task(s), queued ' . $firstTurn['enqueued']
        . ' row(s), ' . $queuedFirst . ' of the ' . $expectedRows . ' this turn owns are in the queue',
);


// The other half of the shipped rule, and the half a site with a healthy queue
// never exercises: a handler whose unstarted work already fills a period is
// held back and named, rather than silently adding another period's rows to a
// queue that is running none of them. Asserted as a set, because the order
// registration happens to produce is not a promise.
$reportedBacklog = $firstTurn['backlogged'] ?? [];
sort($reportedBacklog);
$sortedExpectedBacklog = $expectedBacklog;
sort($sortedExpectedBacklog);

assertThat(
    $reportedBacklog === $sortedExpectedBacklog,
    'a handler whose period is still queued is held back and named',
    ($sortedExpectedBacklog === [] ? 'nothing was backlogged' : 'expected ' . implode(', ', $sortedExpectedBacklog))
    . '; reported ' . ($reportedBacklog === [] ? 'nothing' : implode(', ', $reportedBacklog)),
);

// The same turn again inside the same period. The dedupe key is the provider's
// own and the scheduler port replaces a pending row carrying it, so a host
// clock that fires twice leaves one row per bin. That matters more here than
// on Joomla: WP-Cron fires on page load, so a busy site turns this several
// times a minute.
$turn->execute();

assertThat(
    $queuedTurnRows() === $queuedFirst && $queuedFirst > 0,
    'a second turn inside one period queues no second row',
    $queuedFirst . ' row(s) after the first turn, ' . $queuedTurnRows() . ' after the second',
);

// The provider-generated keys above are the exact rows this turn owns. Move
// only those pending rows to the fixed ancient instant, ahead of any ordinary
// development-site backlog; nothing else in the queue is rewritten.
$prioritizedSweepRows = 0;

foreach ($periodicKeys as $periodicKey) {
    $prioritizedSweepRows += $database->update(
        WpScheduler::TABLE,
        ['run_at' => $smokeQueueRunAt->format('Y-m-d H:i:s')],
        ['dedupe_key' => $periodicKey, 'status' => WpScheduler::STATUS_PENDING]
    );
}

assertThat(
    $prioritizedSweepRows === $expectedRows,
    'only this turn\'s periodic rows are made first in the due queue',
    $prioritizedSweepRows . ' of ' . $expectedRows . ' exact dedupe key(s) updated'
);

/** How many exact rows owned by this turn are still pending. */
$pendingTurnRows = static function () use ($database, $periodicKeys): int {
    $pending = 0;

    foreach ($periodicKeys as $periodicKey) {
        $pending += $database->count(
            Query::from(WpScheduler::TABLE)
                ->where('dedupe_key', '=', $periodicKey)
                ->where('status', '=', WpScheduler::STATUS_PENDING)
        );
    }

    return $pending;
};

// Drain only the still-pending owned rows. QueueRunner caps a pass at 200, so
// the finite loop exists only for a registry with more than 200 periodic bins;
// every pass restores the site's configured batch size immediately afterward.
$firstPass = ['claimed' => 0, 'done' => 0, 'failed' => 0];
$passes = 0;
$maxPasses = max(1, intdiv($expectedRows + 199, 200));

while (($ownedPending = $pendingTurnRows()) > 0 && $passes < $maxPasses) {
    $pass = drainSmokeOwnedBatch(min(200, $ownedPending));
    $firstPass['claimed'] += $pass['claimed'];
    $firstPass['done'] += $pass['done'];
    $firstPass['failed'] += $pass['failed'];
    $passes++;

    if ($pass['claimed'] === 0) {
        break;
    }
}

$finished = 0;
$unfinished = [];

foreach ($periodicKeys as $periodicKey) {
    $sweepRow = $database->selectOne(Query::from(WpScheduler::TABLE)->where('dedupe_key', '=', $periodicKey));

    if ($sweepRow === null) {
        $unfinished[] = $periodicKey . ' => row missing';

        continue;
    }

    if (($sweepRow['status'] ?? '') === WpScheduler::STATUS_DONE) {
        $finished++;
    } else {
        $unfinished[] = (string) $sweepRow['dedupe_key'] . ' => ' . (string) ($sweepRow['status'] ?? 'null')
            . ' ' . substr((string) ($sweepRow['last_error'] ?? ''), 0, 60);
    }
}

assertThat(
    $finished === $expectedRows && $unfinished === [],
    'every row the turn queued is run by the drain, resolved in the registry',
    $finished . ' of ' . $expectedRows . ' finished; ' . $passes . ' drain pass(es), claimed=' . $firstPass['claimed']
        . ' done=' . $firstPass['done'] . ' failed=' . $firstPass['failed']
        . ($unfinished === [] ? '' : '; unfinished: ' . implode(' | ', \array_slice($unfinished, 0, 3))),
);

// The turn's rows are this run's, and they go with it. They are addressed by
// the exact keys the providers minted rather than by a pattern, so a row this
// run did not create cannot be taken with them.
$sweepRowsRemoved = 0;

foreach ($periodicKeys as $periodicKey) {
    $sweepRowsRemoved += $database->delete(WpScheduler::TABLE, ['dedupe_key' => $periodicKey]);
}

step('sweep queue rows removed', $sweepRowsRemoved . ' of ' . $expectedRows . ' row(s)');

// ---------------------------------------------------------------------------
// 11j. A drip-locked lesson and a cohort release
// ---------------------------------------------------------------------------
//
// The two learner-facing outcomes of this wave, driven over the contract on a
// real site rather than over a fake in a unit test.
//
// A drip lock is not a subsystem here and gets no subsystem: it is a date leaf
// in a boolean tree stored against the lesson, and the proof it works is that
// the learner's own curriculum read comes back with that lesson blocked and
// naming the instant it opens. Nothing records that the lesson is locked and
// nothing will record that it unlocked: availability is evaluated on read,
// because a design where a learner's access depends on a job having fired is
// broken on both hosts, where the scheduler is triggered by page traffic.
//
// A cohort release is the paid half of the same idea, and it is asserted the
// same way: create a run, ask the learner surface what it says, and take the
// run away again. A free build registers no cohort provider, so the write
// answers 501 and the section says so rather than passing on nothing.

heading('11j. A drip-locked lesson and a cohort release');

$courseIdForGating = (int) $course->id;
$authorPrincipal = $actor;
$learnerPrincipal = $learner;


$opensAt = (new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

$setRule = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
    'PUT',
    '/manage/lessons/' . $secondLessonId . '/availability',
    $authorPrincipal,
    [
        'tree' => [
            'op' => 'and',
            'showc' => [true],
            'c' => [
                [
                    'type' => 'date',
                    'payload' => ['_v' => 1, 'from' => $opensAt, 'tz' => 'UTC'],
                ],
            ],
        ],
    ],
);

if ($setRule->routeMissing()) {
    pending(
        'a lesson gated on a future date reads back blocked, naming the instant',
        'PUT /manage/{subject_type}/{subject_id}/availability is not registered on this build',
    );
    pending(
        'the lock is evaluated on read and no row records it',
        'the availability surface is not registered on this build',
    );
} else {
    assertThat(
        $setRule->ok(),
        'a date restriction is accepted on the lesson',
        'status ' . $setRule->status . ($setRule->ok() ? '' : ': ' . $setRule->detail()),
    );

    $curriculum = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'GET',
        '/courses/' . $courseIdForGating . '/curriculum',
        $learnerPrincipal,
    );

    $lockedAccess = null;

    foreach ($curriculum->rows('modules') as $curriculumModule) {
        foreach ((array) ($curriculumModule['lessons'] ?? []) as $curriculumLesson) {
            if (!\is_array($curriculumLesson) || (int) ($curriculumLesson['id'] ?? 0) !== $secondLessonId) {
                continue;
            }

            $lockedAccess = \is_array($curriculumLesson['access'] ?? null) ? $curriculumLesson['access'] : null;
        }
    }

    assertThat(
        $lockedAccess !== null
            && ($lockedAccess['state'] ?? '') === 'blocked'
            && \is_string($lockedAccess['reason'] ?? null)
            && ($lockedAccess['opens_at'] ?? null) !== null,
        'a lesson gated on a future date reads back blocked, naming the instant',
        $lockedAccess === null
            ? 'the lesson carried no access block'
            : 'state=' . (string) ($lockedAccess['state'] ?? '')
                . ' reason=' . (string) ($lockedAccess['reason'] ?? 'null')
                . ' opens_at=' . (string) ($lockedAccess['opens_at'] ?? 'null'),
    );

    // Nothing materialises the lock. The rule row is the only thing stored,
    // and taking it away is all it takes to open the lesson again.
    $clearRule = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'DELETE',
        '/manage/lessons/' . $secondLessonId . '/availability',
        $authorPrincipal,
    );

    assertThat(
        $clearRule->ok() || $clearRule->status === 204,
        'clearing the rule is the whole of unlocking it',
        'status ' . $clearRule->status,
    );
}

$createCohort = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
    'POST',
    '/manage/courses/' . $courseIdForGating . '/cohorts',
    $authorPrincipal,
    [
        'title' => 'VidyaLMS smoke run',
        'code' => 'vidyalms-smoke-run',
        'pacing' => 'fixed',
        'state' => 'open',
        'starts_at' => $opensAt,
    ],
);

if ($createCohort->routeMissing() || $createCohort->status === 501) {
    pending(
        'a cohort is created and its release reaches the learner surface',
        'POST /manage/courses/{id}/cohorts answers ' . $createCohort->status
            . ' on this build, which is what a build with no cohort provider owes',
    );
} else {
    $cohortId = (int) ($createCohort->data['id'] ?? 0);

    assertThat(
        $createCohort->ok() && $cohortId > 0,
        'a cohort is created on the course',
        'status ' . $createCohort->status . ', cohort ' . $cohortId,
    );

    if ($cohortId > 0) {
        $removeCohort = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call('DELETE', '/manage/cohorts/' . $cohortId, $authorPrincipal);

        step('smoke cohort removed', 'status ' . $removeCohort->status);
    }
}

// ---------------------------------------------------------------------------
// 11k. The envelope sender, from the queue to the transport
// ---------------------------------------------------------------------------
//
// The one thing 11f above cannot prove. It hands the port a message it built
// itself and asserts the port carries the envelope sender it was given; what
// composes that address is the queue, and the queue composed the bare tag
// "vy{id}". A bare tag is not an address: PHPMailer validates Sender inside
// preSend() and refuses the whole message, so every queued notification on
// both hosts went failed_permanent without a transport ever seeing it.
//
// So this section drives the shipped path end to end. A real queue row, the
// shipped send task resolved from the registry, the shipped email channel and
// the real WpMailer over wp_mail. wp_mail reuses whatever PHPMailer sits on
// the global, so the transport is a PHPMailer whose postSend() records the
// assembled document and posts nothing: preSend() still runs, which is the
// method that refused the bare tag, and wp_mail still answers true, which is
// what lets the queue row reach sent.

heading('11k. The envelope sender, from the queue to the transport');

$envelopeTemplateKey = 'vidyalms.smoke.envelope';
$envelopeChannelKey = \Shondalai\VidyaLMS\Core\Provider\Notification\EmailNotificationChannel::KEY;
$envelopeRegistryKey = \Shondalai\VidyaLMS\Core\Application\Notification\NotificationSender::REGISTRY_CHANNEL;

// The host default is the address wp_mail sends from, composed from the
// stored home option and whatever wp_mail_from answers. Never the request
// host: cron has no host header and a WP-CLI run would compose a different
// domain from the same site.
$homeHost = wp_parse_url((string) get_option('home'), PHP_URL_HOST);
$homeHost = is_string($homeHost) ? (string) preg_replace('/^www\./i', '', $homeHost) : '';
$expectedMailFrom = (string) apply_filters('wp_mail_from', 'wordpress@' . $homeHost);

assertThat(
    $kernel->envelopeDomain === $expectedMailFrom,
    'the host default is the address wp_mail sends from, never the request host',
    'envelope domain "' . $kernel->envelopeDomain . '" from the home option "' . $homeHost
    . '"; HTTP_HOST "' . (string) ($_SERVER['HTTP_HOST'] ?? '') . '" is not consulted',
);

$kernel->notificationTemplates->save($envelopeTemplateKey, 'en-GB', $envelopeChannelKey, array(
    'subject' => 'VidyaLMS smoke: the reverse path',
    'body_text' => 'This message exists so the envelope sender can be read off the wire.',
    'state' => \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository::STATE_PUBLISHED,
));

/** The dedupe digest of one smoke row, so cleanup addresses it exactly. */
$envelopeDedupe = static fn (string $suffix): string => hash('sha256', 'vidyalms.smoke.envelope|' . $suffix);

/**
 * Queue one transactional message and run the shipped send task over it, with
 * the transport captured at the moment PHPMailer would have posted it.
 *
 * @return array{id: int, sender: string, mime: string, from: string, summary: string, state: string, messageId: string}
 */
$driveEnvelope = static function (string $suffix) use (
    $kernel,
    $database,
    $learnerId,
    $envelopeTemplateKey,
    $envelopeChannelKey,
    $envelopeDedupe
): array {
    // A run that died mid-section leaves its own row behind, and enqueue()
    // answers null rather than a second row for a digest already present.
    $database->delete('notification_queue', array('dedupe_key' => $envelopeDedupe($suffix)));

    $queueId = (int) $kernel->notificationQueue->enqueue(
        new \Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification(
            null,
            null,
            'vidyalms.smoke.envelope',
            'account',
            \Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass::Transactional,
            $envelopeTemplateKey,
            'smoke:envelope:' . $suffix,
            'once',
            $envelopeDedupe($suffix),
            Plugin::ports()->clock->now(),
            \Shondalai\VidyaLMS\Core\Domain\Notification\QueueState::Pending,
            $learnerId,
            $envelopeChannelKey,
            null,
            null,
            array('learner_name' => 'VidyaLMS smoke'),
            'UTC'
        )
    );

    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    if (is_file(ABSPATH . WPINC . '/class-wp-phpmailer.php')) {
        require_once ABSPATH . WPINC . '/class-wp-phpmailer.php';
    } elseif (!class_exists('WP_PHPMailer')) {
        // WordPress 6.6 uses PHPMailer directly; the wrapper arrived later.
        class_alias(\PHPMailer\PHPMailer\PHPMailer::class, 'WP_PHPMailer');
    }

    // preSend() assembles MIMEHeader and MIMEBody and validates every address
    // the message carries, which is where the bare tag was refused. postSend()
    // is the one method between that document and the wire, so overriding it
    // captures the exact message a transport would have been handed and posts
    // nothing on any machine.
    $sink = new class (true) extends \WP_PHPMailer {
        public string $mime = '';
        public string $sender = '';
        public string $fromAddress = '';

        public function postSend()
        {
            $this->mime = $this->getSentMIMEMessage();
            $this->sender = (string) $this->Sender;
            $this->fromAddress = (string) $this->From;

            return true;
        }
    };

    // The validator wp_mail installs when it builds its own instance, kept
    // here so this section validates exactly as the site does.
    \PHPMailer\PHPMailer\PHPMailer::$validator = static fn ($email): bool => (bool) is_email($email);

    $previousMailer = $GLOBALS['phpmailer'] ?? null;
    $GLOBALS['phpmailer'] = $sink;

    // WordPress defaults the From address to wordpress@<host>, and on a
    // development host with no dot in its name PHPMailer rejects that inside
    // setFrom, before phpmailer_init ever fires, so nothing would be
    // assembled and this section would report a queue defect that is really a
    // site setting. The From address is the site's business and not the
    // queue's, so it is supplied here exactly as 11f supplies it.
    $mailFrom = static fn (): string => 'smoke@example.test';
    $mailFromName = static fn (): string => 'VidyaLMS smoke';

    add_filter('wp_mail_from', $mailFrom, 999);
    add_filter('wp_mail_from_name', $mailFromName, 999);

    // wp_mail forces isMail(); SMTP is the transport a site that sends bulk
    // mail uses, and the one that carries a structured header intact.
    $transport = static function ($phpmailer): void {
        $phpmailer->isSMTP();
    };

    add_action('phpmailer_init', $transport, 999, 1);

    $summary = '';

    try {
        $task = $kernel->registries->scheduledTasks->get(
            \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationSendTask::TYPE
        );

        $summary = $task->run(new \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun(
            0,
            array('limit' => smokeEnvelopeDrainLimit($database, $queueId)),
            Principal::guest()
        ));
    } catch (\Throwable $failure) {
        $summary = 'the send task threw: ' . $failure->getMessage();
    } finally {
        remove_action('phpmailer_init', $transport, 999);
        remove_filter('wp_mail_from', $mailFrom, 999);
        remove_filter('wp_mail_from_name', $mailFromName, 999);
        $GLOBALS['phpmailer'] = $previousMailer;
    }

    $row = $kernel->notificationQueue->find($queueId);

    return array(
        'id' => $queueId,
        'sender' => $sink->sender,
        'mime' => $sink->mime,
        'from' => $sink->fromAddress,
        'summary' => $summary,
        'state' => $row === null ? 'gone' : $row->state->value,
        'messageId' => $row === null ? '' : (string) $row->providerMessageId,
    );
};

// The channel's own settings, which is the documented way a site that
// receives bounces on a dedicated domain says so. The row is merged and
// handed back exactly as it was found: the feedback secret lives in the same
// row and losing it would close the intake.
$envelopeSettingsBefore = $kernel->providerSettings->find($envelopeRegistryKey, $envelopeChannelKey, 'site', 0);

$kernel->providerSettings->save(
    $envelopeRegistryKey,
    $envelopeChannelKey,
    'site',
    0,
    array_merge($envelopeSettingsBefore ?? array(), array(
        \Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender::SETTING_DOMAIN => 'bounces.smoke.example',
        \Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender::SETTING_PREFIX => 'verp',
    ))
);

$envelopeConfigured = $driveEnvelope('configured');
$expectedConfigured = 'verp+vy' . $envelopeConfigured['id'] . '@bounces.smoke.example';

if ($envelopeSettingsBefore === null) {
    $database->delete('provider_settings', array(
        'registry_key' => $envelopeRegistryKey,
        'provider_key' => $envelopeChannelKey,
        'scope_type' => 'site',
        'scope_id' => 0,
    ));
} else {
    $kernel->providerSettings->save($envelopeRegistryKey, $envelopeChannelKey, 'site', 0, $envelopeSettingsBefore);
}

assertThat(
    $envelopeConfigured['sender'] === $expectedConfigured
        && filter_var($envelopeConfigured['sender'], FILTER_VALIDATE_EMAIL) !== false,
    'the queue composes a full VERP address and PHPMailer accepts it as MAIL FROM',
    'Sender="' . $envelopeConfigured['sender'] . '", expected "' . $expectedConfigured . '"'
    . ' (' . $envelopeConfigured['summary'] . ')',
);

assertThat(
    $envelopeConfigured['mime'] !== ''
        && stripos($envelopeConfigured['mime'], 'VidyaLMS smoke: the reverse path') !== false
        && $envelopeConfigured['from'] !== $envelopeConfigured['sender'],
    'the message reaches the transport assembled, with the envelope sender apart from the From header',
    $envelopeConfigured['mime'] === ''
        ? 'nothing was assembled'
        : strlen($envelopeConfigured['mime']) . ' byte(s) of MIME, From stayed "' . $envelopeConfigured['from'] . '"',
);

assertThat(
    $envelopeConfigured['state'] === \Shondalai\VidyaLMS\Core\Domain\Notification\QueueState::Sent->value
        && $envelopeConfigured['messageId'] === 'vy' . $envelopeConfigured['id'],
    'the queue row reaches sent and keeps the tag as its provider message id',
    'state "' . $envelopeConfigured['state'] . '", provider_message_id "' . $envelopeConfigured['messageId'] . '"',
);

// The same message with nothing configured, on a development site whose own
// from address is at a dotless host. There is no address to compose, so the
// message goes out with no envelope sender and loses its bounce attribution
// and nothing else. The alternative, handing the transport what could not be
// composed, is what refused every message on this site before.
$kernel->providerSettings->save($envelopeRegistryKey, $envelopeChannelKey, 'site', 0, array_merge(
    $envelopeSettingsBefore ?? [],
    [\Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender::SETTING_DOMAIN => "invalid\ndomain"]
));
try {
    $envelopeUnusable = $driveEnvelope('unusable');
} finally {
    if ($envelopeSettingsBefore === null) {
        $database->delete('provider_settings', [
            'registry_key' => $envelopeRegistryKey, 'provider_key' => $envelopeChannelKey,
            'scope_type' => 'site', 'scope_id' => 0,
        ]);
    } else {
        $kernel->providerSettings->save($envelopeRegistryKey, $envelopeChannelKey, 'site', 0, $envelopeSettingsBefore);
    }
}

assertThat(
    $envelopeUnusable['sender'] === ''
        && $envelopeUnusable['state'] === \Shondalai\VidyaLMS\Core\Domain\Notification\QueueState::Sent->value,
    'a domain no address can be composed from costs the attribution and never the message',
    'the site default is "' . $kernel->envelopeDomain . '", Sender="' . $envelopeUnusable['sender']
    . '", row state "' . $envelopeUnusable['state'] . '"',
);

assertThat(
    $envelopeSettingsBefore === null
        ? $kernel->providerSettings->find($envelopeRegistryKey, $envelopeChannelKey, 'site', 0) === null
        : $kernel->providerSettings->find($envelopeRegistryKey, $envelopeChannelKey, 'site', 0) === $envelopeSettingsBefore,
    'the channel settings row is handed back exactly as it was found',
    $envelopeSettingsBefore === null
        ? 'there was no row and there is none now'
        : (string) wp_json_encode($envelopeSettingsBefore),
);

// This section's rows go with it, addressed by the exact digests it minted.
$envelopeRowsRemoved = 0;

foreach (array('configured', 'unusable') as $suffix) {
    $envelopeRowsRemoved += $database->delete('notification_queue', array('dedupe_key' => $envelopeDedupe($suffix)));
}

$envelopeLogsRemoved = $database->delete('email_log', array('template_key' => $envelopeTemplateKey));
$envelopeTemplateRemoved = $database->delete('notification_templates', array('template_key' => $envelopeTemplateKey));

step(
    'smoke envelope rows removed',
    $envelopeRowsRemoved . ' queue, ' . $envelopeLogsRemoved . ' email log, '
    . $envelopeTemplateRemoved . ' template row(s)'
);

// ---------------------------------------------------------------------------
// 11l. Availability gates a quiz attempt
// ---------------------------------------------------------------------------
//
// The gate is evaluated on read for the outline and again on the write path,
// and the second is the one that matters: an outline that draws a lock while
// POST /quizzes/{id}/attempts starts the attempt anyway is a gate in name
// only. StartAttempt asks the engine before it counts attempts or reads the
// quiz window, so a blocked quiz refuses with the same reason and instant the
// outline already showed.
//
// It runs on a course of its own rather than on the spine's, because the
// spine's enrolment is completed by the time this section is reached and
// StartAttempt requires an active one. Everything it creates is removed at the
// end of the section, and the course is on the --cleanup list as well, so a
// run that dies half way leaves nothing behind that --cleanup cannot take.

heading('11l. Availability gates a quiz attempt');

$gatedCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke gated course',
    GATED_COURSE_SLUG,
    'A course created by build/smoke.php to prove availability refuses a quiz attempt.',
    '<p>Seeded by the VidyaLMS WordPress adapter smoke test.</p>',
);
$gatedCourseId = (int) $gatedCourse->id;
$gatedModule = $kernel->addModule->execute($actor, $gatedCourseId, 'Assessment');

// A course needs one published lesson before it can be published, which is
// PublishCourse's own rule and not something to work around.
$kernel->addLesson->execute(
    $actor,
    (int) $gatedModule->id,
    'Before the assessment',
    'reading',
    ['body' => '<p>Read this, then take the quiz.</p>'],
);
$kernel->publishCourse->execute($actor, $gatedCourseId);
$kernel->grantEntitlement->execute(
    $actor,
    $learnerId,
    $gatedCourseId,
    Entitlement::SOURCE_MANUAL,
    'smoke-test-gated',
);

step('gated course', 'course ' . $gatedCourseId . ' module ' . $gatedModule->id);

$createQuiz = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
    'POST',
    '/manage/courses/' . $gatedCourseId . '/quizzes',
    $actor,
    ['title' => 'Smoke drip quiz', 'pass_mark' => 50],
);

$gatedQuizId = (int) ($createQuiz->data['id'] ?? 0);

if ($createQuiz->routeMissing() || $gatedQuizId <= 0) {
    pending(
        'a quiz gated on a future date refuses the attempt, naming the instant',
        'POST /manage/courses/{id}/quizzes answers ' . $createQuiz->status . ' on this build',
    );
} else {
    $gateOpensAt = (new \DateTimeImmutable('+21 days', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

    $gateQuiz = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'PUT',
        '/manage/quizzes/' . $gatedQuizId . '/availability',
        $actor,
        [
            'tree' => [
                'op' => 'and',
                'showc' => [true],
                'c' => [
                    [
                        'type' => 'date',
                        'payload' => ['_v' => 1, 'from' => $gateOpensAt, 'tz' => 'UTC'],
                    ],
                ],
            ],
        ],
    );

    if ($gateQuiz->routeMissing()) {
        pending(
            'a quiz gated on a future date refuses the attempt, naming the instant',
            'PUT /manage/quizzes/{id}/availability is not registered on this build',
        );
    } else {
        assertThat(
            $gateQuiz->ok(),
            'a date restriction is accepted on the quiz',
            'status ' . $gateQuiz->status . ($gateQuiz->ok() ? '' : ': ' . $gateQuiz->detail()),
        );

        $blockedStart = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call('POST', '/quizzes/' . $gatedQuizId . '/attempts', $learner);

        assertThat(
            $blockedStart->status === 409
                && \is_string($blockedStart->data['reason'] ?? null)
                && ($blockedStart->data['opens_at'] ?? null) !== null,
            'starting an attempt on a gated quiz is refused, naming the reason and the instant',
            'status ' . $blockedStart->status
                . ' reason=' . (string) ($blockedStart->data['reason'] ?? 'null')
                . ' opens_at=' . (string) ($blockedStart->data['opens_at'] ?? 'null'),
        );

        $attemptsWhileBlocked = $database->count(Query::from('attempts')->where('quiz_id', '=', $gatedQuizId));

        assertThat(
            $attemptsWhileBlocked === 0,
            'the refused attempt wrote no attempt row',
            'attempts=' . $attemptsWhileBlocked,
        );

        step(
            'quiz gate cleared',
            'status ' . \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call('DELETE', '/manage/quizzes/' . $gatedQuizId . '/availability', $actor)->status,
        );

        $openStart = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call('POST', '/quizzes/' . $gatedQuizId . '/attempts', $learner);

        assertThat(
            $openStart->status !== 409,
            'clearing the rule is the whole of unlocking the quiz',
            'status ' . $openStart->status
                . ($openStart->status === 409 ? ': ' . $openStart->detail() : ''),
        );
    }
}

step('gated course rows removed', (string) removeSmokeCourse($database, $gatedCourseId) . ' row(s)');


// ---------------------------------------------------------------------------
// 11m. Credentials: a template with elements, the frozen snapshot, a revoke,
//      a reissue and the credential formats
// ---------------------------------------------------------------------------
//
// The certificate this run already issued in section 6 is the subject. What is
// asserted here is everything the credential surface adds around it, and every
// assertion is guarded by the route it needs: a build that has not shipped the
// template aggregate, the reissue path or the credential formats reports each
// one pending rather than failing, and each becomes an ordinary assertion the
// moment the core registers the route.
//
// The frozen snapshot is the load-bearing one. A certificate renders from the
// template as it stood at issue and never from the live template, so
// correcting a template cannot rewrite a credential somebody already holds. A
// row issued before the snapshot existed falls back to the live template, and
// the response has to say that it did.

heading('11m. Credentials');

$certificateRow = $database->selectOne(
    Query::from('certificates')->where('enrolment_id', '=', $enrolmentId),
);
$certificateId = (int) ($certificateRow['id'] ?? 0);

assertThat(
    $certificateId > 0,
    'the run has a certificate to reason about',
    'certificate ' . $certificateId,
);

if ($certificateId > 0) {
    assertThat(
        \array_key_exists('template_snapshot', $certificateRow)
            && \array_key_exists('context_snapshot', $certificateRow)
            && \array_key_exists('artefact_path', $certificateRow),
        'the issuance row carries the provenance columns the design names',
        'template_snapshot=' . (($certificateRow['template_snapshot'] ?? null) === null ? 'null' : 'set')
            . ' context_snapshot=' . (($certificateRow['context_snapshot'] ?? null) === null ? 'null' : 'set')
            . ' status=' . (string) ($certificateRow['status'] ?? 'null'),
    );

    if (($certificateRow['template_snapshot'] ?? null) === null) {
        pending(
            'the issuance row freezes the template it was issued from',
            'this build issues without a snapshot, so the render falls back to the live template',
        );
    } else {
        $snapshot = json_decode((string) $certificateRow['template_snapshot'], true);

        assertThat(
            \is_array($snapshot) && ($snapshot['_v'] ?? null) !== null,
            'the frozen template snapshot is a versioned stored shape',
            \is_array($snapshot) ? '_v=' . var_export($snapshot['_v'] ?? null, true) : 'not an object',
        );
    }

    // The template aggregate. A page owns paper, an element owns one drawn
    // thing, and the element registry answers what may be placed on this
    // template. Created and removed inside the section.
    $templateKey = 'vidyalms.smoke.template';
    $certificateKeyPrefix = CERTIFICATE_IDEMPOTENCY_PREFIX . bin2hex(random_bytes(8)) . '-';
    $certificateMutationKey = static fn (string $operation): string
        => $certificateKeyPrefix . $operation;

    $createTemplate = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'POST',
        '/manage/certificate-templates',
        $actor,
        [
            'template_key' => $templateKey,
            'name' => 'VidyaLMS smoke template',
            'format' => 'elements',
            'renderer' => 'elements',
        ],
        idempotencyKey: $certificateMutationKey('create-template'),
    );

    $smokeTemplateId = (int) ($createTemplate->data['id'] ?? 0);
    $templateRevision = (int) ($createTemplate->data['revision'] ?? 0);

    if ($createTemplate->routeMissing() || $smokeTemplateId <= 0 || $templateRevision <= 0) {
        pending(
            'a template carries pages, and a page carries elements',
            'POST /manage/certificate-templates answers ' . $createTemplate->status . ' on this build',
        );
    } else {
        $elementTypes = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'GET',
            '/manage/certificate-templates/' . $smokeTemplateId . '/element-types',
            $actor,
        );

        assertThat(
            \count($elementTypes->rows('items')) >= 17,
            'the element registry offers the seventeen free elements',
            'element types = ' . \count($elementTypes->rows('items')),
        );

        // An elements template arrives with a page, because a designer cannot
        // open on nothing.
        $pageList = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'GET',
            '/manage/certificate-templates/' . $smokeTemplateId . '/pages',
            $actor,
        );
        $smokePageId = (int) ($pageList->rows('items')[0]['id'] ?? 0);

        assertThat(
            \count($pageList->rows('items')) === 1 && $smokePageId > 0,
            'an elements template arrives with the page a designer needs',
            'pages=' . \count($pageList->rows('items')) . ' first page ' . $smokePageId,
        );

        // Each element's own payload, because each provider validates its own:
        // a QR code with no alternative text is refused at authoring time,
        // which is SC 1.1.1 enforced where the author can still fix it rather
        // than discovered on a certificate somebody already holds.
        $elementPayloads = [
            'learner_name' => [],
            'course_name' => [],
            'verification_code' => [],
            'qr_code' => ['alt' => 'Scan to verify this certificate'],
        ];

        $placed = 0;
        $refused = [];

        foreach ($elementPayloads as $elementKey => $elementData) {
            $body = [
                'element' => $elementKey,
                'name' => $elementKey,
                'posx' => 20,
                'posy' => 20 + (10 * $placed),
                'expected_revision' => $templateRevision,
            ];

            if ($elementData !== []) {
                $body['data'] = $elementData;
            }

            $addElement = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
                'POST',
                '/manage/certificate-template-pages/' . $smokePageId . '/elements',
                $actor,
                $body,
                idempotencyKey: $certificateMutationKey('add-element-' . $elementKey),
            );

            $addedItem = $addElement->data['item'] ?? null;
            $nextRevision = (int) ($addElement->data['template_revision'] ?? 0);

            if (
                ($addElement->ok() || $addElement->status === 201)
                && \is_array($addedItem)
                && $nextRevision > $templateRevision
            ) {
                $placed++;
                $templateRevision = $nextRevision;
            } else {
                $refused[] = $elementKey . ' ' . $addElement->status . ' ' . $addElement->detail();
            }
        }

        assertThat(
            $placed === 4,
            'four elements are placed on the page',
            'placed=' . $placed . ($refused === [] ? '' : '; refused: ' . implode('; ', $refused)),
        );

        // The same element without its alternative text is refused, so the
        // rule is a rule rather than a default that happened to be filled in.
        $refusedQr = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'POST',
            '/manage/certificate-template-pages/' . $smokePageId . '/elements',
            $actor,
            [
                'element' => 'qr_code',
                'name' => 'unlabelled',
                'posx' => 20,
                'posy' => 60,
                'data' => ['alt' => ''],
                'expected_revision' => $templateRevision,
            ],
            idempotencyKey: $certificateMutationKey('refuse-unlabelled-qr'),
        );

        $refusedQrErrors = \is_array($refusedQr->data['errors'] ?? null)
            ? $refusedQr->data['errors']
            : [];

        assertThat(
            $refusedQr->status === 422
                && \array_key_exists('data', $refusedQrErrors)
                && !\array_key_exists('expected_revision', $refusedQrErrors),
            'a QR code with no alternative text is refused by its own provider',
            'status ' . $refusedQr->status . ' fields=' . implode(',', array_keys($refusedQrErrors)),
        );

        // A preview writes nothing and reports the template level findings the
        // author has to read, which is where the rule that a QR code alone is
        // not a verification affordance is enforced.
        $preview = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'POST',
            '/manage/certificate-templates/' . $smokeTemplateId . '/preview',
            $actor,
        );

        assertThat(
            $preview->ok() || $preview->status === 422,
            'a preview answers without writing anything',
            'status ' . $preview->status,
        );

        // Publication is explicit and freezes the complete owned fixture.
        // It never changes the developer site's selected default.
        $publishedTemplate = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'POST',
            '/manage/certificate-templates/' . $smokeTemplateId . '/publish',
            $actor,
            ['expected_revision' => $templateRevision],
            idempotencyKey: $certificateMutationKey('publish-template'),
        );
        $publishedTemplateRevision = (int) ($publishedTemplate->data['published_revision'] ?? 0);
        assertThat(
            $publishedTemplate->ok()
                && $publishedTemplateRevision === $templateRevision + 1
                && ($publishedTemplate->data['has_unpublished_changes'] ?? true) === false,
            'explicit publication freezes the current design at its claimed revision',
            'status ' . $publishedTemplate->status . ' published revision=' . $publishedTemplateRevision,
        );
        if ($publishedTemplate->ok()) {
            $templateRevision = (int) ($publishedTemplate->data['revision'] ?? $templateRevision);
        }
        $publicationBeforeEdit = $database->selectOne(
            Query::from('certificate_templates')->select(['published_snapshot'])->where('id', '=', $smokeTemplateId),
        )['published_snapshot'] ?? null;

        // Editing. Every write inside the aggregate moves the template's
        // revision, which is what makes an optimistic precondition able to
        // detect a lost update at all: a screen that saved against the
        // revision it read is told when somebody else saved first.
        $templateBefore = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'GET',
            '/manage/certificate-templates/' . $smokeTemplateId,
            $actor,
        );
        $revisionBefore = (int) ($templateBefore->data['revision'] ?? 0);

        assertThat(
            $revisionBefore === $templateRevision,
            'the aggregate read confirms the revision returned by child writes',
            'tracked ' . $templateRevision . ', read ' . $revisionBefore,
        );

        if ($revisionBefore > 0) {
            $templateRevision = $revisionBefore;
        }

        $renamed = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'PATCH',
            '/manage/certificate-templates/' . $smokeTemplateId,
            $actor,
            ['name' => 'VidyaLMS smoke template (edited)', 'expected_revision' => $revisionBefore],
            idempotencyKey: $certificateMutationKey('rename-template'),
        );

        $renamedRevision = (int) ($renamed->data['revision'] ?? 0);

        assertThat(
            $renamed->ok()
                && (string) ($renamed->data['name'] ?? '') === 'VidyaLMS smoke template (edited)'
                && $renamedRevision > $revisionBefore,
            'a template edit is accepted and moves the revision',
            'status ' . $renamed->status
                . ' revision ' . $revisionBefore . ' -> ' . (string) ($renamed->data['revision'] ?? 'null'),
        );

        if ($renamedRevision > $revisionBefore) {
            $templateRevision = $renamedRevision;
        }
        $publicationAfterEdit = $database->selectOne(
            Query::from('certificate_templates')->select(['published_snapshot'])->where('id', '=', $smokeTemplateId),
        )['published_snapshot'] ?? null;
        assertThat(
            $publicationBeforeEdit !== null
                && $publicationAfterEdit === $publicationBeforeEdit
                && ($renamed->data['has_unpublished_changes'] ?? false) === true,
            'editing the draft preserves the exact published design',
            'published bytes preserved=' . ($publicationAfterEdit === $publicationBeforeEdit ? 'yes' : 'no'),
        );

        $stale = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'PATCH',
            '/manage/certificate-templates/' . $smokeTemplateId,
            $actor,
            ['name' => 'Written over somebody else', 'expected_revision' => $revisionBefore],
            idempotencyKey: $certificateMutationKey('refuse-stale-template-edit'),
        );

        assertThat(
            $stale->status === 409
                && (int) ($stale->data['expected_revision'] ?? 0) === $revisionBefore
                && !\array_key_exists('current_revision', $stale->data),
            'a second save against the revision already used is refused and requires a fresh read',
            'status ' . $stale->status
                . ' expected_revision=' . (string) ($stale->data['expected_revision'] ?? 'null'),
        );

        // Reordering. A page order is the order the paper is printed in and an
        // element order is draw order, so both are written as a whole list:
        // a partial list would silently drop a page or an element from the
        // render.
        $secondPage = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'POST',
            '/manage/certificate-templates/' . $smokeTemplateId . '/pages',
            $actor,
            [
                'orientation' => 'portrait',
                'width' => 210,
                'height' => 297,
                'expected_revision' => $templateRevision,
            ],
            idempotencyKey: $certificateMutationKey('add-second-page'),
        );
        $secondPageItem = $secondPage->data['item'] ?? null;
        $secondPageId = \is_array($secondPageItem) ? (int) ($secondPageItem['id'] ?? 0) : 0;
        $secondPageRevision = (int) ($secondPage->data['template_revision'] ?? 0);

        assertThat(
            $secondPage->status === 201
                && $secondPageId > 0
                && $secondPageRevision > $templateRevision,
            'adding a page returns the page and its authoritative aggregate revision',
            'status ' . $secondPage->status
                . ' page=' . $secondPageId
                . ' revision=' . $secondPageRevision,
        );

        if ($secondPageRevision > $templateRevision) {
            $templateRevision = $secondPageRevision;
        }

        $pagesReordered = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'PUT',
            '/manage/certificate-templates/' . $smokeTemplateId . '/pages/order',
            $actor,
            ['ids' => [$secondPageId, $smokePageId], 'expected_revision' => $templateRevision],
            idempotencyKey: $certificateMutationKey('reorder-pages'),
        );

        $pagesReorderedRevision = (int) ($pagesReordered->data['template_revision'] ?? 0);

        assertThat(
            $pagesReordered->ok()
                && array_column($pagesReordered->rows('items'), 'id') === [$secondPageId, $smokePageId]
                && array_column($pagesReordered->rows('items'), 'sequence') === [1, 2]
                && $pagesReorderedRevision > $templateRevision,
            'the pages are reordered as a whole list and the sequences close up',
            'status ' . $pagesReordered->status
                . ' ids=' . implode(',', array_column($pagesReordered->rows('items'), 'id'))
                . ' revision=' . $pagesReorderedRevision,
        );

        if ($pagesReorderedRevision > $templateRevision) {
            $templateRevision = $pagesReorderedRevision;
        }

        $partialOrder = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'PUT',
            '/manage/certificate-templates/' . $smokeTemplateId . '/pages/order',
            $actor,
            ['ids' => [$smokePageId], 'expected_revision' => $templateRevision],
            idempotencyKey: $certificateMutationKey('refuse-partial-page-order'),
        );

        $partialOrderErrors = \is_array($partialOrder->data['errors'] ?? null)
            ? $partialOrder->data['errors']
            : [];

        assertThat(
            $partialOrder->status === 422
                && \array_key_exists('ids', $partialOrderErrors)
                && !\array_key_exists('expected_revision', $partialOrderErrors),
            'a partial page order is refused rather than silently dropping a page',
            'status ' . $partialOrder->status . ' fields=' . implode(',', array_keys($partialOrderErrors)),
        );

        $elements = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'GET',
            '/manage/certificate-template-pages/' . $smokePageId . '/elements',
            $actor,
        );
        $elementIds = array_column($elements->rows('items'), 'id');

        assertThat(
            \count($elementIds) === 4,
            'the page lists the elements placed on it, in draw order',
            'elements=' . \count($elementIds),
        );

        if (\count($elementIds) === 4) {
            $drawOrder = [$elementIds[3], $elementIds[0], $elementIds[1], $elementIds[2]];

            $elementsReordered = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
                'PUT',
                '/manage/certificate-template-pages/' . $smokePageId . '/elements/order',
                $actor,
                ['ids' => $drawOrder, 'expected_revision' => $templateRevision],
                idempotencyKey: $certificateMutationKey('reorder-elements'),
            );

            $elementsReorderedRevision = (int) ($elementsReordered->data['template_revision'] ?? 0);

            assertThat(
                $elementsReordered->ok()
                    && array_column($elementsReordered->rows('items'), 'id') === $drawOrder
                    && $elementsReorderedRevision > $templateRevision,
                'the elements are reordered, which is what puts a border behind a name',
                'status ' . $elementsReordered->status . ' revision=' . $elementsReorderedRevision,
            );

            if ($elementsReorderedRevision > $templateRevision) {
                $templateRevision = $elementsReorderedRevision;
            }

            $movedElement = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
                'PATCH',
                '/manage/certificate-template-elements/' . $elementIds[0],
                $actor,
                ['posx' => 42, 'posy' => 84, 'expected_revision' => $templateRevision],
                idempotencyKey: $certificateMutationKey('move-element'),
            );

            $movedItem = $movedElement->data['item'] ?? null;
            $movedRevision = (int) ($movedElement->data['template_revision'] ?? 0);

            assertThat(
                $movedElement->ok()
                    && \is_array($movedItem)
                    && (int) ($movedItem['posx'] ?? 0) === 42
                    && (int) ($movedItem['posy'] ?? 0) === 84
                    && $movedRevision > $templateRevision,
                'an element is moved and answers its new position',
                'status ' . $movedElement->status
                    . ' posx=' . (\is_array($movedItem) ? (string) ($movedItem['posx'] ?? 'null') : 'null')
                    . ' posy=' . (\is_array($movedItem) ? (string) ($movedItem['posy'] ?? 'null') : 'null')
                    . ' revision=' . $movedRevision,
            );

            if ($movedRevision > $templateRevision) {
                $templateRevision = $movedRevision;
            }
        }

        // DELETE archives and never destroys, because a certificate issued
        // from this template still verifies and its frozen snapshot still
        // renders. The row is removed from the database by --cleanup.
        $removeTemplate = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'DELETE',
            '/manage/certificate-templates/' . $smokeTemplateId,
            $actor,
            query: ['expected_revision' => (string) $templateRevision],
            idempotencyKey: $certificateMutationKey('archive-template'),
        );

        $archivedRow = $database->selectOne(
            Query::from('certificate_templates')->where('id', '=', $smokeTemplateId),
        );

        assertThat(
            $removeTemplate->ok()
                && (string) ($removeTemplate->data['state'] ?? '') === 'archived'
                && $archivedRow !== null,
            'deleting a template archives it and destroys nothing',
            'status ' . $removeTemplate->status
                . ' state=' . (string) ($removeTemplate->data['state'] ?? 'null')
                . ' row ' . ($archivedRow === null ? 'gone' : 'kept'),
        );
    }

    // The site default cannot be deleted, because something has to answer
    // when the next completion asks which template to issue from.
    $defaultTemplateRow = $database->selectOne(
        Query::from('certificate_templates')->where('is_default', '=', 1),
    );
    $defaultTemplateId = (int) ($defaultTemplateRow['id'] ?? 0);

    if ($defaultTemplateId <= 0) {
        pending(
            'the site default template cannot be deleted',
            'this site has no default certificate template row to refuse',
        );
    } else {
        $refusedDelete = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'DELETE',
            '/manage/certificate-templates/' . $defaultTemplateId,
            $actor,
            query: ['expected_revision' => (string) ((int) ($defaultTemplateRow['revision'] ?? 0))],
            idempotencyKey: $certificateMutationKey('refuse-default-archive'),
        );

        $defaultStillThere = $database->selectOne(
            Query::from('certificate_templates')->where('id', '=', $defaultTemplateId),
        );

        assertThat(
            $refusedDelete->status === 409
                && (string) ($refusedDelete->data['reason'] ?? '') === 'certificate_template.is_default'
                && $defaultStillThere !== null
                && (int) ($defaultStillThere['is_default'] ?? 0) === 1,
            'deleting the site default template is refused and the row is untouched',
            'status ' . $refusedDelete->status
                . ' reason=' . (string) ($refusedDelete->data['reason'] ?? 'null')
                . ' is_default=' . (string) ($defaultStillThere['is_default'] ?? 'null'),
        );

        // The criteria lock. Once a certificate has been issued from a
        // template, the fields that decide what it means are frozen: the same
        // template cannot mean one thing to the holder who has it and another
        // to the next one. The refusal is attempted only when the lock is
        // actually set, because an unlocked template would accept the change
        // and this harness must not rewrite a site's own default.
        $lockedAt = $defaultStillThere['locked_at'] ?? null;

        if ($lockedAt === null) {
            pending(
                'the criteria lock refuses an expiry change once a certificate has been issued',
                'the default template carries no locked_at, so nothing has been issued from it here',
            );
        } else {
            $expiryBefore = $defaultStillThere['expiry_mode'] ?? null;

            $lockRefusal = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
                'PATCH',
                '/manage/certificate-templates/' . $defaultTemplateId,
                $actor,
                [
                    'expiry_mode' => 'days_after_issue',
                    'expiry_days' => 365,
                    'expected_revision' => (int) ($defaultStillThere['revision'] ?? 0),
                ],
                idempotencyKey: $certificateMutationKey('refuse-locked-expiry'),
            );

            $expiryAfter = $database->selectOne(
                Query::from('certificate_templates')->where('id', '=', $defaultTemplateId),
            )['expiry_mode'] ?? null;

            assertThat(
                $lockRefusal->status === 409
                    && (string) ($lockRefusal->data['lock'] ?? '') === 'certificate_template.criteria_locked'
                    && $expiryAfter === $expiryBefore,
                'the criteria lock refuses an expiry change after an issuance, and writes nothing',
                'status ' . $lockRefusal->status
                    . ' lock=' . (string) ($lockRefusal->data['lock'] ?? 'null')
                    . ' expiry_mode ' . var_export($expiryBefore, true) . ' -> ' . var_export($expiryAfter, true),
            );
        }
    }

    // The credential document, by format. The free build serves two, and
    // content negotiation names what it can serve; a format it cannot is
    // absent from the negotiation rather than an error.
    $uuid = (string) ($certificateRow['uuid'] ?? '');

    if ($uuid === '') {
        pending(
            'the credential is served in each free format',
            'this build issues no credential uuid, so /credentials/{uuid} has nothing to address',
        );
    } else {
        foreach (['application/ld+json' => 'vidyalms.json', 'image/png' => 'openbadges2.baked-png'] as $accept => $formatKey) {
            $credential = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
                'GET',
                '/credentials/' . rawurlencode($uuid),
                Principal::guest(),
                [],
                [],
                null,
                null,
                ['accept' => $accept],
            );

            if ($credential->routeMissing()) {
                pending(
                    'the credential is served as ' . $formatKey,
                    'GET /credentials/{uuid} answers ' . $credential->status . ' on this build',
                );

                continue;
            }

            assertThat(
                $credential->ok() || $credential->status === 406,
                'the credential answers for ' . $formatKey . ' or names what it can serve',
                'status ' . $credential->status,
            );
        }
    }

    // The reissue, then the revoke ceremony. Order matters: a reissue of a
    // revoked certificate is not the case the design describes, so the reissue
    // runs on the active row and the revoke on whatever is active after it.
    $verifyCode = (string) ($certificateRow['code'] ?? '');

    $reissue = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'POST',
        '/admin/certificates/' . $certificateId . '/reissue',
        $actor,
    );

    if ($reissue->routeMissing()) {
        pending(
            'a reissue supersedes the old row and the old code still resolves',
            'POST /admin/certificates/{id}/reissue answers ' . $reissue->status . ' on this build',
        );
    } else {
        assertThat(
            $reissue->ok() || $reissue->status === 201,
            'a reissue is accepted',
            'status ' . $reissue->status . ($reissue->ok() ? '' : ': ' . $reissue->detail()),
        );

        $superseded = $database->selectOne(Query::from('certificates')->where('id', '=', $certificateId));

        assertThat(
            (string) ($superseded['status'] ?? '') === 'superseded'
                && (int) ($superseded['superseded_by_id'] ?? 0) > 0
                && ($superseded['active_enrolment_key'] ?? null) === null,
            'the replaced row is superseded, names its successor and releases the active key',
            'status=' . (string) ($superseded['status'] ?? 'null')
                . ' superseded_by=' . (string) ($superseded['superseded_by_id'] ?? 'null')
                . ' active_key=' . (($superseded['active_enrolment_key'] ?? null) === null ? 'null' : 'set'),
        );

        $oldCode = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'GET',
            '/certificates/' . rawurlencode($verifyCode) . '/verify',
            Principal::guest(),
        );

        assertThat(
            $oldCode->ok() && $oldCode->string('status') === 'superseded',
            'the old verification code keeps resolving and says it was replaced',
            'status ' . $oldCode->status . ' credential status=' . $oldCode->string('status', 'null'),
        );
    }

    $activeRow = $database->selectOne(
        Query::from('certificates')
            ->where('enrolment_id', '=', $enrolmentId)
            ->where('status', '=', 'active'),
    );
    $activeId = (int) ($activeRow['id'] ?? $certificateId);
    $activeCode = (string) ($activeRow['code'] ?? $verifyCode);

    // Suspension is the reversible hold, and it is a different answer to a
    // relying party from revocation: a payment dispute or a plagiarism
    // investigation is not "withdrawn".
    $suspend = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'POST',
        '/admin/certificates/' . $activeId . '/suspend',
        $actor,
        ['reason' => 'under_review'],
    );

    if ($suspend->routeMissing()) {
        pending(
            'a suspension is a reversible hold and a reinstatement lifts it',
            'POST /admin/certificates/{id}/suspend answers ' . $suspend->status . ' on this build',
        );
    } else {
        $suspended = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'GET',
            '/certificates/' . rawurlencode($activeCode) . '/verify',
            Principal::guest(),
        );

        $reinstate = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'POST',
            '/admin/certificates/' . $activeId . '/reinstate',
            $actor,
        );

        $reinstated = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
            'GET',
            '/certificates/' . rawurlencode($activeCode) . '/verify',
            Principal::guest(),
        );

        assertThat(
            $suspend->ok()
                && $suspended->string('status') === 'suspended'
                && $reinstate->ok()
                && $reinstated->string('status') === 'active',
            'a suspension is a reversible hold and a reinstatement lifts it',
            'suspend ' . $suspend->status . ' then status=' . $suspended->string('status', 'null')
                . ', reinstate ' . $reinstate->status . ' then status=' . $reinstated->string('status', 'null'),
        );
    }

    $revoke = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'POST',
        '/admin/certificates/' . $activeId . '/revoke',
        $actor,
        ['reason' => 'issued_in_error'],
    );

    assertThat(
        $revoke->ok() || $revoke->status === 204,
        'a revoke is accepted with a reason',
        'status ' . $revoke->status . ($revoke->ok() ? '' : ': ' . $revoke->detail()),
    );

    $afterRevoke = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'GET',
        '/certificates/' . rawurlencode($activeCode) . '/verify',
        Principal::guest(),
    );

    assertThat(
        $afterRevoke->ok() && $afterRevoke->bool('valid') === false,
        'the revoked certificate verifies as not valid, and the endpoint still answers 200',
        'status ' . $afterRevoke->status
            . ' valid=' . var_export($afterRevoke->bool('valid'), true)
            . ' credential status=' . $afterRevoke->string('status', 'null')
            . ' revoked=' . var_export($afterRevoke->bool('revoked'), true),
    );

    $unknownCode = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'GET',
        '/certificates/NOTACODE1234/verify',
        Principal::guest(),
    );

    assertThat(
        $unknownCode->status === 200 && $unknownCode->bool('valid') === false,
        'an unknown code answers 200 and not valid, so probing reveals nothing structural',
        'status ' . $unknownCode->status,
    );

    // The fixture is left with exactly one active certificate, because that is
    // the state every later run and the parity checks read. A revoked
    // certificate can be replaced, which is the correction path an "issued in
    // error" revocation is the first half of.
    $replace = \Shondalai\VidyaLMS\WordPress\Content\CoreRoute::call(
        'POST',
        '/admin/certificates/' . $activeId . '/reissue',
        $actor,
    );

    if (!$replace->routeMissing()) {
        $stillActive = $database->count(
            Query::from('certificates')
                ->where('enrolment_id', '=', $enrolmentId)
                ->where('status', '=', 'active'),
        );

        assertThat(
            ($replace->ok() || $replace->status === 201) && $stillActive === 1,
            'a revoked certificate can be replaced, and the enrolment holds one active row again',
            'status ' . $replace->status . ', active rows = ' . $stillActive,
        );
    }
}



// ---------------------------------------------------------------------------
// 11n. The host facts a credential is built from
// ---------------------------------------------------------------------------
//
// This process has no request. Neither does WP-Cron, and neither does WP-CLI,
// and issuance runs on all three. So every URL a credential quotes is composed
// from the stored home option rather than from whatever host header happened
// to arrive: an id inside a signed document can never be corrected afterwards,
// because the only party who could is the wallet holding it.
//
// The Joomla adapter reaches the same place by a different road, because
// Uri::root() there is composed from the request and answers the path of the
// CLI entry point when there is none. Both hosts end up handing the kernel a
// credential base, a status list base and an issuer that a scheduled run and a
// browser request agree on.

heading('11n. Host facts behind a credential');

$storedHome = rtrim((string) get_option('home'), '/');
$credentialBase = $kernel->credentialBaseUrl;
$statusListBase = $kernel->statusListBaseUrl;

assertThat(
    $credentialBase !== '' && str_starts_with($credentialBase, $storedHome),
    'the credential base is composed from the stored home option and not from this process',
    'home ' . $storedHome . ', credential base ' . ($credentialBase === '' ? '(empty)' : $credentialBase),
);

assertThat(
    $credentialBase === rtrim(rest_url(RestController::REST_NAMESPACE . '/credentials'), '/'),
    'the credential base is this site\'s own REST mount, which is what serves the document',
    'expected ' . rtrim(rest_url(RestController::REST_NAMESPACE . '/credentials'), '/'),
);

assertThat(
    $statusListBase !== '' && str_starts_with($statusListBase, $credentialBase),
    'the status list is published under the same base, so a verifier follows one host',
    'status list base ' . ($statusListBase === '' ? '(empty)' : $statusListBase),
);

$frozenRow = $database->selectOne(
    Query::from('certificates')
        ->where('enrolment_id', '=', $enrolmentId)
        ->where('status', '=', 'active'),
);
$frozenJson = $frozenRow['credential_json'] ?? null;

if (!\is_string($frozenJson) || $frozenJson === '') {
    assertThat(
        false,
        'the certificate issued by this run froze its credential onto the row',
        'credential_json is ' . ($frozenJson === null ? 'null' : 'empty')
            . ', credential_format=' . (string) ($frozenRow['credential_format'] ?? 'null'),
    );
} else {
    $frozen = json_decode($frozenJson, true);
    $frozen = \is_array($frozen) ? $frozen : [];
    $frozenId = (string) ($frozen['id'] ?? '');
    $frozenUuid = (string) ($frozenRow['uuid'] ?? '');

    assertThat(
        $frozenId !== ''
            && str_starts_with($frozenId, $credentialBase . '/')
            && str_contains($frozenId, $frozenUuid),
        'the credential minted in this request-less process carries a resolvable id',
        'id ' . ($frozenId === '' ? '(empty)' : $frozenId),
    );

    // The issuer. Every credential this build used to emit named nobody:
    // {"id": "", "name": ""} on the free format and "urn:vidyalms:issuer"
    // with an empty name on the signed one, because the settings row it read
    // was written by no adapter, no screen and no route. A verifier reading
    // the badge could not tell who awarded it.
    $issuer = $frozen['issuer'] ?? [];
    $issuer = \is_array($issuer) ? $issuer : [];

    assertThat(
        (string) ($issuer['id'] ?? '') !== '' && (string) ($issuer['name'] ?? '') !== '',
        'the credential names an issuer rather than an anonymous one',
        'issuer id ' . var_export($issuer['id'] ?? null, true)
            . ' name ' . var_export($issuer['name'] ?? null, true),
    );

    assertThat(
        (string) ($issuer['name'] ?? '') === trim((string) get_bloginfo('name')),
        'the issuer defaults to this site, which is the fact WordPress has and the core cannot',
        'site name ' . trim((string) get_bloginfo('name')),
    );
}

// The seeded default template's stable key. 1.7.0 added template_key without
// filling it, so on every upgraded site the shipped default carried NULL and a
// lookup by the shipped key missed, while a fresh install found it. A schema
// drift check cannot see this: both sides match structurally and only the row
// differs. The plugin's own one-shot data step is what closes it.
$seededDefault = $database->selectOne(
    Query::from('certificate_templates')->where('is_default', '=', 1),
);

assertThat(
    (string) ($seededDefault['template_key'] ?? '') === 'vidyalms.default',
    'the seeded default template carries the key the core looks it up by',
    'template_key=' . var_export($seededDefault['template_key'] ?? null, true),
);

assertThat(
    get_option(Activator::OPTION_SEEDED_DATA_VERSION) === Activator::SEEDED_DATA_VERSION,
    'the data reconciliation records that it ran, so it runs once rather than on every request',
    'marker=' . var_export(get_option(Activator::OPTION_SEEDED_DATA_VERSION), true),
);

// The reconciliation sweep for issuance. Section 11i already asserted it is
// among the handlers one turn of the clock starts; what is left is that the
// clock itself is scheduled, and that a second activation does not schedule a
// second one.
assertThat(
    wp_next_scheduled(Plugin::HOOK_RUN_SWEEPS) !== false,
    'the recurring event that starts every registered sweep is scheduled on this site',
    'next run ' . var_export(wp_next_scheduled(Plugin::HOOK_RUN_SWEEPS), true),
);

$sweepEvents = 0;
$cronArray = _get_cron_array();

if (\is_array($cronArray)) {
    foreach ($cronArray as $hooks) {
        if (\is_array($hooks) && isset($hooks[Plugin::HOOK_RUN_SWEEPS])) {
            $sweepEvents += \count((array) $hooks[Plugin::HOOK_RUN_SWEEPS]);
        }
    }
}

assertThat(
    $sweepEvents === 1,
    'the host schedules exactly one sweeps event, however many times the plugin is activated',
    Plugin::HOOK_RUN_SWEEPS . ' events = ' . $sweepEvents,
);

// Content negotiation. WordPress does not gate a REST route on Accept at all:
// one route matches the whole contract and the core owns every 406, so there
// is no host list here to drift from the registry the way the Joomla mount's
// used to. What both hosts do share is the binary response convention, which
// used to be an untyped array literal specified by a comment and sniffed for
// separately in each adapter. Two emitters had to agree forever on a rule
// written down nowhere, and a drift between them serves base64 text where a
// wallet expected a PNG. The core says so on the object now, and both mounts
// ask.
$pngUuid = (string) ($frozenRow['uuid'] ?? '');

if ($pngUuid === '') {
    pending(
        'a credential served as bytes says so on the response rather than being sniffed for',
        'this run has no credential uuid to address',
    );
} else {
    $pngResponse = $kernel->apiKernel()->handle(new \Shondalai\VidyaLMS\Core\Http\ApiRequest(
        'GET',
        '/credentials/' . $pngUuid,
        Principal::guest(),
        [],
        [],
        null,
        [],
        null,
        null,
        ['accept' => 'image/png'],
        '127.0.0.1',
    ));

    assertThat(
        $pngResponse->status === 200
            && $pngResponse->isBinary()
            && str_starts_with($pngResponse->binaryBytes(), "\x89PNG"),
        'a credential served as bytes says so on the response, and the bytes are the image',
        'status ' . $pngResponse->status
            . ' isBinary=' . var_export($pngResponse->isBinary(), true)
            . ' first bytes=' . bin2hex(substr($pngResponse->binaryBytes(), 0, 4)),
    );

    $servable = $kernel->getCredential->servableMediaTypes();

    assertThat(
        \in_array('image/png', $servable, true) && \in_array('application/ld+json', $servable, true),
        'the build names what it can serve from the format registry rather than from a list a host holds',
        'servable: ' . implode(', ', $servable),
    );
}


// ---------------------------------------------------------------------------
// 11o. The free seat subsystem
// ---------------------------------------------------------------------------
//
// The same contract body the Joomla harness runs, against this host's ports.
// Everything in it is arithmetic over rows, and the two hosts count rows with
// different drivers, so a section written twice would be two chances for one
// of them to count differently: a signed ledger sum, a state count and a hash
// chain all ride on it.
//
// The contract seeds two organisations of its own, so the alert half can ask
// who an exhausting pool reached and, more importantly, who it did not. It
// removes every row it wrote in a finally, including its ledger rows: the
// ledger is append-only for a site's own history, and an organisation created
// and destroyed inside one run has none.

heading('11o. Seats, invitations and the reclaim ceremony');

$seatCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke seat course',
    SEAT_COURSE_SLUG,
    'A course created by build/smoke.php so the seat contract has something to fund.',
    '<p>Seeded by the VidyaLMS WordPress adapter smoke test.</p>',
);
$seatCourseId = (int) $seatCourse->id;
$seatModule = $kernel->addModule->execute($actor, $seatCourseId, 'Seats');
$kernel->addLesson->execute(
    $actor,
    (int) $seatModule->id,
    'What a seat funds',
    'reading',
    ['body' => '<p>Read this, then take the quiz.</p>'],
);
$kernel->publishCourse->execute($actor, $seatCourseId);

$seatAccounts = [
    'admin_a' => makeUser(SEAT_ADMIN_A_LOGIN, SEAT_ADMIN_A_EMAIL, 'subscriber', 'VidyaLMS Smoke Seat Admin A'),
    'admin_b' => makeUser(SEAT_ADMIN_B_LOGIN, SEAT_ADMIN_B_EMAIL, 'subscriber', 'VidyaLMS Smoke Seat Admin B'),
    'learner_one' => makeUser(SEAT_LEARNER_ONE_LOGIN, SEAT_LEARNER_ONE_EMAIL, 'subscriber', 'VidyaLMS Smoke Seat Learner One'),
    'learner_two' => makeUser(SEAT_LEARNER_TWO_LOGIN, SEAT_LEARNER_TWO_EMAIL, 'subscriber', 'VidyaLMS Smoke Seat Learner Two'),
];

step(
    'seat contract fixture',
    'course ' . $seatCourseId . ', accounts ' . implode(', ', array_values($seatAccounts)),
);

['failures' => $seatFailures, 'facts' => $seatFacts] =
    \Shondalai\VidyaLMS\Tooling\Contract\OrganisationSeatContract::run(
        $kernel,
        Plugin::ports(),
        $actor,
        $seatAccounts,
        $seatCourseId,
    );

foreach ($seatFacts as $seatFact) {
    out('    ' . $seatFact);
}

foreach ($seatFailures as $seatFailure) {
    out('    ' . $seatFailure);
}

assertThat(
    $seatFailures === [],
    'the pool machine, reserve at issue, the ceremony, the transfer, the alert and the reconciliation',
    $seatFailures === []
        ? \count($seatFacts) . ' facts recorded, 0 expectations broken'
        : \count($seatFailures) . ' expectation(s) broken',
);

// Both organisations are gone by now, so nothing of this section survives but
// the course and the four accounts, which go here.
foreach ($seatAccounts as $seatUserId) {
    $database->delete('notification_queue', ['recipient_user_id' => $seatUserId]);
    $database->delete('activity_log', ['user_id' => $seatUserId]);
    $database->delete('email_log', ['user_id' => $seatUserId]);
}

foreach (SEAT_ACCOUNT_LOGINS as $seatLogin) {
    step('seat contract account ' . $seatLogin, removeUser($seatLogin));
}

step('seat course rows removed', (string) removeSmokeCourse($database, $seatCourseId) . ' row(s)');


// ---------------------------------------------------------------------------
// 11b. The organisation import surface and the row level error model
// ---------------------------------------------------------------------------
//
// The same body of assertions the Joomla harness runs, against wpdb rather
// than Joomla's query builder: an organisation-scoped load with one malformed
// row that ends completed_with_problems, the paged problems page, the
// failed-rows download with its hostile cell neutralised, the dry pass writing
// nothing, the site list still serving a job that belongs to no organisation,
// and the six format importers refusing honestly on a host where none of their
// products is installed.
//
// It runs last of the sections that write, because it registers an importer
// into the booted registry for the length of the process and the Import screen
// section counts that registry. Everything it creates it removes in its own
// finally; the admin_access_log rows its GETs write are append-only and are
// reported.

heading('11b. Organisation imports, row problems and the failed rows download');

['failures' => $importFailures, 'facts' => $importFacts, 'access_log_rows_added' => $importAccessRows] =
    \Shondalai\VidyaLMS\Tooling\Contract\OrganisationImportContract::run($kernel, Plugin::ports(), $actor);

foreach ($importFacts as $importFact) {
    out('    ' . $importFact);
}

foreach ($importFailures as $importFailure) {
    out('    ' . $importFailure);
}

assertThat(
    $importFailures === [],
    'the organisation import journey, the row report, the download, the dry pass and the six refusals',
    $importFailures === []
        ? \count($importFacts) . ' facts recorded, 0 expectations broken'
        : \count($importFailures) . ' expectation(s) broken',
);

step('admin_access_log rows the import journey added', $importAccessRows . ' (append-only, kept)');

// R5. One journey over the identity, rostering and governance surface, in the
// same shape as 10s and for the same reason: every one of these rows is
// written on one side of a tenancy predicate and read back on the other,
// through this host's own driver, and three of the columns are JSON that the
// two drivers hand back differently. It seeds its own organisation, its own
// OneRoster package and its own fixture people, and removes every row it
// wrote; the admin_access_log rows its GETs append are reported.

heading('10t. Organisation identity, rostering, reports, compliance, retention and erasure');

// The three people the OneRoster package names, seeded as host accounts before
// the contract runs. The importer matches a roster row to a host account and
// skips one it cannot resolve as a referential miss, so without these the
// roster half of the contract loads nothing and reports it as this host's
// fault: "grace 0, alan 0; the caller must seed them". The Joomla harness has
// seeded them since the contract shipped and this one never did, which is why
// the WordPress half of this section had never passed.
$rosterAccounts = [];

foreach (['ada' => 'Ada Byron', 'grace' => 'Grace Hopper', 'alan' => 'Alan Turing'] as $given => $rosterName) {
    $rosterMarker = \Shondalai\VidyaLMS\Tooling\Contract\OrganisationIdentityContract::MARKER;
    $rosterAccounts[$given] = makeUser(
        str_replace('-', '_', $given . '_' . $rosterMarker),
        $given . '-' . $rosterMarker . '@example.test',
        'subscriber',
        $rosterName
    );
}

step(
    'roster contract fixture',
    \count($rosterAccounts) . ' account(s): ' . implode(', ', array_values($rosterAccounts)),
);

['failures' => $identityFailures, 'facts' => $identityFacts, 'access_log_rows_added' => $identityAccessRows] =
    \Shondalai\VidyaLMS\Tooling\Contract\OrganisationIdentityContract::run($kernel, $ports, $actor);

foreach ($identityFacts as $identityFact) {
    out('    ' . $identityFact);
}

foreach ($identityFailures as $identityFailure) {
    out('    ' . $identityFailure);
}

assertThat(
    $identityFailures === [],
    'the unverified connection and domain, the evaluator decision on the event, the OneRoster tombstone,'
        . ' the three reports with the deny list, compliance with no rule, the retention refusal and the receipt',
    $identityFailures === []
        ? \count($identityFacts) . ' facts recorded, 0 expectations broken'
        : \count($identityFailures) . ' expectation(s) broken',
);

step('admin_access_log rows the identity journey added', $identityAccessRows . ' (append-only, kept)');

foreach ($rosterAccounts as $rosterUserId) {
    $database->delete('notification_queue', ['recipient_user_id' => $rosterUserId]);
    $database->delete('activity_log', ['user_id' => $rosterUserId]);
    $database->delete('email_log', ['user_id' => $rosterUserId]);
}

foreach (array_keys($rosterAccounts) as $rosterGiven) {
    removeUser(str_replace(
        '-',
        '_',
        $rosterGiven . '_' . \Shondalai\VidyaLMS\Tooling\Contract\OrganisationIdentityContract::MARKER
    ));
}

step('roster contract accounts removed', \count($rosterAccounts) . ' account(s)');

// The half a shared contract cannot check: whether this host seated its own
// asserted-identity port. The contract runs against whatever ports it is
// handed, so "the free identity seam is live on this site" is the adapter's
// own claim to make.
assertThat(
    !$ports->assertedIdentity instanceof \Shondalai\VidyaLMS\Core\Port\Identity\NoAssertedIdentity,
    'this host seats its own asserted-identity source rather than the refusing default',
    $ports->assertedIdentity::class,
);

// The same half on this host. R5 shipped the port, the source and the use case
// and nothing called it after a login; wp_login is where that is closed.
assertThat(
    has_action(\Shondalai\VidyaLMS\WordPress\Identity\HostAssertion::HOOK) !== false,
    'this host calls the core after a login, so the free identity clause has a caller',
    'a listener is registered on ' . \Shondalai\VidyaLMS\WordPress\Identity\HostAssertion::HOOK,
);

// R6. One journey over the free automation engine, in the same shape as 10s and
// 10t and for the same reasons. The engine writes eight tables through this
// host's own driver, three of its columns are JSON the two drivers hand back
// differently, and the one effect it produces lands in notification_queue,
// which this site is holding a live backlog in. It authors its own rule, arms
// it, rehearses it, fires it once for real, replays it, disarms it, and removes
// every row it wrote including the one queued notification.

heading('10u. The free automation engine: a rule armed, rehearsed, fired, replayed and disarmed');

['failures' => $automationFailures, 'facts' => $automationFacts, 'queue_rows_added' => $automationQueueRows] =
    \Shondalai\VidyaLMS\Tooling\Contract\AutomationEngineContract::run($kernel, $ports, $actor);

foreach ($automationFacts as $automationFact) {
    out('    ' . $automationFact);
}

foreach ($automationFailures as $automationFailure) {
    out('    ' . $automationFailure);
}

assertThat(
    $automationFailures === [],
    'the rule authored and armed, the dry run writing nothing, the real run producing exactly one queue row,'
        . ' the replay producing none, and the disarm cancelling what was still queued',
    $automationFailures === []
        ? \count($automationFacts) . ' facts recorded, 0 expectations broken'
        : \count($automationFailures) . ' expectation(s) broken',
);

step('notification_queue rows the automation journey produced', $automationQueueRows . ' (removed again by the section)');

// The three automation task types reach this host's clock without this host
// naming one, which is the claim the whole provisioning design rests on.
$automationTaskTypes = [];

foreach (
    [\Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationSweepTask::TYPE,
    \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationFanoutTask::TYPE,
    \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationStepTask::TYPE] as $automationTaskType
) {
    if (
        $kernel->registries->scheduledTasks->has($automationTaskType)
        && $kernel->registries->scheduledTasks->get($automationTaskType)
            instanceof \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically
    ) {
        $automationTaskTypes[] = $automationTaskType;
    }
}

assertThat(
    \count($automationTaskTypes) === 3,
    'all three automation task types are registered on this site and all three are started by the clock',
    implode(', ', $automationTaskTypes),
);


// ---------------------------------------------------------------------------
// 10w. The free AI build
// ---------------------------------------------------------------------------
//
// R8's whole free obligation in one journey, over the shipped writers against
// real MySQL: a site that never buys a model still has working AI tooling, and
// a site that never turns it on sends nothing anywhere. All four levels of
// enablement off on arrival and turned on by a named person, a deterministic
// check run to completion over the null bridge, the dry run persisting exactly
// what would be sent and calling nothing, a generated question draft no learner
// read path reaches, an acceptance that writes the question and its provenance
// in one transaction, the one stop above all four levels, and the learner
// reading back the run that named them.
//
// It writes to twelve tables that were empty until 1.26.0 and removes every
// row it wrote in its own finally, restoring every
// enablement row to the value it found.
//
// **No model is contacted and no key is read.** The free build's only registered
// bridge is the null one, which is the point rather than a limitation.

heading('10w. The free AI build: four levels, a deterministic run, a draft accepted and a stop');

$aiCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke AI course',
    AI_COURSE_SLUG,
    'A course created by build/smoke.php so the AI spine has something to run over.',
    '<p>Seeded by the VidyaLMS WordPress adapter smoke test.</p>',
);
$aiCourseId = (int) $aiCourse->id;
$aiModule = $kernel->addModule->execute($actor, $aiCourseId, 'What the checks read');
$kernel->addLesson->execute(
    $actor,
    (int) $aiModule->id,
    'The first lesson',
    'reading',
    ['body' => '<p>Read this before the reading level check runs over it.</p>'],
);
$kernel->publishCourse->execute($actor, $aiCourseId);

step('AI fixture', 'course ' . $aiCourseId . ', learner ' . $learnerId);

['failures' => $aiFailures, 'facts' => $aiFacts, 'runs' => $aiRuns, 'drafts_accepted' => $aiAccepted] =
    \Shondalai\VidyaLMS\Tooling\Contract\AiFreeBuildContract::run(
        $kernel,
        $ports,
        $actor,
        $aiCourseId,
        $learnerId,
    );

foreach ($aiFacts as $aiFact) {
    out('    ' . $aiFact);
}

foreach ($aiFailures as $aiFailure) {
    out('    ' . $aiFailure);
}

assertThat(
    $aiFailures === [],
    'the four levels off then on, the dry run sending nothing, the check completing over the null bridge,'
        . ' the draft unreachable by a learner, the acceptance writing its provenance, and the stop',
    $aiFailures === []
        ? \count($aiFacts) . ' facts recorded, 0 expectations broken'
        : \count($aiFailures) . ' expectation(s) broken',
);

step('AI runs the section made and removed again', $aiRuns . ' run(s), ' . $aiAccepted . ' draft(s) accepted');

// The free build ships exactly one bridge, and it is the one with nothing to
// call. A site that grew a second one without anybody installing a package
// would be a site sending text somewhere nobody reviewed.
$bridgeKeys = $kernel->registries->modelBridges->keys();
$foreignBridges = providersOutsideTheFreeBuild($kernel->registries->modelBridges, ['null']);

assertThat(
    $proInstalled
        ? \in_array('null', $bridgeKeys, true)
            && $foreignBridges === array_values(array_diff($bridgeKeys, ['null']))
        : $bridgeKeys === ['null'],
    $proInstalled
        ? 'the null bridge is registered and every other bridge came from outside the free build'
        : 'the free build offers exactly one model bridge, and its key is null',
    implode(', ', $bridgeKeys),
);

// The five AI task types reach this host's clock without this host naming one,
// which is the claim the whole provisioning design rests on. Three are started
// by the clock; the other two are started by an event and reach the same
// handler registry through the drain, so both halves are counted here.
$aiClockTypes = [];
$aiQueuedTypes = [];

foreach (
    [\Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AiReconcileTask::TYPE,
    \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AiRetentionTask::TYPE,
    \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AiStaleDraftsTask::TYPE] as $aiTaskType
) {
    if (
        $kernel->registries->scheduledTasks->has($aiTaskType)
        && $kernel->registries->scheduledTasks->get($aiTaskType)
            instanceof \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically
    ) {
        $aiClockTypes[] = $aiTaskType;
    }
}

foreach (
    [\Shondalai\VidyaLMS\Core\Application\Ai\QueueAiJob::TASK_TYPE,
    \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AiChunkTask::TYPE] as $aiQueuedType
) {
    if ($kernel->registries->scheduledTasks->has($aiQueuedType)) {
        $aiQueuedTypes[] = $aiQueuedType;
    }
}

assertThat(
    \count($aiClockTypes) === 3 && \count($aiQueuedTypes) === 2,
    'three AI task types are started by this host\'s clock and two by an event, all five through one registry',
    implode(', ', $aiClockTypes) . ' | ' . implode(', ', $aiQueuedTypes),
);

// The twelve tables 1.26.0 creates exist on this site, and none of them carries
// a score, a confidence or a risk. The absence is the design statement, and a
// host migration is where one would first appear.
$aiTables = [
    'ai_routes', 'ai_policies', 'ai_policy_acceptances', 'ai_runs', 'ai_run_attempts',
    'ai_run_subjects', 'ai_drafts', 'ai_draft_findings', 'ai_artefact_provenance',
    'ai_grounding_chunks', 'ai_jobs', 'ai_job_map',
];
$aiPresent = 0;
$aiForbidden = [];

$aiTableList = (array) $wpdb->get_col(
    $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'vidyalms_ai_') . '%')
);

foreach ($aiTables as $aiTable) {
    $aiPrefixed = $wpdb->prefix . 'vidyalms_' . $aiTable;

    if (!\in_array($aiPrefixed, $aiTableList, true)) {
        continue;
    }

    $aiPresent++;

    foreach ((array) $wpdb->get_col('DESCRIBE `' . $aiPrefixed . '`') as $aiColumn) {
        if (\in_array($aiColumn, ['ai_score', 'ai_confidence', 'ai_risk'], true)) {
            $aiForbidden[] = $aiTable . '.' . (string) $aiColumn;
        }
    }
}

$aiVectorTablePresent = \in_array($wpdb->prefix . 'vidyalms_ai_chunk_vectors', $aiTableList, true);

assertThat(
    $aiPresent === 12 && $aiForbidden === [],
    'all twelve AI tables exist on this site and none carries a score, a confidence or a risk column',
    $aiPresent . ' of 12 present, forbidden columns: '
        . ($aiForbidden === [] ? 'none' : implode(', ', $aiForbidden)),
);

// The thirteenth belongs to the paid half. On a free build it is deliberately
// absent, because an empty table would promise a capability the free build does
// not have; on a site that installed Pro it has to be there, because the vector
// index has nowhere else to write. This harness runs on either edition, so both
// directions are asserted rather than the free one alone.
assertThat(
    $aiVectorTablePresent === $proInstalled,
    $proInstalled
        ? 'ai_chunk_vectors exists because Pro is installed, and nothing free writes it'
        : 'ai_chunk_vectors does not exist on a free build, because nothing free could fill it',
    ($aiVectorTablePresent ? 'present' : 'absent') . ', pro ' . ($proInstalled ? 'installed' : 'not installed'),
);

step('AI course rows removed', (string) removeSmokeCourse($database, $aiCourseId) . ' row(s)');

// ---------------------------------------------------------------------------
// 10x. The free reporting half
// ---------------------------------------------------------------------------
//
// R9b's whole free obligation in one journey, over the shipped writers against
// real MySQL: the gradebook's three stored things written by the listener that
// hears a submission marked, a regrade in the append-only change log, the
// aggregation registry folding the stored cells, the learner timeline paged on
// the exact keyset comparison, and one schedule rendering per recipient into
// the notification queue with both organisation crossings refused before a run
// row opens.
//
// It runs after 10w because it writes to four tables that were empty until
// 1.27.0, and everything it writes goes inside a transaction it rolls back;
// the one thing a transaction cannot undo is a file, so the section removes
// every artefact the export pipeline wrote by path.
//
// **No report mailer exists and none is built.** The artefact reaches its
// recipient through the notification queue, which is what the section asserts:
// a second mailer beside the one in the product would leave no queue row here
// at all.

heading('10x. The free reporting half: a gradebook, a regrade, a timeline and a schedule');

$gradebookCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke gradebook course',
    GRADEBOOK_COURSE_SLUG,
    'A course created by build/smoke.php so the gradebook has something to grade.',
    '<p>Seeded by the VidyaLMS WordPress adapter smoke test.</p>',
);
$gradebookCourseId = (int) $gradebookCourse->id;
$gradebookModule = $kernel->addModule->execute($actor, $gradebookCourseId, 'What is marked');
$kernel->addLesson->execute(
    $actor,
    (int) $gradebookModule->id,
    'The first lesson',
    'reading',
    ['body' => '<p>Read this before the assignment.</p>'],
);
$kernel->publishCourse->execute($actor, $gradebookCourseId);

step('gradebook fixture', 'course ' . $gradebookCourseId . ', learner ' . $learnerId);

['failures' => $gradebookFailures, 'facts' => $gradebookFacts, 'runs' => $gradebookRuns] =
    \Shondalai\VidyaLMS\Tooling\Contract\GradebookReportingContract::run(
        $kernel,
        $ports,
        $actor,
        $gradebookCourseId,
        $learnerId,
    );

foreach ($gradebookFacts as $gradebookFact) {
    out('    ' . $gradebookFact);
}

foreach ($gradebookFailures as $gradebookFailure) {
    out('    ' . $gradebookFailure);
}

assertThat(
    $gradebookFailures === [],
    'the graded submission filling the gradebook, the regrade in the log, the aggregation, the timeline'
        . ' walk, the per recipient render and both refusals',
    $gradebookFailures === []
        ? \count($gradebookFacts) . ' facts recorded, 0 expectations broken'
        : \count($gradebookFailures) . ' expectation(s) broken',
);

step('scheduled report runs the section made and rolled back', $gradebookRuns . ' run(s)');

// reports.schedule reaches this host's clock without this host naming it, which
// is the claim the whole provisioning design rests on and the reason a schedule
// that never fires would be silent. There is no cron event for it and there
// never will be: it is started by the hourly sweeps event and run by the drain,
// exactly as every other registered periodic type is.
$reportTaskType = \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask::KEY;
$reportTaskPeriodic = $kernel->registries->scheduledTasks->has($reportTaskType)
    && $kernel->registries->scheduledTasks->get($reportTaskType)
        instanceof \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;

assertThat(
    $reportTaskPeriodic,
    'reports.schedule is registered on this site and is started by the clock, with no cron event of its own',
    $reportTaskType . ($reportTaskPeriodic ? ' is periodic' : ' is missing or is not periodic'),
);

// And the two events that start it exist. The plugin re-creates a missing one
// on every init, so a site that lost them to a cron plugin repairs itself; a
// site where they are absent here is a site where nothing periodic runs at all.
$sweepEvent = wp_next_scheduled(Plugin::HOOK_RUN_SWEEPS);
$drainEvent = wp_next_scheduled(Plugin::HOOK_RUN_JOBS);

assertThat(
    $sweepEvent !== false && $drainEvent !== false,
    'both cron events every registered task type rides are scheduled on this site',
    'sweeps=' . ($sweepEvent === false ? 'missing' : gmdate('Y-m-d H:i:s', (int) $sweepEvent))
        . ' drain=' . ($drainEvent === false ? 'missing' : gmdate('Y-m-d H:i:s', (int) $drainEvent)),
);

// The four tables 1.27.0 creates exist on this site, and grade_changes is the
// one with no updated_at. That absence is the design statement, and a host
// migration is where one would first appear.
$gradebookTables = ['report_schedules', 'grade_items', 'grade_cells', 'grade_changes'];
$gradebookPresent = 0;
$changeLogTouchable = false;

foreach ($gradebookTables as $gradebookTable) {
    $gradebookPrefixed = $wpdb->prefix . 'vidyalms_' . $gradebookTable;
    $gradebookExists = (array) $wpdb->get_col(
        $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($gradebookPrefixed))
    );

    if ($gradebookExists === []) {
        continue;
    }

    $gradebookPresent++;

    if (
        $gradebookTable === 'grade_changes'
        && \in_array('updated_at', (array) $wpdb->get_col('DESCRIBE `' . $gradebookPrefixed . '`'), true)
    ) {
        $changeLogTouchable = true;
    }
}

assertThat(
    $gradebookPresent === 4 && !$changeLogTouchable,
    'all four 1.27.0 tables exist on this site and the append-only log carries no updated_at',
    $gradebookPresent . ' of 4 present, grade_changes.updated_at '
        . ($changeLogTouchable ? 'present' : 'absent'),
);

step('gradebook course rows removed', (string) removeSmokeCourse($database, $gradebookCourseId) . ' row(s)');

// R11a. The two free organisation gaps R5 left open, in the same shape as 10s
// and 10t and for the same reasons. org_catalogue_entries is the last
// organisation table the design named and nobody had built, its params column
// is JSON the two drivers hand back differently, and every read of it is
// bounded by a tenancy predicate this host's own driver compiles. The
// compliance half is the older gap: nothing in the product wrote
// compliance_requirements until this wave, so ComplianceAssignments::assign had
// no caller on any site and the window sweep reported checked=0 everywhere.
//
// It seeds two organisations, so the tenancy has a far side, and everything it
// writes goes inside a transaction it rolls back. The rollback is measured
// afterwards rather than assumed.

heading('10y. The organisation catalogue and the compliance authoring surface');

// Its own course and its own four accounts, because the seat section deletes
// both of its own two hundred lines above this one. Reusing them meant every
// write here was refused, starting at "The CMS account is unavailable" and
// cascading into fourteen broken expectations that named the catalogue rather
// than the fixture. The Joomla harness has always seeded its own for this
// section; this is that step, and nothing else about the contract changes.
$catalogueCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke catalogue course',
    CATALOGUE_COURSE_SLUG,
    'A course created by build/smoke.php so the catalogue contract has something to release.',
    '<p>Seeded by the VidyaLMS WordPress adapter smoke test.</p>',
);
$catalogueCourseId = (int) $catalogueCourse->id;
$catalogueModule = $kernel->addModule->execute($actor, $catalogueCourseId, 'Catalogue');
$kernel->addLesson->execute(
    $actor,
    (int) $catalogueModule->id,
    'What a catalogue entry reaches',
    'reading',
    ['body' => '<p>Seeded so the course can be published and released.</p>'],
);
$kernel->publishCourse->execute($actor, $catalogueCourseId);

$catalogueAccounts = [
    'admin_a' => makeUser(
        CATALOGUE_ADMIN_A_LOGIN,
        CATALOGUE_ADMIN_A_EMAIL,
        'subscriber',
        'VidyaLMS Smoke Catalogue Admin A'
    ),
    'admin_b' => makeUser(
        CATALOGUE_ADMIN_B_LOGIN,
        CATALOGUE_ADMIN_B_EMAIL,
        'subscriber',
        'VidyaLMS Smoke Catalogue Admin B'
    ),
    'learner_one' => makeUser(
        CATALOGUE_LEARNER_ONE_LOGIN,
        CATALOGUE_LEARNER_ONE_EMAIL,
        'subscriber',
        'VidyaLMS Smoke Catalogue Learner One'
    ),
    'learner_two' => makeUser(
        CATALOGUE_LEARNER_TWO_LOGIN,
        CATALOGUE_LEARNER_TWO_EMAIL,
        'subscriber',
        'VidyaLMS Smoke Catalogue Learner Two'
    ),
];

step(
    'catalogue contract fixture',
    'course ' . $catalogueCourseId . ', accounts ' . implode(', ', array_values($catalogueAccounts)),
);

['failures' => $catalogueFailures, 'facts' => $catalogueFacts] =
    \Shondalai\VidyaLMS\Tooling\Contract\OrganisationCatalogueContract::run(
        $kernel,
        $ports,
        $actor,
        $catalogueCourseId,
        $catalogueAccounts,
        (int) $course->id,
    );

foreach ($catalogueFacts as $catalogueFact) {
    out('    ' . $catalogueFact);
}

foreach ($catalogueFailures as $catalogueFailure) {
    out('    ' . $catalogueFailure);
}

assertThat(
    $catalogueFailures === [],
    'a released course reaches the organisation through a catalogue entry and no further, and a'
        . ' requirement is authored, assigned and answered with its exception',
    $catalogueFailures === []
        ? \count($catalogueFacts) . ' facts recorded, 0 expectations broken'
        : \count($catalogueFailures) . ' expectation(s) broken',
);

// The contract rolls back everything it wrote, so what is left is this
// section's own course and its four accounts, which go the way 11o's do.
foreach ($catalogueAccounts as $catalogueUserId) {
    $database->delete('notification_queue', ['recipient_user_id' => $catalogueUserId]);
    $database->delete('activity_log', ['user_id' => $catalogueUserId]);
    $database->delete('email_log', ['user_id' => $catalogueUserId]);
}

foreach (CATALOGUE_ACCOUNT_LOGINS as $catalogueLogin) {
    step('catalogue contract account ' . $catalogueLogin, removeUser($catalogueLogin));
}

step('catalogue course rows removed', (string) removeSmokeCourse($database, $catalogueCourseId) . ' row(s)');

// verifyOnShutdown() only speaks when the seal is broken, and a detector that
// is silent either way cannot be told apart from one that was never wired in.
// This states the intact case out loud so a verification run can read the seal
// rather than infer it.

/**
 * The child-to-parent pairs the orphan sweep walks, named once so the run and
 * the cleanup cannot drift apart about what a complete teardown is.
 *
 * A function rather than a const because the cleanup path exits long before
 * this point in the file and PHP hoists a function declaration where it does
 * not hoist a constant.
 *
 * @return array<int, array{0: string, 1: string, 2: string}>
 */
function orphanParents(): array
{
    return [
        ['grade_changes', 'course_id', 'courses'],
        ['grade_cells', 'course_id', 'courses'],
        ['grade_items', 'course_id', 'courses'],
        ['course_instructors', 'course_id', 'courses'],
        ['lesson_content', 'lesson_id', 'lessons'],
        ['course_progress', 'enrolment_id', 'enrolments'],
        ['org_role_assignments', 'organisation_id', 'organisations'],
        ['org_learning_locks', 'course_id', 'courses'],
    ];
}

/**
 * The last teardown, and the one that cannot leak: every child row whose
 * parent this run already removed.
 *
 * Each section removes its own fixture, and each one used to be believed
 * complete. It was not: a course dropped without its gradebook rows, an
 * organisation dropped without its role assignment, a lesson dropped without
 * its content and an enrolment dropped without its progress all left rows
 * behind on both dev sites, and every wave's report said nothing survived.
 * A sweep by parent is the only teardown that stays true when a section grows
 * a new table, because it names the parent rather than the list of children.
 *
 * It is safe by construction: a row whose parent still exists is never
 * touched, so a foreign fixture mid-run is left exactly as it was found.
 *
 * @param array<int, array{0: string, 1: string, 2: string}> $pairs child table, child column, parent table
 */
function sweepOrphanedChildren(DatabasePort $storage, array $pairs): array
{
    $swept = [];

    foreach ($pairs as [$child, $column, $parent]) {
        $parents = [];

        foreach ($storage->selectAll(Query::from($parent)->select(['id'])) as $parentRow) {
            $parents[(int) $parentRow['id']] = true;
        }

        $orphans = [];

        foreach ($storage->selectAll(Query::from($child)->select(['id', $column])) as $childRow) {
            $key = $childRow[$column];

            if ($key === null) {
                continue;
            }

            if (!isset($parents[(int) $key])) {
                $orphans[] = (int) $childRow['id'];
            }
        }

        foreach ($orphans as $orphanId) {
            $storage->delete($child, ['id' => $orphanId]);
        }

        if ($orphans !== []) {
            $swept[$child] = $orphans;
        }
    }

    return $swept;
}

$orphanSweep = sweepOrphanedChildren($database, orphanParents());

step(
    'orphaned child rows swept by parent',
    $orphanSweep === []
        ? 'none'
        : implode(', ', array_map(
            static fn (string $table, array $ids): string => $table . ' ' . implode('/', $ids),
            array_keys($orphanSweep),
            $orphanSweep,
        )),
);

heading('12. Schema seal');

assertThat(
    $schemaSeal->isIntact(),
    'the table list is unchanged since the run started',
    \count($schemaSeal->snapshot()) . ' tables, 0 added, 0 dropped',
);

heading($failures === 0 ? 'ALL CHECKS PASSED' : $failures . ' CHECK(S) FAILED');

if ($pendings > 0) {
    out();
    out('  ' . $pendings . ' check(s) are pending a core route or provider this build does not register.');
    out('  They are named above. Each becomes an ordinary assertion the moment the core ships it.');
}

if (!$keep) {
    out();
    out('  Seeded data is still in the database. Remove it with:');
    out('    php build/smoke.php --wp=' . $wpRoot . ' --cleanup');
}

exit($failures === 0 ? 0 : 1);
