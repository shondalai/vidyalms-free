<?php

/**
 * @package     VidyaLMS
 * @subpackage  Build
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * End to end verification of the Joomla adapter against a real Joomla site and
 * a real database. It drives the access spine (course, curriculum, publish,
 * entitlement, enrolment, progress, completion, certificate, verification)
 * through the CoreKernel resolved from the Joomla container, so every step
 * exercises the real port implementations.
 *
 * What it proves that a unit test cannot:
 *   - JoomlaDatabase compiles the core Query into working MySQL
 *   - update() reports genuinely affected rows, so the guarded completion
 *     update fires CourseCompleted exactly once
 *   - upsert() dedupes on the unique key, so replayed heartbeats do not
 *     multiply progress rows
 *   - the certificate verification code round-trips through the real tables
 *
 * Usage (from any directory):
 *   php build/smoke.php --joomla=<joomla root> [--actor=<user id>]
 *   php build/smoke.php --joomla=<joomla root> --cleanup
 *
 * --cleanup removes everything a previous run seeded (course, curriculum,
 * entitlement, enrolment, progress, certificate, activity and email log rows,
 * and the test learner account) and leaves the extension installed.
 *
 * The script writes to the site it is pointed at. Point it at a development
 * site, never at production.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "smoke.php runs on the command line only.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Arguments
// ---------------------------------------------------------------------------

// No built-in site: --joomla or VIDYALMS_JOOMLA_SITE names it, because this
// script works against whatever site it is pointed at.
$joomlaRoot = rtrim(str_replace('\\', '/', (string) getenv('VIDYALMS_JOOMLA_SITE')), '/');
$actorId    = 0;
$cleanup    = false;
$keep       = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--joomla=')) {
        $joomlaRoot = rtrim(str_replace('\\', '/', substr($argument, 9)), '/');
    } elseif (str_starts_with($argument, '--actor=')) {
        $actorId = (int) substr($argument, 8);
    } elseif ($argument === '--cleanup') {
        $cleanup = true;
    } elseif ($argument === '--keep') {
        $keep = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php build/smoke.php [--joomla=<root>] [--actor=<user id>] [--cleanup] [--keep]\n";
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(1);
    }
}

if (!is_file($joomlaRoot . '/configuration.php')) {
    fwrite(STDERR, 'Not a Joomla root (pass --joomla=<joomla root> or set VIDYALMS_JOOMLA_SITE): ' . $joomlaRoot . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Bootstrap the CMS exactly as cli/joomla.php does
// ---------------------------------------------------------------------------

// Uri::root() reads the server environment, and in CLI Joomla takes the path
// straight from the request URI. The console has no request, so the site
// address is spelled out here; a real deployment that builds URLs from a
// scheduled task must set live_site (or verification_base_url) instead.
$_SERVER['HTTP_HOST']      ??= 'localhost';
$_SERVER['REQUEST_URI']    ??= '/' . basename($joomlaRoot) . '/';
$_SERVER['SCRIPT_NAME']    ??= '/' . basename($joomlaRoot) . '/index.php';
$_SERVER['PHP_SELF']       ??= $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD'] ??= 'GET';

\define('_JEXEC', 1);
\define('JPATH_BASE', $joomlaRoot);

require_once __DIR__ . '/../../../tooling/fixtures/bootstrap.php';
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Console\Application as ConsoleApplication;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Tooling\Fixtures\SchemaSeal;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$application = $container->get(ConsoleApplication::class);

// doExecute() is what normally registers the extension namespaces; this script
// dispatches no console command, so the PSR-4 map is loaded by hand. Without
// it no com_vidyalms or core class can be autoloaded.
$application->createExtensionNamespaceMap();

// The contract bootstrap loads by require and not by autoload, and several of
// its files extend a core class (SmokeOrganisationRosterImporter extends
// Registry\Importer\AbstractImporter). Requiring them before the line above
// resolves the parent through an autoloader that does not know lib_vidyalms_core
// yet, which is a fatal "class not found" before the first section runs.
require_once __DIR__ . '/../../../tooling/contract/bootstrap.php';

Factory::$application = $application;
$application->getLanguage()->load('com_vidyalms', JPATH_ADMINISTRATOR);

// ---------------------------------------------------------------------------
// Output helpers
// ---------------------------------------------------------------------------

$failures = 0;
$pendings = 0;
$step     = 0;

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
 * The size of the component's own log file, so a section can assert that a
 * degradation was reported rather than swallowed. Zero when the site has not
 * written one yet, which makes "it grew" the only thing worth asserting.
 */
function smokeComponentLogSize(string $joomlaRoot): int
{
    $configured = Factory::getContainer()->get('config')->get('log_path');
    $path       = \is_string($configured) && $configured !== ''
        ? $configured
        : $joomlaRoot . '/administrator/logs';
    $file       = rtrim($path, '/\\') . '/com_vidyalms.php';

    clearstatcache(true, $file);

    return is_file($file) ? (int) filesize($file) : 0;
}

/**
 * A scheduled task the clock has to start, for the sections that prove the
 * periodic turn end to end.
 *
 * It exists because the four task types this wave introduces are shipped by
 * the core and by the paid package, not by this adapter, and the path they
 * travel is entirely this adapter's: the routine that starts them, the queue
 * they land in, the drain that resolves them in the registry. A probe under
 * the same key proves that path before the shipped handler exists and keeps
 * proving it afterwards, and the sections skip the probe for any key already
 * registered so a shipped handler is never shadowed.
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
 * The keys of a registry that did not come from the free build.
 *
 * Two seams assert that the free build ships exactly one provider, because a
 * second one nobody registered would be a payment path or a model call nobody
 * reviewed. That equality can only be asserted on a free-only site, and
 * run-local.mjs seeds this harness on the same site verify.mjs then drives
 * through the Pro screens, so the site it runs on has Pro. The property that
 * survives an edition is the same one in a form a merged registry can answer:
 * the free provider is still there, and every other key belongs to a class
 * from outside the free build.
 *
 * Attribution is by the provider class's own namespace rather than by the
 * feature-provider ledger, because the ledger is written by the package that
 * registers and a bridge registered straight into the registry by a host
 * plugin has no ledger row. Namespace answers the question the two seams
 * actually ask.
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
 * @return array<string, mixed>|null
 */
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

function row(DatabasePort $database, string $table, string $column, mixed $value): ?array
{
    return $database->selectOne(Query::from($table)->where($column, '=', $value));
}

// ---------------------------------------------------------------------------
// Fixture identity
// ---------------------------------------------------------------------------

const COURSE_SLUG        = 'vidyalms-smoke-course';
const SECOND_COURSE_SLUG = 'vidyalms-smoke-other-course';

/**
 * A third course, for the section that proves a gated quiz refuses an attempt.
 *
 * It needs an enrolment that is still active, and the spine's is completed by
 * the time that section runs, so it cannot borrow the spine's course.
 */
const GATED_COURSE_SLUG  = 'vidyalms-smoke-gated-course';

/**
 * A fourth course, for the seat contract.
 *
 * It needs a course nothing else in this run has finished, released to two
 * throwaway organisations and carrying a quiz the contract authors, so it
 * cannot borrow the spine's course either.
 */
const SEAT_COURSE_SLUG   = 'vidyalms-smoke-seat-course';

/**
 * A sixth course, for the free AI build.
 *
 * Its own course because the AI section runs a reading level check over the
 * text it holds and accepts one generated item into a quiz of its own, and
 * because a course removed at the end of the section is the cheapest way to be
 * sure no generated correct answer is left on the site.
 */
const AI_COURSE_SLUG = 'vidyalms-smoke-ai-course';
/**
 * A seventh course, for the gradebook and the scheduled report.
 *
 * Its own course for two reasons. The gradebook section enrols the learner and
 * marks an assignment, and the spine's enrolment is already completed, so it
 * cannot borrow it. And the schedule it saves names a course in its stored
 * parameters, so a course removed at the end of the section is the cheapest way
 * to be sure no schedule is left pointing at rows this run created.
 */
const GRADEBOOK_COURSE_SLUG = 'vidyalms-smoke-gradebook-course';

/**
 * The catalogue and compliance section's own course.
 *
 * It used to be handed 10r's seat course and 10r's four accounts, and 10r
 * removes both on its way out, so on a real site every write in the section
 * answered 409 on a course row that no longer exists or 422 on a CMS account
 * that no longer exists. A section that seeds what it needs cannot be broken
 * by the order the sections happen to run in.
 */
const CATALOGUE_COURSE_SLUG = 'vidyalms-smoke-catalogue-course';
const SEAT_ACCOUNT_PREFIX = 'vidyalms_smoke_seat_';
const LEARNER_USERNAME   = 'vidyalms_smoke_learner';
const LEARNER_EMAIL      = 'vidyalms.smoke@example.invalid';
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
            $removed += smokeDelete($storage, 'idempotency_keys', ['scope' => $scope, 'idem_key' => $key]);
        }
    }

    return $removed;
}

/** @var DatabaseInterface $db */
$db = $container->get(DatabaseInterface::class);

// This harness seeds and removes rows. It creates no table and drops none, on
// this site or any other, and the seal makes that a checked fact rather than an
// intention: if the table list differs on the way out, however the run ends,
// the run fails and names what moved. A script that genuinely needs tables of
// its own creates a dedicated database and drops the database, never a table in
// a schema it does not own. See tooling/fixtures/README.md.
$schemaSeal = new SchemaSeal('joomla smoke', static fn (): array => $db->getTableList());
$schemaSeal->verifyOnShutdown();

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
/**
 * The vidyalms_ tables this site actually has, without their prefix, read once.
 *
 * A free-only site holds 126 of the 169 tables the two editions declare between
 * them, so a cleanup naming quiz_slots or any other Pro table on a free build is
 * a fatal "table doesn't exist" rather than a no-op. This harness has to run on
 * either edition.
 *
 * @return list<string>
 */
function smokeSiteTables(): array
{
    static $tables = null;

    if ($tables === null) {
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $prefix = $db->getPrefix() . 'vidyalms_';
        $tables = [];

        foreach ($db->getTableList() as $name) {
            if (str_starts_with((string) $name, $prefix)) {
                $tables[] = substr((string) $name, \strlen($prefix));
            }
        }
    }

    return $tables;
}

/**
 * A cleanup delete that a table this edition does not install cannot kill.
 *
 * It answers 0 rather than pretending: an absent table held no row of this run,
 * so nothing was removed and nothing is claimed. A table that IS here is
 * deleted from exactly as before, so no assertion loses any strength.
 *
 * @param array<string, mixed> $where
 */
function smokeDelete(DatabasePort $storage, string $table, array $where): int
{
    return \in_array($table, smokeSiteTables(), true) ? $storage->delete($table, $where) : 0;
}

/**
 * The drain window that is guaranteed to contain one named queue row.
 *
 * NotificationSendTask takes a limit and nothing else, and the queue hands
 * the drain its due pending rows ordered by run_at. A section that queues a
 * row now and drains a fixed five therefore proves nothing on a site that
 * already holds five due rows: its own row is the sixth and the assertions
 * report a queue defect that is really a backlog. That is not hypothetical.
 * The envelope section passed on a freshly installed site and failed on the
 * same site an hour later, because the run before it had left eleven fanned
 * out rows pending, and a second run of this very harness reproduces it every
 * time.
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
            ->where('run_at', '<=', (string) $own['run_at']),
    );

    return max(5, \count($ahead));
}

function removeSmokeCourse(DatabasePort $storage, int $courseId): int
{
    $removed = 0;

    foreach ($storage->selectAll(Query::from('enrolments')->where('course_id', '=', $courseId)) as $seeded) {
        $enrolmentId = (int) $seeded['id'];
        $removed += smokeDelete($storage, 'course_progress', ['enrolment_id' => $enrolmentId]);
        $removed += smokeDelete($storage, 'lesson_progress', ['enrolment_id' => $enrolmentId]);
    }

    foreach ($storage->selectAll(Query::from('quizzes')->where('course_id', '=', $courseId)) as $seededQuiz) {
        $quizId = (int) $seededQuiz['id'];

        foreach ($storage->selectAll(Query::from('attempts')->where('quiz_id', '=', $quizId)) as $seededAttempt) {
            $attemptId = (int) $seededAttempt['id'];

            foreach (['attempt_answer_events', 'attempt_answers', 'attempt_questions', 'attempt_grades'] as $child) {
                $removed += smokeDelete($storage, $child, ['attempt_id' => $attemptId]);
            }

            $removed += smokeDelete($storage, 'attempts', ['id' => $attemptId]);
        }

        $removed += smokeDelete($storage, 'quiz_slots', ['quiz_id' => $quizId]);
        $removed += smokeDelete($storage, 'questions', ['quiz_id' => $quizId]);
        $removed += smokeDelete($storage, 'availability_rules', ['subject_type' => 'quiz', 'subject_id' => $quizId]);
        $removed += smokeDelete($storage, 'availability_rule_refs', ['owner_type' => 'quiz', 'owner_id' => $quizId]);
        $removed += smokeDelete($storage, 'quizzes', ['id' => $quizId]);
    }

    foreach ($storage->selectAll(Query::from('lessons')->where('course_id', '=', $courseId)) as $seededLesson) {
        $lessonId = (int) $seededLesson['id'];
        $removed += smokeDelete($storage, 'lesson_content', ['lesson_id' => $lessonId]);
        $removed += smokeDelete($storage, 'video_positions', ['lesson_id' => $lessonId]);
        $removed += smokeDelete($storage, 'notification_queue', ['subject_type' => 'lesson', 'subject_id' => $lessonId]);
        $removed += smokeDelete($storage, 'availability_rules', ['subject_type' => 'lesson', 'subject_id' => $lessonId]);
        $removed += smokeDelete($storage, 'availability_rule_refs', ['owner_type' => 'lesson', 'owner_id' => $lessonId]);

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
                $removed += smokeDelete($storage, 'submission_files', ['submission_id' => (int) $seededSubmission['id']]);
            }

            $removed += smokeDelete($storage, 'submissions', ['assignment_id' => $seededAssignmentId]);
            $removed += smokeDelete($storage, 'assignments', ['id' => $seededAssignmentId]);
        }
    }

    // The gradebook, child first: a change names its cell and a cell names its
    // item, so removing the item first would leave a log row nobody can read.
    foreach (['grade_changes', 'grade_cells', 'grade_items'] as $gradeTable) {
        $removed += smokeDelete($storage, $gradeTable, ['course_id' => $courseId]);
    }

    foreach ($storage->selectAll(Query::from('modules')->where('course_id', '=', $courseId)) as $seededModule) {
        $removed += smokeDelete($storage, 
            'availability_rules',
            ['subject_type' => 'module', 'subject_id' => (int) $seededModule['id']],
        );
    }

    // The credential child rows hang off the certificate rather than the
    // course, so they go before the certificates do or they are orphaned.
    foreach ($storage->selectAll(Query::from('certificates')->where('course_id', '=', $courseId)) as $seededCertificate) {
        $seededCertificateId = (int) $seededCertificate['id'];
        $removed += smokeDelete($storage, 'certificate_credits', ['certificate_id' => $seededCertificateId]);
        $removed += smokeDelete($storage, 
            'credential_status_entries',
            ['subject_type' => 'certificate', 'subject_id' => $seededCertificateId],
        );
    }

    $removed += smokeDelete($storage, 'course_credits', ['course_id' => $courseId]);
    $removed += smokeDelete($storage, 'availability_rules', ['subject_type' => 'course', 'subject_id' => $courseId]);
    $removed += smokeDelete($storage, 'availability_rule_refs', ['owner_type' => 'course', 'owner_id' => $courseId]);
    $removed += smokeDelete($storage, 'notification_queue', ['subject_type' => 'course', 'subject_id' => $courseId]);

    foreach (['certificates', 'enrolments', 'entitlements', 'activity_log', 'course_instructors'] as $table) {
        $removed += smokeDelete($storage, $table, ['course_id' => $courseId]);
    }

    $removed += smokeDelete($storage, 'lessons', ['course_id' => $courseId]);
    $removed += smokeDelete($storage, 'modules', ['course_id' => $courseId]);
    $removed += smokeDelete($storage, 'courses', ['id' => $courseId]);

    return $removed;
}

/**
 * The seeded learner, or 0 when the account does not exist.
 */
function learnerId(DatabaseInterface $db): int
{
    $username = LEARNER_USERNAME;

    $query = $db->createQuery()
        ->select($db->quoteName('id'))
        ->from($db->quoteName('#__users'))
        ->where($db->quoteName('username') . ' = :username')
        ->bind(':username', $username, ParameterType::STRING);

    return (int) $db->setQuery($query)->loadResult();
}

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------

if ($cleanup) {
    heading('VidyaLMS smoke cleanup');

    $kernel  = bootKernel();
    $ports   = $container->get(\Shondalai\VidyaLMS\Core\Port\Ports::class);
    $storage = $ports->database;

    $userId = learnerId($db);

    foreach (
        [COURSE_SLUG, SECOND_COURSE_SLUG, GATED_COURSE_SLUG, SEAT_COURSE_SLUG,
        AI_COURSE_SLUG, GRADEBOOK_COURSE_SLUG, CATALOGUE_COURSE_SLUG] as $slug
    ) {
        $course = row($storage, 'courses', 'slug', $slug);

        if ($course === null) {
            step('course ' . $slug, 'not present');

            continue;
        }

        // One list of tables, shared with the gated-quiz section, which
        // removes its own course as soon as it is finished with it. A table
        // added to one list and not the other is a leak nobody notices until a
        // later run trips over the row.
        step(
            'deleted course ' . $slug,
            (string) removeSmokeCourse($storage, (int) $course['id']) . ' row(s)',
        );
    }

    // The certificate template the credentials section creates, removed by the
    // key it mints rather than by a pattern, so nothing a site owner authored
    // can go with it. It is normally removed inside that section; this covers
    // a run that died between creating it and getting there.
    foreach (
        $storage->selectAll(Query::from('certificate_templates')->where('template_key', '=', 'vidyalms.smoke.template'))
        as $smokeTemplate
    ) {
        $smokeTemplateId = (int) $smokeTemplate['id'];

        foreach (
            $storage->selectAll(Query::from('certificate_template_pages')->where('template_id', '=', $smokeTemplateId))
            as $smokePage
        ) {
            smokeDelete($storage, 'certificate_template_elements', ['page_id' => (int) $smokePage['id']]);
            smokeDelete($storage, 'certificate_template_pages', ['id' => (int) $smokePage['id']]);
        }

        step(
            'deleted smoke certificate template',
            (string) smokeDelete($storage, 'certificate_templates', ['id' => $smokeTemplateId]) . ' row(s)',
        );
    }

    // The seat contract removes its own organisations, pools and ledger rows
    // inside its own finally, so all that can survive a run that died inside
    // it is the four accounts and anything addressed to them.
    $seatAccountsRemoved = 0;

    foreach ($storage->selectAll(Query::from('organisations')
        ->whereContains('slug', \Shondalai\VidyaLMS\Tooling\Contract\OrganisationSeatContract::MARKER)) as $strandedOrg) {
        $strandedId = (int) $strandedOrg['id'];

        foreach (['seat_events', 'seat_assignments', 'seat_pools', 'invitations', 'org_memberships',
            'org_membership_events', 'org_course_releases', 'admin_access_log', 'org_learning_locks'] as $strandedTable) {
            $seatAccountsRemoved += smokeDelete($storage, $strandedTable, ['organisation_id' => $strandedId]);
        }

        $seatAccountsRemoved += smokeDelete($storage, 'organisations', ['id' => $strandedId]);
    }

    foreach (seatContractAccountIds($db) as $seatUserId) {
        smokeDelete($storage, 'notification_queue', ['recipient_user_id' => $seatUserId]);
        smokeDelete($storage, 'activity_log', ['user_id' => $seatUserId]);
        smokeDelete($storage, 'email_log', ['user_id' => $seatUserId]);
        $container->get(UserFactoryInterface::class)->loadUserById($seatUserId)->delete();
        $seatAccountsRemoved++;
    }

    foreach (rosterContractAccountIds($db) as $rosterUserId) {
        smokeDelete($storage, 'notification_queue', ['recipient_user_id' => $rosterUserId]);
        smokeDelete($storage, 'activity_log', ['user_id' => $rosterUserId]);
        smokeDelete($storage, 'email_log', ['user_id' => $rosterUserId]);
        $container->get(UserFactoryInterface::class)->loadUserById($rosterUserId)->delete();
        $seatAccountsRemoved++;
    }

    step('deleted stranded seat contract rows', $seatAccountsRemoved . ' row(s)');

    // The import contract removes its own rows in its own finally too, so this
    // catches only a run that died inside it. Jobs are found by the harness
    // importer's key, which no shipped importer answers to, and their row
    // problems go first because they name the job.
    $importRowsRemoved = 0;

    foreach ($storage->selectAll(Query::from('import_jobs')->select(['id'])
        ->where('importer', '=', \Shondalai\VidyaLMS\Tooling\Contract\SmokeOrganisationRosterImporter::KEY)) as $strandedJob) {
        $importRowsRemoved += smokeDelete($storage, 'import_row_problems', ['import_job_id' => (int) $strandedJob['id']]);
        $importRowsRemoved += smokeDelete($storage, 'import_jobs', ['id' => (int) $strandedJob['id']]);
    }

    $importRowsRemoved += smokeDelete($storage, 
        'import_map',
        ['importer' => \Shondalai\VidyaLMS\Tooling\Contract\SmokeOrganisationRosterImporter::KEY],
    );

    foreach ($storage->selectAll(Query::from('organisations')
        ->whereContains('slug', \Shondalai\VidyaLMS\Tooling\Contract\OrganisationImportContract::MARKER)) as $strandedOrg) {
        $strandedImportOrg = (int) $strandedOrg['id'];

        foreach (['org_membership_events', 'org_memberships'] as $strandedTable) {
            $importRowsRemoved += smokeDelete($storage, $strandedTable, ['organisation_id' => $strandedImportOrg]);
        }

        $importRowsRemoved += smokeDelete($storage, 'organisations', ['id' => $strandedImportOrg]);
    }

    step('deleted stranded import contract rows', $importRowsRemoved . ' row(s)');

    step('deleted certificate retry receipts', removeSmokeCertificateReceipts($storage) . ' row(s)');

    // The envelope sender section's own rows, addressed by the keys it mints
    // rather than by a pattern, so nothing another run wrote can go with them.
    $envelopeRows = smokeDelete($storage, 'notification_queue', ['trigger_key' => 'vidyalms.smoke.envelope'])
        + smokeDelete($storage, 'email_log', ['template_key' => 'vidyalms.smoke.envelope'])
        + smokeDelete($storage, 'notification_templates', ['template_key' => 'vidyalms.smoke.envelope']);

    step('deleted smoke envelope rows', $envelopeRows . ' row(s)');

    if ($userId > 0) {
        smokeDelete($storage, 'email_log', ['user_id' => $userId]);
        smokeDelete($storage, 'activity_log', ['user_id' => $userId]);
        smokeDelete($storage, 'notification_queue', ['recipient_user_id' => $userId]);

        $learner = $container->get(UserFactoryInterface::class)->loadUserById($userId);

        step('deleted learner account ' . $userId, $learner->delete() ? 'ok' : 'FAILED');
    } else {
        step('learner ' . LEARNER_USERNAME, 'not present');
    }

    /* The same sweep the run itself ends with, so a cleanup that removes a
       fixture cannot leave a child row waiting for the next run to notice. */
    $cleanupSweep = sweepOrphanedChildren($storage, orphanParents());

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
// The spine
// ---------------------------------------------------------------------------

heading('VidyaLMS Joomla adapter smoke test');
out('  joomla   : ' . $joomlaRoot);
out('  database : ' . $db->getName() . ' as ' . Factory::getContainer()->get('config')->get('db'));
out('  php      : ' . PHP_VERSION);

heading('1. Kernel');

// The system plugin group is imported BEFORE the kernel is built, and that
// order is the whole point of these three lines.
//
// onVidyaLmsKernelReady is dispatched once, from inside KernelFactory, on the
// application dispatcher. A listener that is not on that dispatcher when the
// kernel is built has missed it for the life of the request, and there is no
// second chance: the container caches the shared kernel. Every earlier run of
// this harness built the kernel on a dispatcher carrying no plugins at all, so
// the whole run saw a free build even on a site with Pro installed and
// enabled. Wave 3c recorded that as the blind spot behind it, and it is the
// reason a Pro provider, a Pro route or a Pro mount key could not be asserted
// here at all.
//
// Importing the group is exactly what a web request does on onAfterInitialise,
// so this is not a special arrangement for the harness: it is the arrangement
// every real request already has.
//
// One plugin at a time, each in its own try, because a development site is a
// site with other people's extensions on it. A single legacy plugin that calls
// a class Joomla 6 removed is a fatal inside the group import, and importing
// the group as a whole would then stop the harness before it had asserted
// anything, on a site where VidyaLMS itself is perfectly healthy. Skipped
// plugins are named rather than swallowed.
$systemPlugins  = [];
$skippedPlugins = [];

foreach (\Joomla\CMS\Plugin\PluginHelper::getPlugin('system') as $systemPlugin) {
    $element = (string) ($systemPlugin->name ?? '');

    if ($element === '') {
        continue;
    }

    try {
        \Joomla\CMS\Plugin\PluginHelper::importPlugin(
            'system',
            $element,
            true,
            $application->getDispatcher()
        );

        $systemPlugins[] = $element;
    } catch (\Throwable) {
        $skippedPlugins[] = $element;
    }
}

step(
    'system plugins imported before the kernel is built',
    \count($systemPlugins) . ' imported (' . implode(', ', $systemPlugins) . ')'
    . ($skippedPlugins === [] ? '' : '; skipped ' . implode(', ', $skippedPlugins)),
);

assertThat(
    \in_array('vidyalms', $systemPlugins, true),
    'the VidyaLMS system plugin is on the dispatcher before the kernel is built',
    'so onVidyaLmsKernelReady reaches every listener, a paid package included',
);

$kernel = bootKernel();

step('CoreKernel resolved from the global container', \get_class($kernel));

$installedPackages = [];

try {
    foreach ($kernel->featureProviders->listActive() as $ledgerRow) {
        if (!\in_array($ledgerRow->package, $installedPackages, true)) {
            $installedPackages[] = $ledgerRow->package;
        }
    }
} catch (\Throwable) {
    $installedPackages = [];
}

$proInstalled = \in_array('vidyalms-pro', $installedPackages, true);

step(
    'packages registered into this kernel',
    $installedPackages === [] ? 'the free build alone' : implode(', ', $installedPackages),
);

/** @var \Shondalai\VidyaLMS\Core\Port\Ports $ports */
$ports    = $container->get(\Shondalai\VidyaLMS\Core\Port\Ports::class);
$database = $ports->database;

assertThat(
    $container->get(CoreKernel::class) === $kernel,
    'the kernel is shared, not rebuilt per resolve',
    'same instance on a second get()'
);

step('verification base url', $kernel->verificationBaseUrl);

heading('2. Actors');

if ($actorId <= 0) {
    $actorId = superUserId($db);
}

if ($actorId <= 0) {
    fwrite(STDERR, "No Super User found to act as the author; pass --actor=<id>.\n");
    exit(1);
}

$actor = Principal::user($actorId);
step('author principal', 'user ' . $actorId . ' (' . $ports->users->findById($actorId)?->name . ')');

assertThat(
    $ports->authorization->can($actorId, \Shondalai\VidyaLMS\Core\Application\Shared\Capability::TEACH),
    'the author holds vidyalms.teach through Joomla ACL',
    'AuthorizationPort::can returned true'
);

$learnerId = learnerId($db);

if ($learnerId <= 0) {
    $learnerId = createLearner($container);
    step('created learner account', LEARNER_USERNAME . ' (id ' . $learnerId . ')');
} else {
    step('learner account already present', LEARNER_USERNAME . ' (id ' . $learnerId . ')');
}

$learner = Principal::user($learnerId);

heading('3. Curriculum');

$existing = row($database, 'courses', 'slug', COURSE_SLUG);

if ($existing !== null) {
    fwrite(STDERR, "\nThe smoke course already exists. Run with --cleanup first.\n");
    exit(1);
}

$course = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke course',
    COURSE_SLUG,
    'A course created by build/smoke.php to prove the adapter works end to end.',
    '<p>Seeded by the VidyaLMS Joomla adapter smoke test.</p>',
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

// The four course events reach the Joomla dispatcher through
// Port\JoomlaEventBridge, which is what plg_system_vidyalms and any third
// party extension subscribe to. Listening here, before the use case runs,
// proves the whole chain rather than the map in the plugin's constant.
$courseEvents = [];

foreach (['onVidyaLmsCoursePublished', 'onVidyaLmsCourseUpdated', 'onVidyaLmsCourseArchived'] as $courseEvent) {
    $application->getDispatcher()->addListener(
        $courseEvent,
        static function (\Joomla\Event\EventInterface $event) use (&$courseEvents): void {
            $courseEvents[$event->getName()][] = $event->getArgument('payload');
        },
    );
}

$published = $kernel->publishCourse->execute($actor, $course->id);
assertThat(
    $published->isPublished(),
    'PublishCourse moved the course to published',
    'state=' . $published->state->value . ' published_at=' . ($published->publishedAt?->format('Y-m-d H:i:s') ?? 'null')
);

$publishedPayload = $courseEvents['onVidyaLmsCoursePublished'][0] ?? null;

assertThat(
    \is_array($publishedPayload)
        && (int) ($publishedPayload['course_id'] ?? 0) === $course->id
        && ($publishedPayload['state'] ?? '') === 'published'
        && (string) ($publishedPayload['slug'] ?? '') === (string) $course->slug,
    'publishing fired onVidyaLmsCoursePublished with the course payload',
    \is_array($publishedPayload)
        ? 'course_id=' . ($publishedPayload['course_id'] ?? '?') . ' slug=' . ($publishedPayload['slug'] ?? '?')
        : 'no event seen',
);

$kernel->updateCourse->execute($actor, $course->id, ['title' => $published->title . ' (renamed)']);
$updatedPayload = $courseEvents['onVidyaLmsCourseUpdated'][0] ?? null;

assertThat(
    \is_array($updatedPayload) && \in_array('title', (array) ($updatedPayload['changed'] ?? []), true),
    'updating fired onVidyaLmsCourseUpdated naming the changed field',
    \is_array($updatedPayload)
        ? 'changed=' . implode(',', (array) ($updatedPayload['changed'] ?? []))
        : 'no event seen',
);

$kernel->updateCourse->execute($actor, $course->id, ['title' => $published->title]);

heading('4. Access');

$entitlement = $kernel->grantEntitlement->execute(
    $actor,
    $learnerId,
    $course->id,
    Entitlement::SOURCE_MANUAL,
    'smoke-test'
);
step('GrantEntitlement', 'entitlement ' . $entitlement->id() . ' source ' . $entitlement->source);

$enrolments = $kernel->listMyEnrolments->execute($learner);
$enrolment  = null;

foreach ($enrolments as $candidate) {
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

heading('5. Progress and completion');

// Three identical heartbeats on an incomplete course: JoomlaDatabase::upsert
// has to collapse them onto one row through the unique key, exactly as the
// SQLite harness does.
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
    'status=' . ($after['status'] ?? 'null') . ' percent=' . ($after['progress_percent'] ?? 'null')
        . ' completed_at=' . ($after['completed_at'] ?? 'null')
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

heading('6. Credentials');

$certificates = $database->selectAll(Query::from('certificates')->where('enrolment_id', '=', $enrolmentId));

assertThat(
    \count($certificates) === 1,
    'exactly one certificate was issued',
    'certificate rows = ' . \count($certificates)
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

heading('7. Catalogue and mail');

$page = $kernel->getCatalogue->execute(Principal::guest(), 'smoke', null, 1, 12);

assertThat(
    $page->total >= 1,
    'GetCatalogue finds the published course',
    'total=' . $page->total . ' first=' . ($page->items[0]->title ?? 'none')
);

$mail = $database->count(Query::from('email_log')->where('user_id', '=', $learnerId));
step('email_log rows for the learner', (string) $mail);

heading('8. Private storage and signed URLs');

$filePath = 'smoke/' . date('Ymd-His') . '.txt';
$ports->storage->putPrivate($filePath, "VidyaLMS smoke file.\n");

$signed = $ports->storage->signedUrl($filePath, 300);
parse_str((string) parse_url($signed, \PHP_URL_QUERY), $signedQuery);

assertThat(
    ($signedQuery['task'] ?? '') === \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage::DOWNLOAD_TASK,
    'the signed URL points at the site download task',
    'task=' . ($signedQuery['task'] ?? 'null')
);

assertThat(
    \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage::verifySignature(
        (string) ($signedQuery['path'] ?? ''),
        (int) ($signedQuery['expires'] ?? 0),
        (string) ($signedQuery['signature'] ?? '')
    ),
    'the signature verifies, so FileController would serve it',
    'path=' . ($signedQuery['path'] ?? 'null')
);

assertThat(
    !\VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage::verifySignature(
        (string) ($signedQuery['path'] ?? ''),
        (int) ($signedQuery['expires'] ?? 0),
        (string) ($signedQuery['signature'] ?? '') . '0'
    ),
    'a tampered signature is rejected',
    'hash_equals returned false'
);

assertThat(
    trim($ports->storage->readPrivate($filePath)) === 'VidyaLMS smoke file.',
    'the private file reads back byte for byte',
    'readPrivate matched'
);

$ports->storage->delete($filePath);

assertThat(!$ports->storage->exists($filePath), 'the private file was removed', 'exists() is false');

heading('9. Administration access');

// A group granted only core.manage on the component must be able to work the
// manager screens. Joomla's ACL has no action implication, so JoomlaAuthorization
// supplies it; without that, the dashboard and enrolments screens refuse a
// component manager because vidyalms.reports is a custom action nothing grants
// by default.
[$managerGroupId, $managerUserId] = createComponentManager($container, $db);

step('component manager', 'group ' . $managerGroupId . ', user ' . $managerUserId . ' with core.manage only');

$authorization = $ports->authorization;

assertThat(
    $authorization->can($managerUserId, Capability::MANAGE),
    'core.manage answers the MANAGE capability',
    'vidyalms.manage = true',
);

foreach ([
    'dashboard and enrolments' => Capability::VIEW_REPORTS,
    'course authoring'         => Capability::TEACH,
    'grading'                  => Capability::GRADE,
    'enrolling others'         => Capability::ENROL_OTHERS,
] as $screen => $capability) {
    assertThat(
        $authorization->can($managerUserId, $capability),
        'core.manage reaches ' . $screen,
        $capability . ' = true',
    );
}

assertThat(
    !$authorization->can($managerUserId, Capability::ADMIN),
    'core.manage does NOT grant component administration',
    'vidyalms.admin = false',
);

$strangerId = learnerId($db);

assertThat(
    $strangerId > 0 && !$authorization->can($strangerId, Capability::VIEW_REPORTS),
    'a registered user without either administration action is refused',
    'vidyalms.reports = false for user ' . $strangerId,
);

removeComponentManager($container, $db, $managerGroupId, $managerUserId);
step('removed the throwaway manager group and user', 'ok');

heading('9b. Per-course teaching');

// The workbench is reachable by anyone holding one of the four operational
// actions, and what they may then do is CourseAccess's answer, not the
// screen's. A group granted vidyalms.teach on the component alone therefore
// has to reach the courses it instructs and no others: without that, the
// whole point of the per-course rule would be lost the moment a site
// delegated teaching.
[$teachGroupId, $teacherId] = createAclGroup(
    $container,
    $db,
    'VidyaLMS smoke instructors',
    'VidyaLMS Smoke Instructor',
    'vidyalms_smoke_instructor',
    ['vidyalms.teach'],
);

\Joomla\CMS\Access\Access::clearStatics();

$teacher = Principal::user($teacherId);

step('component instructor', 'group ' . $teachGroupId . ', user ' . $teacherId . ' with vidyalms.teach only');

assertThat(
    $authorization->can($teacherId, Capability::TEACH),
    'vidyalms.teach on the component answers the TEACH capability',
    'vidyalms.teach = true',
);

assertThat(
    !$authorization->can($teacherId, Capability::MANAGE),
    'vidyalms.teach does NOT grant management',
    'vidyalms.manage = false',
);

assertThat(
    !$kernel->guard->can($teacher, Capability::MANAGE),
    'the core guard agrees the instructor does not manage the site',
    'AccessGuard::can(manage) = false',
);

assertThat(
    refused(static fn () => $kernel->getManagedCourse->execute($teacher, $course->id)),
    'a course the instructor is not assigned to is refused',
    'GetManagedCourse on course ' . $course->id . ' throws',
);

$kernel->assignInstructor->execute($actor, $course->id, $teacherId);
step('AssignInstructor', 'user ' . $teacherId . ' on course ' . $course->id);

$reached = null;

try {
    $reached = $kernel->getManagedCourse->execute($teacher, $course->id);
} catch (\Throwable $failure) {
    $reached = null;
}

assertThat(
    $reached !== null && $reached->managed->course->id === $course->id,
    'the course the instructor was assigned to opens its workbench',
    'GetManagedCourse on course ' . $course->id . ' answers',
);

// A second course the same instructor is not assigned to, so "only that
// course" is a measured fact and not the absence of another course.
$otherCourse = row($database, 'courses', 'slug', SECOND_COURSE_SLUG) === null
    ? $kernel->createCourse->execute(
        $actor,
        'VidyaLMS smoke second course',
        SECOND_COURSE_SLUG,
        'A second course, to prove per-course teaching is per course.',
    )
    : null;

if ($otherCourse === null) {
    fwrite(STDERR, "\nThe second smoke course already exists. Run with --cleanup first.\n");
    exit(1);
}

step('CreateCourse (second)', 'course ' . $otherCourse->id . ' slug ' . $otherCourse->slug);

assertThat(
    refused(static fn () => $kernel->getManagedCourse->execute($teacher, $otherCourse->id)),
    'the other course stays refused to the same instructor',
    'GetManagedCourse on course ' . $otherCourse->id . ' throws',
);

assertThat(
    refused(static fn () => $kernel->addModule->execute($teacher, $otherCourse->id, 'Not mine')),
    'and so does writing to it',
    'AddModule on course ' . $otherCourse->id . ' throws',
);

$kernel->removeInstructor->execute($actor, $course->id, $teacherId);

assertThat(
    refused(static fn () => $kernel->getManagedCourse->execute($teacher, $course->id)),
    'removing the assignment closes the workbench again',
    'GetManagedCourse on course ' . $course->id . ' throws',
);

removeAclGroup($container, $db, $teachGroupId, $teacherId, ['vidyalms.teach']);
step('removed the throwaway instructor group and user', 'ok');

heading('10. Verification URL');

out('  ' . rtrim($kernel->verificationBaseUrl, '/') . '/' . $code);

// The shared port contract, the same assertions the core runs against its
// SQLite harness and the WordPress adapter runs against WpDatabase. It writes
// inside a transaction it rolls back and then checks the rollback happened, so
// it leaves this site exactly as it found it. build/port-contract.php runs the
// same thing on its own when only this is in question.
heading('10b. Database port contract');

$contractFailures = \Shondalai\VidyaLMS\Tooling\Contract\DatabasePortContract::failures($database);

foreach ($contractFailures as $contractFailure) {
    out('    ' . $contractFailure);
}

assertThat(
    $contractFailures === [],
    'JoomlaDatabase satisfies the database port contract',
    $contractFailures === []
        ? 'aggregates, conditional counts, rates, nulls last, OR groups, grouped count'
        : \count($contractFailures) . ' expectation(s) broken',
);

// Schema 1.1.0 on this site, and what it did to the attempts that were here
// before it. Read only, like the port contract above: it asserts on rows that
// already exist rather than seeding any, which is the only way to test the
// pre-manifest fallback at all, because an attempt written today gets a
// manifest.
heading('10c. Assessment foundation');

['failures' => $assessmentFailures, 'facts' => $assessmentFacts] =
    \Shondalai\VidyaLMS\Tooling\Contract\AssessmentFoundationContract::run($kernel, $ports);

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
        : \count($assessmentFailures) . ' expectation(s) broken',
);

// The Settings and Dashboard screens state how often the expiry sweep runs by
// reading the scheduler's own table, keyed on the routine id the task plugin
// advertises. Two literals in two extensions: if they drift, both screens
// quietly report that the sweep is not running at all.
assertThat(
    \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::SWEEP_TASK_TYPE
        === \VidyaLMS\Plugin\Task\VidyaLms\Extension\VidyaLms::ROUTINE_EXPIRE_ENTITLEMENTS,
    'the sweep routine the Settings screen reads is the one the task plugin runs',
    \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::SWEEP_TASK_TYPE,
);

// ---------------------------------------------------------------------------
// 10d. The mount asset seam
// ---------------------------------------------------------------------------

// Every React mount on this site now gets its assets through
// Administrator\Service\MountAssets, which is also the seam a Pro package or a
// third-party question type adds a bundle to. What matters to the free build
// is the part that holds in both editions: each mount enables its own bundle,
// and that bundle is first in the resolved script order, because it is what
// creates window.vidyalms.questionTypes for anything loaded behind it. What
// any listener contributed is that listener's business and is asserted in its
// own package.
heading('10d. Mount assets');

$mountsChecked = [];

foreach (\VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets::MOUNTS as $mount) {
    $assets = new \Joomla\CMS\WebAsset\WebAssetManager(
        (new \Joomla\CMS\WebAsset\WebAssetRegistry())->addExtensionRegistryFile('com_vidyalms'),
    );

    $contributed = \VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets::attach(
        $assets,
        new \VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssetContext(
            $mount,
            \in_array($mount, ['player', 'quiz-runner'], true) ? 'site' : 'administrator',
        ),
    );

    $free = \VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets::freeAssetName($mount);

    $scripts = [];

    foreach ($assets->getAssets('script', true) as $asset) {
        $scripts[] = $asset->getName();
    }

    $styles = [];

    foreach ($assets->getAssets('style', true) as $asset) {
        $styles[] = $asset->getName();
    }

    assertThat(
        ($scripts[0] ?? '') === $free && \in_array($free, $styles, true),
        'the ' . $mount . ' mount loads its own bundle first',
        implode(' then ', $scripts) . ($contributed === [] ? '' : ' (contributed: ' . implode(', ', $contributed) . ')'),
    );

    $mountsChecked[] = $mount;
}

step('mounts checked', implode(', ', $mountsChecked));

// ---------------------------------------------------------------------------
// 10e. SEF routes
// ---------------------------------------------------------------------------
//
// build() and parse() have to be inverses, and on a site with no menu item
// for this component they were not. NomenuRules is the rule that runs there,
// and it reads every key segment as core's {id}-{alias} pair: it hands the
// segment to get<View>Id() and then turns the first hyphen of the answer into
// a colon. Both of this component's keys are literal strings, so
// /component/vidyalms/course/algebra-101 parsed to the slug "algebra:101",
// which is a course that does not exist. Building the same URL was unaffected,
// so the player's Start attempt link answered a 404 on every course whose slug
// has a hyphen in it, which is nearly all of them, while the same query in its
// non-SEF spelling worked.
//
// The check runs here rather than in a unit test because that rule only fires
// when there is no menu item, which is a property of the site and not of the
// router: a fixture that stubbed the menu would have kept passing.

heading('10e. SEF routes');

$siteApplication = $container->get(\Joomla\CMS\Application\SiteApplication::class);
$siteMenu        = $siteApplication->getMenu();
$router          = new \VidyaLMS\Component\VidyaLMS\Site\Service\Router($siteApplication, $siteMenu);

step(
    'menu items for this component',
    \count($siteMenu->getItems('component', 'com_vidyalms') ?: []) === 0
        ? 'none, so the no-menu rules are what routes this component'
        : 'present, so the standard rules route it as well',
);

/**
 * One round trip: the query the component builds a link from, through the
 * segments a URL carries, back into a query.
 *
 * @param array<string, mixed> $query
 *
 * @return array{0: string, 1: array<string, mixed>}
 */
$roundTrip = static function (array $query) use ($router): array {
    $building = $query;
    $segments = $router->build($building);
    $parsing  = $segments;

    return [implode('/', $segments), $router->parse($parsing) + ['__leftover' => $parsing]];
};

foreach (
    [
        'the catalogue'          => ['view' => 'catalogue'],
        'a course with a hyphenated slug' => ['view' => 'course', 'slug' => 'vidyalms-smoke-course'],
        'a course with a plain slug'      => ['view' => 'course', 'slug' => 'algebra'],
        'the quiz handoff a player emits' => ['view' => 'course', 'slug' => 'vidyalms-smoke-course', 'vy-quiz' => 5],
    ] as $what => $query
) {
    [$path, $parsed] = $roundTrip(['option' => 'com_vidyalms'] + $query);
    $leftover        = $parsed['__leftover'];
    unset($parsed['__leftover']);

    $sameView = ($parsed['view'] ?? null) === $query['view'];
    $sameKey  = !\array_key_exists('slug', $query) || ($parsed['slug'] ?? null) === $query['slug'];

    assertThat(
        $sameView && $sameKey && $leftover === [],
        'the route for ' . $what . ' parses back to itself',
        '/' . $path . ' => ' . json_encode($parsed) . ($leftover === [] ? '' : ' leftover ' . json_encode($leftover)),
    );
}

// The verification page keys on a code rather than a slug and takes the same
// rule, so the same hyphen would have moved in a code that carried one.
[$verifyPath, $verifyParsed] = $roundTrip(['option' => 'com_vidyalms', 'view' => 'verify', 'code' => 'AB2C-9XY']);
$verifyLeftover              = $verifyParsed['__leftover'];
unset($verifyParsed['__leftover']);

assertThat(
    ($verifyParsed['code'] ?? null) === 'AB2C-9XY' && $verifyLeftover === [],
    'a verification code survives the same round trip',
    '/' . $verifyPath . ' => ' . json_encode($verifyParsed),
);

// ---------------------------------------------------------------------------
// 10f. The scheduled task runner
// ---------------------------------------------------------------------------
//
// The queue, end to end, through the extension com_scheduler actually calls.
// A row is queued through the scheduler port, plg_task_vidyalms is imported
// onto the application's dispatcher, and onExecuteTask is dispatched with the
// drain routine exactly as com_scheduler dispatches it. What that proves is
// the thing a unit test cannot: the runner knows no job types of its own.
//
// It resolves a row's job_type in the core's scheduled task registry and
// nowhere else, so a package that queues work of its own is run by this
// adapter without this adapter changing, and a type nobody registered is
// recorded as an unknown type rather than retried for ever. Both cases are
// queued together here, because a runner with a switch statement in it would
// pass the first and fail the second.
//
// No #__scheduler_tasks row is created: com_scheduler's Task takes a plain
// record, so the event carries a real Task without the site gaining a
// scheduled task it never asked for. The two queue rows are removed on the way
// out, which is what keeps the seal below intact.

heading('10f. The scheduled task runner');

$taskDispatcher = $application->getDispatcher();
\Joomla\CMS\Plugin\PluginHelper::importPlugin('task', 'vidyalms', true, $taskDispatcher);

$queueTable = \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaScheduler::TABLE;
$knownType = \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ExpireEntitlementsTask::KEY;
$unknownType = 'vidyalms.smoke.no_such_type';
$queueSuffix = gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
$knownKey = 'vidyalms-smoke-known-' . $queueSuffix;
$unknownKey = 'vidyalms-smoke-unknown-' . $queueSuffix;

// The live development site may already have a large due backlog. These two
// collision-resistant, test-owned rows use a fixed ancient instant so they are
// deterministically the first two pending rows without changing anybody
// else's schedule. The drain is bounded to exactly those two rows below.
$smokeQueueRunAt = new \DateTimeImmutable('1970-01-02 00:00:00', new \DateTimeZone('UTC'));

foreach ([$knownType => $knownKey, $unknownType => $unknownKey] as $type => $key) {
    $ports->scheduler->schedule(
        new \Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask(
            $type,
            ['actor_user_id' => $actorId],
            $smokeQueueRunAt,
            $key,
        ),
    );
}

step(
    'queued through the scheduler port',
    $queueTable . ': "' . $knownType . '" and "' . $unknownType . '"',
);

$taskRecord = (object) [
    'id' => 0,
    'title' => 'VidyaLMS smoke drain',
    'type' => \VidyaLMS\Plugin\Task\VidyaLms\Extension\VidyaLms::ROUTINE_DRAIN_JOBS,
    'params' => '{}',
    'taskOption' => null,
];

$executeEvent = new \Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent('onExecuteTask', [
    'subject' => new \Joomla\Component\Scheduler\Administrator\Task\Task($taskRecord),
    'params' => (object) ['batch_size' => 2, 'max_attempts' => 1, 'stuck_minutes' => 0, 'retry_backoff_minutes' => 0],
    'routineId' => \VidyaLMS\Plugin\Task\VidyaLms\Extension\VidyaLms::ROUTINE_DRAIN_JOBS,
]);

$taskDispatcher->dispatch('onExecuteTask', $executeEvent);

/** @var array<string, mixed> $drainSnapshot */
$drainSnapshot = (array) $executeEvent->getArgument('snapshot', []);

$knownRow = $database->selectOne(Query::from($queueTable)->where('dedupe_key', '=', $knownKey));
$unknownRow = $database->selectOne(Query::from($queueTable)->where('dedupe_key', '=', $unknownKey));
$knownStatus = $knownRow === null ? 'row missing' : var_export($knownRow['status'] ?? null, true);
$unknownOutcome = $unknownRow === null
    ? 'row missing'
    : 'status=' . var_export($unknownRow['status'] ?? null, true)
        . ', last_error=' . var_export($unknownRow['last_error'] ?? null, true);

assertThat(
    ($knownRow['status'] ?? '') === 'done' && ($knownRow['last_error'] ?? null) === null,
    'the runner resolved a registered task type and ran it',
    '"' . $knownType . '" finished with status ' . $knownStatus
    . ', done=' . var_export($drainSnapshot['done'] ?? null, true),
);

// Port\Scheduler\UnresolvedTaskType states the shape: the drain leaves the row
// PENDING and stamps the marker on its error, so that the inspector can tell an
// unresolvable job from a job requeued after a transient failure. A drain that
// failed the row instead would take an operator's only diagnostic away, so the
// status, the marker, the named type, the restored attempt and the released
// reservation are all asserted rather than the status alone.
assertThat(
    ($unknownRow['status'] ?? '') === 'pending'
    && \Shondalai\VidyaLMS\Core\Port\Scheduler\UnresolvedTaskType::isUnresolved(
        (string) ($unknownRow['last_error'] ?? '')
    )
    && str_contains((string) ($unknownRow['last_error'] ?? ''), $unknownType)
    && ($unknownRow['reserved_at'] ?? null) === null
    && ($unknownRow['finished_at'] ?? null) === null,
    'an unregistered task type is recorded as unknown rather than retried',
    'outcome: ' . substr($unknownOutcome, 0, 120),
);

$registeredTypes = $kernel->registries->scheduledTasks->keys();

assertThat(
    \in_array($knownType, $registeredTypes, true) && !\in_array($unknownType, $registeredTypes, true),
    'the runner dispatched on the registry alone',
    \count($registeredTypes) . ' type(s) registered: ' . implode(', ', $registeredTypes),
);

step(
    'queue rows removed',
    $database->delete($queueTable, ['dedupe_key' => $knownKey])
    + $database->delete($queueTable, ['dedupe_key' => $unknownKey]) . ' row(s)',
);

// ---------------------------------------------------------------------------
// 10g. The frontend instructor page
// ---------------------------------------------------------------------------
//
// The site view that mounts the instructor workbench for anyone the core says
// may teach. Three facts, and each one is a thing only a real site can answer.
//
// The SEF route has to survive a round trip like every other view of this
// component, and it has to keep the course id, because the workbench URL
// template the app fills is built from it: a router that dropped "id" would
// send every "open this course" link to the course list instead.
//
// The mount contract has to come out with the same keys the administrator
// workbench writes, because it is the same bundle reading it, and its URLs
// have to be site URLs with the two brace tokens intact: a router that
// percent-encoded "{course_id}" leaves fillUrlTemplate matching nothing, which
// is a link that silently goes nowhere.
//
// The bundle has to be on the page under the SITE application, not only the
// administrator one, which is the seam question: MountAssets keys on the mount
// and not on the client, and this is the first mount rendered by both.

heading('10g. The frontend instructor page');

[$instructorPath, $instructorParsed] = $roundTrip(['option' => 'com_vidyalms', 'view' => 'instructor']);
$instructorLeftover                  = $instructorParsed['__leftover'];
unset($instructorParsed['__leftover']);

assertThat(
    ($instructorParsed['view'] ?? null) === 'instructor' && $instructorLeftover === [],
    'the route for the instructor page parses back to itself',
    '/' . $instructorPath . ' => ' . json_encode($instructorParsed),
);

// The course id is a query parameter and not a key segment, deliberately: a
// key segment is read by NomenuRules as core's {id}-{alias} pair, which is the
// trap the slug views already had to be defended from. build() consumes the
// keys it turned into segments and leaves the rest as the query string, so
// what this asserts is that "id" survives the build rather than being eaten.
$instructorQuery = ['option' => 'com_vidyalms', 'view' => 'instructor', 'id' => 123];
$instructorBuilt = $router->build($instructorQuery);

assertThat(
    (int) ($instructorQuery['id'] ?? 0) === 123,
    'the workbench of one course keeps its course id in the URL',
    '/' . implode('/', $instructorBuilt) . '?' . http_build_query($instructorQuery),
);

// Route::_ and Session::getFormToken() both read Factory's application, and
// this script's is the console one. The site application is what a visitor
// would have, so it stands in for the length of this section and is put back
// afterwards: leaving it in place would change what every later helper builds.
$consoleApplication = Factory::$application;

// A SiteApplication built in the console has never run initialise(), so it
// holds no language, and the mount contract states the visitor's locale. The
// console's own language is the site's, so it stands in.
if ($siteApplication->getLanguage() === null) {
    $siteApplication->loadLanguage($consoleApplication->getLanguage());
}

Factory::$application = $siteApplication;

try {
    $listConfig      = \VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig::forInstructor(null);
    $courseConfig    = \VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig::forInstructor(
        $course->id,
        (string) $course->slug,
    );
    $configFailure   = null;
} catch (\Throwable $failure) {
    $listConfig    = [];
    $courseConfig  = [];
    $configFailure = $failure->getMessage();
} finally {
    Factory::$application = $consoleApplication;
}

assertThat(
    $configFailure === null,
    'the site builds the instructor mount contract',
    $configFailure ?? 'no throw',
);

// The administrator writes this same shape for the same bundle. Comparing the
// key sets rather than the values is the point: the URLs differ by design and
// a missing key is what an older bundle reads as undefined.
//
// The expectation is the administrator's own answer rather than a literal
// list, so a key added to the workbench contract is compared on the day it is
// added instead of the day somebody remembers to edit this line. A literal
// list here is a second statement of the contract, and it drifted the first
// time the contract grew.
$expectedKeys = array_keys(
    \VidyaLMS\Component\VidyaLMS\Administrator\Service\AdminMountConfig::workbench(null)
);
$actualKeys   = array_keys($listConfig);
sort($expectedKeys);
sort($actualKeys);

assertThat(
    $expectedKeys === $actualKeys,
    'the site mount carries the keys the workbench mount carries',
    implode(', ', $actualKeys),
);

assertThat(
    \array_key_exists('course', $listConfig) && $listConfig['course'] === null
        && \is_array($courseConfig['course'] ?? null)
        && (int) ($courseConfig['course']['id'] ?? 0) === $course->id,
    'no id means the course list and an id means that course',
    'list: ' . (\array_key_exists('course', $listConfig) ? json_encode($listConfig['course']) : 'absent')
    . ', course: ' . json_encode($courseConfig['course'] ?? null),
);

/** @var array<string, mixed> $instructorUrls */
$instructorUrls = \is_array($listConfig['urls'] ?? null) ? $listConfig['urls'] : [];

// The "&amp;" half is not decoration. These URLs go into a JSON attribute, not
// into markup, and Route::_ escapes for XHTML by default: an app following
// "?id=5&amp;Itemid=101" asks for a parameter called "amp;Itemid", and the
// workbench link lands on the course list every time.
assertThat(
    str_contains((string) ($instructorUrls['workbench'] ?? ''), '{course_id}')
    && str_contains((string) ($instructorUrls['preview'] ?? ''), '{slug}')
    && !str_contains((string) ($instructorUrls['workbench'] ?? ''), '&amp;')
    && !str_contains((string) ($instructorUrls['preview'] ?? ''), '&amp;'),
    'the URL templates keep the tokens the app substitutes, unescaped',
    'workbench ' . ($instructorUrls['workbench'] ?? '-') . ', preview ' . ($instructorUrls['preview'] ?? '-'),
);

assertThat(
    str_contains((string) (($listConfig['api'] ?? [])['base'] ?? ''), 'task=api.route&path=')
    && (($listConfig['api'] ?? [])['mode'] ?? '') === 'query'
    && str_contains((string) ($instructorUrls['upload'] ?? ''), 'task=api.upload'),
    'the api block is the site passthrough, not the API application',
    (string) (($listConfig['api'] ?? [])['base'] ?? '-'),
);

// The same seam the administrator page uses, asked for the site client. A Pro
// renderer bundle registered for the instructor mount has to reach this page
// too, and the free bundle still has to be first because it is what creates
// window.vidyalms.questionTypes.
$siteAssets = new \Joomla\CMS\WebAsset\WebAssetManager(
    (new \Joomla\CMS\WebAsset\WebAssetRegistry())->addExtensionRegistryFile('com_vidyalms'),
);

$siteContributed = \VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets::attach(
    $siteAssets,
    \VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssetContext::site(
        \VidyaLMS\Component\VidyaLMS\Administrator\Service\MountAssets::MOUNT_INSTRUCTOR,
    ),
);

$siteScripts = [];

foreach ($siteAssets->getAssets('script', true) as $asset) {
    $siteScripts[] = $asset->getName();
}

assertThat(
    ($siteScripts[0] ?? '') === 'com_vidyalms.app.instructor',
    'the site instructor page loads the workbench bundle first',
    implode(' then ', $siteScripts)
    . ($siteContributed === [] ? '' : ' (contributed: ' . implode(', ', $siteContributed) . ')'),
);

// The page, its layout and the menu item type an administrator picks. A view
// with no default.xml is a view no menu can point at, which is the difference
// between a page and a URL only we know about.
foreach (
    [
        'the site view'      => JPATH_SITE . '/components/com_vidyalms/src/View/Instructor/HtmlView.php',
        'its layout'         => JPATH_SITE . '/components/com_vidyalms/tmpl/instructor/default.php',
        'its menu item type' => JPATH_SITE . '/components/com_vidyalms/tmpl/instructor/default.xml',
    ] as $what => $file
) {
    assertThat(is_file($file), $what . ' is installed', $file);
}

// ---------------------------------------------------------------------------
// 10h. The Import screen
// ---------------------------------------------------------------------------
//
// The screen is registry driven: it offers every registered importer and holds
// no list of its own, so a mapper package that registers one appears without
// an edit here. What is new is that it also draws the options an importer
// declares, and the two things worth asserting on a real site are that the
// declarations survive the round trip through the descriptor the screen
// renders from, and that a checkbox comes back as a boolean.
//
// That second one is not pedantry. The form posts strings, and every importer
// reads its flags as ($options['enrolments'] ?? false) === true, which a
// string "1" fails: the run would quietly do the opposite of what was ticked.
// ImportOption::read() is in the core so this form and the WordPress one
// cannot disagree, and this is where it is exercised against the real
// registry rather than a fixture.

heading('10h. The Import screen');

$importerDescriptors = \VidyaLMS\Component\VidyaLMS\Administrator\Service\ImportSources::descriptors(
    $kernel->registries->importers,
);

$describedKeys = array_map(
    static fn (array $descriptor): string => $descriptor['key'],
    $importerDescriptors,
);

assertThat(
    $describedKeys === $kernel->registries->importers->keys(),
    'the screen offers exactly the registered importers, in registration order',
    implode(', ', $describedKeys),
);

// Every importer that reads a database has to be startable from this
// screen, and until R4 three of them were not: the probe was the literal
// 'cms-db:' and learndash-db:, tutor-db: and lifterlms-db: all answer
// false to it on purpose. The descriptor now names the scheme the importer
// itself answered to, and databaseSource() composes onto that scheme.
// accepts_database is two questions and not one: the importer declares a
// scheme, and the product whose tables it reads is really on THIS site.
// A LearnDash, Tutor or LifterLMS importer answers false on a Joomla site with
// no WordPress tables in its database, and that refusal is the correct answer
// rather than the R4 defect. So the declaration half is asserted for every
// importer that declares, on every site, and the acceptance half is asserted
// for the ones the site can actually start. The negative control is what keeps
// the first half from passing on a composition that lost the scheme.
$databaseImporters   = [];
$declaringImporters  = [];
$startable           = [];
$schemesRoundTrip    = true;

foreach ($importerDescriptors as $descriptor) {
    $importer = $kernel->registries->importers->get($descriptor['key']);

    if ($importer instanceof \Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceSchemes) {
        foreach ($importer->sourceSchemes() as $scheme) {
            $declaringImporters[] = $descriptor['key'] . ' => ' . $scheme;
            $composed = \VidyaLMS\Component\VidyaLMS\Administrator\Service\ImportSources::databaseSource(
                'courses=7',
                $scheme,
            );
            $foreign = \VidyaLMS\Component\VidyaLMS\Administrator\Service\ImportSources::databaseSource(
                'courses=7',
                'no-such-product-db:',
            );

            $schemesRoundTrip = $schemesRoundTrip
                && $scheme !== ''
                && str_starts_with($composed, $scheme)
                && !$importer->supports($foreign);
        }
    }

    if (!$descriptor['accepts_database']) {
        continue;
    }

    $databaseImporters[] = $descriptor['key'] . ' => ' . $descriptor['database_scheme'];
    $composed = \VidyaLMS\Component\VidyaLMS\Administrator\Service\ImportSources::databaseSource(
        'courses=7',
        $descriptor['database_scheme'],
    );
    // The round trip is the SHAPE: the screen composes onto the scheme the
    // importer declared. Whether the product is on this site is a separate
    // fact and the descriptor carries it separately, so this asserts the two
    // agree rather than folding one into the other. Calling supports() alone
    // here asked both questions at once, which is the defect one layer up:
    // a screen that hid learndash-db: on a site with no LearnDash described
    // the importer wrongly, and a harness that read the same answer called
    // it correct.
    $schemesRoundTrip = $schemesRoundTrip
        && $descriptor['database_scheme'] !== ''
        && str_starts_with($composed, $descriptor['database_scheme'])
        && $descriptor['source_installed'] === $importer->supports($composed);

    if ($descriptor['source_installed']) {
        $startable[] = $descriptor['key'];
    }
}

assertThat(
    $schemesRoundTrip && count($declaringImporters) >= 4 && $databaseImporters !== [],
    'every database importer names a scheme the screen composes and the importer accepts',
    'declared: ' . implode(', ', $declaringImporters)
    . '; describes a database source: ' . implode(', ', $databaseImporters)
    . '; product present on this site: ' . ($startable === [] ? 'none' : implode(', ', $startable)),
);

$declaredOptions = [];
$optionShapeOk   = true;

foreach ($importerDescriptors as $descriptor) {
    foreach ($descriptor['options'] as $option) {
        $declaredOptions[] = $descriptor['key'] . '.' . $option['key'];

        $optionShapeOk = $optionShapeOk
            && $option['label'] !== ''
            && \in_array($option['kind'], \Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption::KINDS, true)
            && ($option['kind'] !== 'choice' || $option['choices'] !== []);
    }
}

assertThat(
    $optionShapeOk && $declaredOptions !== [],
    'every declared importer option arrives with a label and a drawable kind',
    \count($declaredOptions) . ' option(s): ' . implode(', ', $declaredOptions),
);

// The Community Quiz importer is the one with booleans, and it is the one
// whose flags decide whether entitlements are written at all.
$communityQuiz = $kernel->registries->importers->get('community-quiz');
$readBack      = \Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption::read(
    $communityQuiz instanceof \Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface
        ? $communityQuiz->importOptions()
        : [],
    ['enrolments' => '1', 'table_prefix' => ' jos_cq_ ', 'dry_run' => '1'],
);

assertThat(
    ($readBack['enrolments'] ?? null) === true
    && ($readBack['publish'] ?? null) === false
    && ($readBack['table_prefix'] ?? null) === 'jos_cq_'
    && !\array_key_exists('dry_run', $readBack),
    'a ticked box reaches the importer as a boolean and dry_run stays the pipeline\'s',
    json_encode($readBack),
);

// ---------------------------------------------------------------------------
// 10i. The mail port: the header map, the envelope sender and part ordering
// ---------------------------------------------------------------------------
//
// Three of the four obligations in MailerPort's docblock are legal rather than
// aesthetic, and none of them can be checked without assembling a real MIME
// document: RFC 8058 one-click is honoured only when both List-Unsubscribe
// header names survive intact, RFC 2046 section 5.1.4 orders a
// multipart/alternative plain part before the HTML part, and the envelope
// sender is what makes an asynchronous bounce attributable at all.
//
// So the port is driven with a rendered message and the message is assembled
// rather than sent. The mailer factory here returns a Mail whose send() calls
// PHPMailer's own preSend(), which is the method that builds MIMEHeader and
// MIMEBody, and then stops. Nothing leaves the machine, and what is asserted
// is the exact document a transport would have been handed.

heading('10i. Mail headers, the envelope sender and part ordering');

$capturingMailer = new class () extends \Joomla\CMS\Mail\Mail {
    public string $captured = '';

    public function send()
    {
        // preSend() assembles the headers and the body and delivers nothing.
        $this->preSend();
        $this->captured = $this->getSentMIMEMessage();

        return true;
    }
};

$capturingFactory = new class ($capturingMailer) implements \Joomla\CMS\Mail\MailerFactoryInterface {
    public function __construct(private readonly \Joomla\CMS\Mail\Mail $mailer)
    {
    }

    public function createMailer(?\Joomla\Registry\Registry $settings = null): \Joomla\CMS\Mail\MailerInterface
    {
        return $this->mailer;
    }
};

// The transport a site that sends bulk mail actually uses. PHPMailer
// encodes a custom header value over 47 characters when the transport is
// PHP mail(), which corrupts a structured header; the case below asserts
// that the port detects that rather than shipping a broken one.
$capturingMailer->isSMTP();
$capturingMailer->setSender('smoke@example.test', 'VidyaLMS smoke');

$unsubscribeUri = 'https://example.test/unsubscribe/SMOKE-TOKEN_1';
$mailHeaders    = \Shondalai\VidyaLMS\Core\Port\Mail\MailHeaders::build(
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

$capturedMime   = '';
$capturedSender = '';

try {
    (new \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaMailer(
        $capturingFactory,
        $ports->translator,
        $ports->users,
    ))->send($renderedMessage);

    $capturedMime   = $capturingMailer->captured;
    $capturedSender = (string) $capturingMailer->Sender;
} catch (\Throwable $mailFailure) {
    step('the mail port threw while assembling', $mailFailure->getMessage());
}

$headerEnd   = strpos($capturedMime . "\r\n\r\n", "\r\n\r\n");
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
$htmlAt  = $capturedMime === '' ? false : stripos($capturedMime, 'Content-Type: text/html');

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
    'Sender="' . $capturedSender . '", From stayed "smoke@example.test"',
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

// The same message over PHP's own mail(), which is what a fresh Joomla is
// configured to use. PHPMailer's encodeHeader() Q-encodes any custom header
// value longer than 47 characters on that transport, and an encoded-word is
// not a URI: a receiver reading List-Unsubscribe would find nothing to follow
// and one-click would stop working with nothing in any log. The header still
// goes out, because an inert header is not harmful and dropping a legally
// required mechanism silently would be worse; what the port owes the site is
// to say so, and this is the assertion that it does.
$mailTransportMailer = new class () extends \Joomla\CMS\Mail\Mail {
    public string $captured = '';

    public function send()
    {
        $this->preSend();
        $this->captured = $this->getSentMIMEMessage();

        return true;
    }
};

$mailTransportMailer->isMail();
$mailTransportMailer->setSender('smoke@example.test', 'VidyaLMS smoke');

$logBefore = smokeComponentLogSize($joomlaRoot);

try {
    (new \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaMailer(
        new class ($mailTransportMailer) implements \Joomla\CMS\Mail\MailerFactoryInterface {
            public function __construct(private readonly \Joomla\CMS\Mail\Mail $mailer)
            {
            }

            public function createMailer(?\Joomla\Registry\Registry $settings = null): \Joomla\CMS\Mail\MailerInterface
            {
                return $this->mailer;
            }
        },
        $ports->translator,
        $ports->users,
    ))->send($renderedMessage);
} catch (\Throwable $mailTransportFailure) {
    step('the mail() transport threw while assembling', $mailTransportFailure->getMessage());
}

$logAfter = smokeComponentLogSize($joomlaRoot);

assertThat(
    $logAfter > $logBefore,
    'a transport that would corrupt a structured header is reported, not ignored',
    'the component log grew by ' . ($logAfter - $logBefore) . ' byte(s) naming the remedy',
);

// ---------------------------------------------------------------------------
// 10j. The unsubscribe page, the preference centre and the feedback intake
// ---------------------------------------------------------------------------
//
// All three are host surfaces over core contract routes, and what this section
// proves is the host half: that the URLs route, that a token survives a round
// trip through a router which reads a key segment as core's {id}-{alias} pair,
// that a GET of the unsubscribe page acts on nothing, and that a raw ARF body
// reaches the router instead of being rejected as bad JSON.
//
// The GET is the legal obligation. Mail security scanners follow every link in
// a message, which is the whole reason RFC 8058 specifies a POST for one-click;
// a GET that unsubscribed would turn a scanner into an opt-out.

heading('10j. The unsubscribe page, the preference centre and the feedback intake');

// A token is URL-safe base64: letters, digits, hyphen and underscore. The
// hyphen is the trap, because NomenuRules turns the first one of a key segment
// into a colon on the way back in.
$smokeToken = 'eyJ1IjoxfQ.SG1hYy1zbW9rZQ-Value_1';

[$unsubPath, $unsubParsed] = $roundTrip([
    'option' => 'com_vidyalms',
    'view'   => 'unsubscribe',
    'token'  => $smokeToken,
]);
$unsubLeftover = $unsubParsed['__leftover'];
unset($unsubParsed['__leftover']);

assertThat(
    ($unsubParsed['view'] ?? null) === 'unsubscribe'
    && ($unsubParsed['token'] ?? null) === $smokeToken
    && $unsubLeftover === [],
    'an unsubscribe token survives the SEF round trip with its hyphen',
    '/' . $unsubPath . ' => ' . json_encode($unsubParsed),
);

[$prefPath, $prefParsed] = $roundTrip(['option' => 'com_vidyalms', 'view' => 'preferences']);
$prefLeftover            = $prefParsed['__leftover'];
unset($prefParsed['__leftover']);

assertThat(
    ($prefParsed['view'] ?? null) === 'preferences' && $prefLeftover === [],
    'the preference centre routes and parses back to itself',
    '/' . $prefPath . ' => ' . json_encode($prefParsed),
);

// Neither page may ever be cached: both carry one person's state, and a stored
// copy of the unsubscribe page would show one recipient's subscription to the
// next visitor. Both views call the same refusal the mount pages use, and what
// is asserted here is the half a page render cannot show: that the listener it
// adds actually excludes the page from Joomla's own page cache.
$cacheExcluded    = false;
$cacheProbe       = new \Joomla\CMS\Event\PageCache\IsExcludedEvent('onPageCacheIsExcluded');
$cacheApplication = Factory::$application;

// The refusal reads Factory's application, and this script's is the console
// one, which has no page cache to refuse. The site application is what a
// visitor would have, so it stands in for the length of the probe.
Factory::$application = $siteApplication;

try {
    \VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig::forbidPageCache();
    $siteApplication->getDispatcher()->dispatch('onPageCacheIsExcluded', $cacheProbe);
} finally {
    Factory::$application = $cacheApplication;
}

foreach ((array) $cacheProbe->getArgument('result', []) as $cacheAnswer) {
    $cacheExcluded = $cacheExcluded || $cacheAnswer === true;
}

assertThat(
    $cacheExcluded,
    'a page carrying one person\'s state refuses Joomla\'s page cache',
    'onPageCacheIsExcluded answers true, so no copy is ever stored',
);

// The GET writes nothing. Proven against the tables the route would write to
// rather than against the page's own report of itself.
$preferenceRowsBefore = $database->count(Query::from('notification_preferences'));
$consentRowsBefore    = $database->count(Query::from('notification_consents'));

$previewResult = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

$centreResult = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
$feedbackResult = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
// 10k. The mount discovery key
// ---------------------------------------------------------------------------
//
// The workbench bundle and this extension ship separately, and a paid package
// adds its routes from a kernel-ready listener, so which of the contract's
// paths exist on an install is decided at boot. Without the key the bundle had
// to guess, and it guessed wrong in the one direction that matters: the quiz
// export control was on the contract, in the SDK and in the shipped workbench,
// and answered 404 on every site without the package that serves it.

heading('10k. The mount discovery key');

$workbenchConfig = \VidyaLMS\Component\VidyaLMS\Administrator\Service\AdminMountConfig::workbench(
    $course->id,
    (string) $course->slug,
);

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

// The site's instructor page mounts the same bundle and has to answer the same
// question. Two copies of the answer would be two answers.
Factory::$application = $container->get(\Joomla\CMS\Application\SiteApplication::class);

$siteWorkbenchConfig = \VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig::forInstructor(
    $course->id,
    (string) $course->slug,
);

Factory::$application = $application;

assertThat(
    ($siteWorkbenchConfig['routes'] ?? null) === $declaredRoutes,
    'the site instructor page declares the identical route list',
    'both surfaces answer from the running kernel rather than from a copy',
);

// ---------------------------------------------------------------------------
// 10l. The periodic task turn
// ---------------------------------------------------------------------------
//
// A sweep has no event behind it: nothing happens when a drip window opens,
// when a digest period closes or when a learner fails to log in, so the host's
// own clock has to start it. This section drives that turn end to end through
// the extension com_scheduler actually calls, and it does it with probe
// handlers registered under the four type names this wave introduces, so the
// path is proven before the core's own handlers exist and stays proven after.
//
// The runner still knows no task type. It resolves each queued row in the
// core's scheduled task registry, and the sweeps routine asks the registry
// which handlers the clock has to start; neither holds a list.

heading('10l. The periodic task turn');

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
$probeTask = null;

if ($periodicHandlers === []) {
    $probeTask = new SmokeProbeSweepTask('vidyalms.smoke.periodic', $sweepLog = new \ArrayObject());
    $kernel->registries->scheduledTasks->register($probeTask);
    $periodicHandlers[] = $probeTask;
}

$periodicKeys  = [];
$expectedRows  = 0;
$startedNames  = [];
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
$turnClock     = $ports->clock->now();

$sweepScheduler = $ports->scheduler;
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
    $bins   = max(1, $periodicHandler->binCount());
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
// the one routine this host runs, and this says so out loud.
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
$queuedTurnRows = static function () use ($database, $queueTable, $periodicKeys): int {
    $found = 0;

    foreach ($periodicKeys as $periodicKey) {
        $found += $database->count(Query::from($queueTable)->where('dedupe_key', '=', $periodicKey));
    }

    return $found;
};

$dispatchSweeps = static function () use ($taskDispatcher): array {
    $event = new \Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent('onExecuteTask', [
        'subject' => new \Joomla\Component\Scheduler\Administrator\Task\Task((object) [
            'id' => 0,
            'title' => 'VidyaLMS smoke sweeps',
            'type' => \VidyaLMS\Plugin\Task\VidyaLms\Extension\VidyaLms::ROUTINE_SWEEPS,
            'params' => '{}',
            'taskOption' => null,
        ]),
        'params' => (object) [],
        'routineId' => \VidyaLMS\Plugin\Task\VidyaLms\Extension\VidyaLms::ROUTINE_SWEEPS,
    ]);

    $taskDispatcher->dispatch('onExecuteTask', $event);

    return (array) $event->getArgument('snapshot', []);
};

$sweepSnapshot = $dispatchSweeps();
$sweepStarted  = \is_array($sweepSnapshot['tasks'] ?? null) ? $sweepSnapshot['tasks'] : [];
$queuedFirst   = $queuedTurnRows();

assertThat(
    array_values(array_intersect($startedNames, $sweepStarted)) === $startedNames
    && $queuedFirst === $expectedRows,
    'the clock starts every task that declares it needs starting, one row per bin',
    'started ' . \count($sweepStarted) . ' task(s), queued ' . var_export($sweepSnapshot['enqueued'] ?? null, true)
    . ' row(s), ' . $queuedFirst . ' of the ' . $expectedRows . ' this turn owns are in the queue',
);


// The other half of the shipped rule, and the half a site with a healthy queue
// never exercises: a handler whose unstarted work already fills a period is
// held back and named, rather than silently adding another period's rows to a
// queue that is running none of them. Asserted as a set, because the order
// registration happens to produce is not a promise.
$reportedBacklog = $sweepSnapshot['backlogged'] ?? [];
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
// clock that fires twice leaves one row per bin. Both hosts are documented
// multi-firers, so this is the normal case rather than the exotic one.
$dispatchSweeps();

assertThat(
    $queuedTurnRows() === $queuedFirst && $queuedFirst > 0,
    'a second turn inside one period queues no second row',
    $queuedFirst . ' row(s) after the first turn, ' . $queuedTurnRows() . ' after the second',
);

// The provider-generated keys above are the exact rows this turn owns. Move
// only those pending rows to the same fixed ancient instant as the focused
// queue check, so an unrelated development-site backlog cannot starve them.
$prioritizedSweepRows = 0;

foreach ($periodicKeys as $periodicKey) {
    $prioritizedSweepRows += $database->update(
        $queueTable,
        ['run_at' => $smokeQueueRunAt->format('Y-m-d H:i:s')],
        ['dedupe_key' => $periodicKey, 'status' => 'pending'],
    );
}

assertThat(
    $prioritizedSweepRows === $expectedRows,
    'only this turn\'s periodic rows are made first in the due queue',
    $prioritizedSweepRows . ' of ' . $expectedRows . ' exact dedupe key(s) updated',
);

// Now drain them through the same routine com_scheduler calls. The batch is
// exactly the number of owned rows: the harness proves the normal queue path
// without consuming any later environmental work.
$drainSweepsEvent = new \Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent('onExecuteTask', [
    'subject' => new \Joomla\Component\Scheduler\Administrator\Task\Task((object) [
        'id' => 0,
        'title' => 'VidyaLMS smoke drain',
        'type' => \VidyaLMS\Plugin\Task\VidyaLms\Extension\VidyaLms::ROUTINE_DRAIN_JOBS,
        'params' => '{}',
        'taskOption' => null,
    ]),
    'params' => (object) [
        'batch_size' => $expectedRows,
        'max_attempts' => 1,
        'stuck_minutes' => 0,
        'retry_backoff_minutes' => 0,
    ],
    'routineId' => \VidyaLMS\Plugin\Task\VidyaLms\Extension\VidyaLms::ROUTINE_DRAIN_JOBS,
]);

$taskDispatcher->dispatch('onExecuteTask', $drainSweepsEvent);

/** @var array<string, mixed> $sweepDrain */
$sweepDrain = (array) $drainSweepsEvent->getArgument('snapshot', []);

$finished = 0;
$failed   = [];

foreach ($periodicKeys as $periodicKey) {
    $sweepRow = $database->selectOne(Query::from($queueTable)->where('dedupe_key', '=', $periodicKey));

    if ($sweepRow === null) {
        $failed[] = $periodicKey . ' => row missing';

        continue;
    }

    if (($sweepRow['status'] ?? '') === 'done') {
        $finished++;
    } else {
        $failed[] = (string) $sweepRow['dedupe_key'] . ' => ' . (string) ($sweepRow['status'] ?? 'null')
            . ' ' . substr((string) ($sweepRow['last_error'] ?? ''), 0, 60);
    }
}

assertThat(
    $finished === $expectedRows && $failed === [],
    'every row the turn queued is run by the drain, resolved in the registry',
    $finished . ' of ' . $expectedRows . ' finished; drain '
    . json_encode([
        'done' => $sweepDrain['done'] ?? null,
        'failed' => $sweepDrain['failed'] ?? null,
        'skipped' => $sweepDrain['skipped'] ?? null,
        'reclaimed' => $sweepDrain['reclaimed'] ?? null,
    ])
    . ($failed === [] ? '' : '; unfinished: ' . implode(' | ', \array_slice($failed, 0, 3))),
);

// The turn's rows are this run's, and they go with it. They are addressed by
// the exact keys the providers minted rather than by a pattern, so a row this
// run did not create cannot be taken with them.
$sweepRowsRemoved = 0;

foreach ($periodicKeys as $periodicKey) {
    $sweepRowsRemoved += $database->delete($queueTable, ['dedupe_key' => $periodicKey]);
}

step('sweep queue rows removed', $sweepRowsRemoved . ' of ' . $expectedRows . ' row(s)');

// ---------------------------------------------------------------------------
// 10m. A drip-locked lesson and a cohort release
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

heading('10m. A drip-locked lesson and a cohort release');

$secondLessonId = (int) $second->id;
$courseIdForGating = (int) $course->id;
$authorPrincipal = $actor;
$learnerPrincipal = $learner;


$opensAt = (new \DateTimeImmutable('+30 days', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');

$setRule = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

    $curriculum = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
    $clearRule = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

$createCohort = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $removeCohort = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call('DELETE', '/manage/cohorts/' . $cohortId, $authorPrincipal);

        step('smoke cohort removed', 'status ' . $removeCohort->status);
    }
}

// ---------------------------------------------------------------------------
// 10n. The envelope sender, from the queue to the transport
// ---------------------------------------------------------------------------
//
// The one thing 10i above cannot prove. It hands the port a message it built
// itself and asserts the port carries the envelope sender it was given; what
// composes that address is the queue, and the queue composed the bare tag
// "vy{id}". A bare tag is not an address: PHPMailer validates Sender inside
// preSend() and refuses the whole message, so every queued notification on
// both hosts went failed_permanent without a transport ever seeing it, and
// nothing anywhere asserted the shape.
//
// So this section drives the shipped path end to end. A real queue row, the
// shipped send task resolved from the registry, the shipped email channel,
// the real JoomlaMailer, and a Mail whose send() runs PHPMailer's own
// preSend() and then stops. Nothing leaves the machine; what is asserted is
// the reverse path PHPMailer accepted, the document it assembled and the
// state the row reached.

heading('10n. The envelope sender, from the queue to the transport');

$envelopeTemplateKey = 'vidyalms.smoke.envelope';
$envelopeChannelKey  = \Shondalai\VidyaLMS\Core\Provider\Notification\EmailNotificationChannel::KEY;
$envelopeRegistryKey = \Shondalai\VidyaLMS\Core\Application\Notification\NotificationSender::REGISTRY_CHANNEL;

// The host default is the site's own from address and never Uri::root():
// Uri::root() is composed from the request on a site with no live_site set,
// and the queue is drained by a scheduled task that has no request at all.
$hostEnvelopeDomain = \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::envelopeDomain();
$siteMailFrom       = trim((string) Factory::getApplication()->get('mailfrom', ''));
$optionDomain       = trim((string) \Joomla\CMS\Component\ComponentHelper::getParams('com_vidyalms')->get('envelope_domain', ''));

assertThat(
    $hostEnvelopeDomain === ($optionDomain !== '' ? $optionDomain : $siteMailFrom) && $hostEnvelopeDomain !== '',
    'the host default is the site\'s own from address and never the request host',
    'envelope domain "' . $hostEnvelopeDomain . '" from '
    . ($optionDomain !== '' ? 'the component option' : 'the mailfrom "' . $siteMailFrom . '"')
    . '; HTTP_HOST "' . (string) ($_SERVER['HTTP_HOST'] ?? '') . '" is not consulted',
);

$atSign         = strrpos($hostEnvelopeDomain, '@');
$hostDomainOnly = strtolower($atSign === false ? $hostEnvelopeDomain : substr($hostEnvelopeDomain, $atSign + 1));

$kernel->notificationTemplates->save($envelopeTemplateKey, 'en-GB', $envelopeChannelKey, [
    'subject'   => 'VidyaLMS smoke: the reverse path',
    'body_text' => 'This message exists so the envelope sender can be read off the wire.',
    'state'     => \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository::STATE_PUBLISHED,
]);

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
    $ports,
    $database,
    $learnerId,
    $envelopeTemplateKey,
    $envelopeChannelKey,
    $envelopeDedupe,
): array {
    // A run that died mid-section leaves its own row behind, and enqueue()
    // answers null rather than a second row for a digest already present.
    $database->delete('notification_queue', ['dedupe_key' => $envelopeDedupe($suffix)]);

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
            $ports->clock->now(),
            \Shondalai\VidyaLMS\Core\Domain\Notification\QueueState::Pending,
            $learnerId,
            $envelopeChannelKey,
            null,
            null,
            ['learner_name' => 'VidyaLMS smoke'],
            'UTC',
        ),
    );

    // preSend() assembles MIMEHeader and MIMEBody and validates every address
    // the message carries, which is where the bare tag was refused. Nothing
    // is posted.
    $capture = new class () extends \Joomla\CMS\Mail\Mail {
        public string $mime = '';
        public string $sender = '';
        public string $fromAddress = '';

        public function send()
        {
            $this->preSend();

            $this->mime        = $this->getSentMIMEMessage();
            $this->sender      = (string) $this->Sender;
            $this->fromAddress = (string) $this->From;

            return true;
        }
    };

    $capture->isSMTP();
    $capture->setSender('smoke@example.test', 'VidyaLMS smoke');

    $factory = new class ($capture) implements \Joomla\CMS\Mail\MailerFactoryInterface {
        public function __construct(private readonly \Joomla\CMS\Mail\Mail $mailer)
        {
        }

        public function createMailer(?\Joomla\Registry\Registry $settings = null): \Joomla\CMS\Mail\MailerInterface
        {
            return $this->mailer;
        }
    };

    // The shipped channel over a captured transport, installed through the
    // registry's own override and handed back afterwards, so the site keeps
    // the channel it had whatever this section does.
    $displaced = $kernel->registries->notificationChannels->replace(
        new \Shondalai\VidyaLMS\Core\Provider\Notification\EmailNotificationChannel(
            $ports->users,
            new \VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaMailer($factory, $ports->translator, $ports->users),
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseEmailLogRepository($database, $ports->clock),
            $ports->translator,
        ),
    );

    $summary = '';

    try {
        $task = $kernel->registries->scheduledTasks->get(
            \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationSendTask::TYPE,
        );

        $summary = $task->run(new \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun(
            0,
            ['limit' => smokeEnvelopeDrainLimit($database, $queueId)],
            Principal::guest(),
        ));
    } catch (\Throwable $failure) {
        $summary = 'the send task threw: ' . $failure->getMessage();
    } finally {
        $kernel->registries->notificationChannels->replace($displaced);
    }

    $row = $kernel->notificationQueue->find($queueId);

    return [
        'id'        => $queueId,
        'sender'    => $capture->sender,
        'mime'      => $capture->mime,
        'from'      => $capture->fromAddress,
        'summary'   => $summary,
        'state'     => $row === null ? 'gone' : $row->state->value,
        'messageId' => $row === null ? '' : (string) $row->providerMessageId,
    ];
};

$envelopeDefault = $driveEnvelope('default');
$expectedDefault = 'bounce+vy' . $envelopeDefault['id'] . '@' . $hostDomainOnly;

assertThat(
    $envelopeDefault['sender'] === $expectedDefault
    && filter_var($envelopeDefault['sender'], FILTER_VALIDATE_EMAIL) !== false,
    'the queue composes a full VERP address and PHPMailer accepts it as MAIL FROM',
    'Sender="' . $envelopeDefault['sender'] . '", expected "' . $expectedDefault . '"'
    . ' (' . $envelopeDefault['summary'] . ')',
);

assertThat(
    $envelopeDefault['mime'] !== ''
    && stripos($envelopeDefault['mime'], 'VidyaLMS smoke: the reverse path') !== false
    && $envelopeDefault['from'] === 'smoke@example.test',
    'the message reaches the transport assembled, with the envelope sender apart from the From header',
    $envelopeDefault['mime'] === ''
        ? 'nothing was assembled'
        : strlen($envelopeDefault['mime']) . ' byte(s) of MIME, From stayed "' . $envelopeDefault['from'] . '"',
);

assertThat(
    $envelopeDefault['state'] === \Shondalai\VidyaLMS\Core\Domain\Notification\QueueState::Sent->value
    && $envelopeDefault['messageId'] === 'vy' . $envelopeDefault['id'],
    'the queue row reaches sent and keeps the tag as its provider message id',
    'state "' . $envelopeDefault['state'] . '", provider_message_id "' . $envelopeDefault['messageId'] . '"',
);

// The same row again with the channel's own settings in place, which is the
// documented way a site that receives bounces on a dedicated domain says so.
// The row is merged and handed back exactly as it was found: the feedback
// secret lives in the same row and losing it would close the intake.
$envelopeSettingsBefore = $kernel->providerSettings->find($envelopeRegistryKey, $envelopeChannelKey, 'site', 0);

$kernel->providerSettings->save(
    $envelopeRegistryKey,
    $envelopeChannelKey,
    'site',
    0,
    array_merge($envelopeSettingsBefore ?? [], [
        \Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender::SETTING_DOMAIN => 'bounces.smoke.example',
        \Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender::SETTING_PREFIX => 'verp',
    ]),
);

$envelopeConfigured = $driveEnvelope('configured');
$expectedConfigured = 'verp+vy' . $envelopeConfigured['id'] . '@bounces.smoke.example';

if ($envelopeSettingsBefore === null) {
    $database->delete('provider_settings', [
        'registry_key' => $envelopeRegistryKey,
        'provider_key' => $envelopeChannelKey,
        'scope_type'   => 'site',
        'scope_id'     => 0,
    ]);
} else {
    $kernel->providerSettings->save($envelopeRegistryKey, $envelopeChannelKey, 'site', 0, $envelopeSettingsBefore);
}

assertThat(
    $envelopeConfigured['sender'] === $expectedConfigured
    && $envelopeConfigured['state'] === \Shondalai\VidyaLMS\Core\Domain\Notification\QueueState::Sent->value,
    'the channel\'s own settings choose the bounce domain and the user part',
    'Sender="' . $envelopeConfigured['sender'] . '", expected "' . $expectedConfigured
    . '", row state "' . $envelopeConfigured['state'] . '"',
);

assertThat(
    ($envelopeSettingsBefore === null
        ? $kernel->providerSettings->find($envelopeRegistryKey, $envelopeChannelKey, 'site', 0) === null
        : $kernel->providerSettings->find($envelopeRegistryKey, $envelopeChannelKey, 'site', 0) === $envelopeSettingsBefore),
    'the channel settings row is handed back exactly as it was found',
    $envelopeSettingsBefore === null
        ? 'there was no row and there is none now'
        : json_encode($envelopeSettingsBefore),
);

// This section's rows go with it, addressed by the exact digests it minted.
$envelopeRowsRemoved = 0;

foreach (['default', 'configured'] as $suffix) {
    $envelopeRowsRemoved += $database->delete('notification_queue', ['dedupe_key' => $envelopeDedupe($suffix)]);
}

$envelopeLogsRemoved     = $database->delete('email_log', ['template_key' => $envelopeTemplateKey]);
$envelopeTemplateRemoved = $database->delete('notification_templates', ['template_key' => $envelopeTemplateKey]);

step(
    'smoke envelope rows removed',
    $envelopeRowsRemoved . ' queue, ' . $envelopeLogsRemoved . ' email log, '
    . $envelopeTemplateRemoved . ' template row(s)',
);

// ---------------------------------------------------------------------------
// 10o. Availability gates a quiz attempt
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

heading('10o. Availability gates a quiz attempt');

$gatedCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke gated course',
    GATED_COURSE_SLUG,
    'A course created by build/smoke.php to prove availability refuses a quiz attempt.',
    '<p>Seeded by the VidyaLMS Joomla adapter smoke test.</p>',
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

$createQuiz = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

    $gateQuiz = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

        $blockedStart = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call('POST', '/quizzes/' . $gatedQuizId . '/attempts', $learner);

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
            'status ' . \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call('DELETE', '/manage/quizzes/' . $gatedQuizId . '/availability', $actor)->status,
        );

        $openStart = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call('POST', '/quizzes/' . $gatedQuizId . '/attempts', $learner);

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
// 10p. Credentials: a template with elements, the frozen snapshot, a revoke,
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

heading('10p. Credentials');

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

    $createTemplate = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $elementTypes = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $pageList = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

            $addElement = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $refusedQr = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $preview = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $publishedTemplate = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $templateBefore = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

        $renamed = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

        $stale = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $secondPage = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

        $pagesReordered = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

        $partialOrder = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

        $elements = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

            $elementsReordered = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

            $movedElement = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $removeTemplate = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $refusedDelete = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

            $lockRefusal = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
            $credential = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

    $reissue = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

        $oldCode = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
    $suspend = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
        $suspended = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
            'GET',
            '/certificates/' . rawurlencode($activeCode) . '/verify',
            Principal::guest(),
        );

        $reinstate = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
            'POST',
            '/admin/certificates/' . $activeId . '/reinstate',
            $actor,
        );

        $reinstated = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

    $revoke = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

    $afterRevoke = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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

    $unknownCode = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
    $replace = \VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute::call(
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
// 10q. The host facts a credential is built from
// ---------------------------------------------------------------------------
//
// This process is a console application: it has no request, and Uri::base()
// says so itself by branching on PHP_SAPI. That is the whole point of this
// section. Issuance is scheduled work on a real site too, drained by the
// Scheduled Task routine and by cli/joomla.php, and a credential minted there
// used to carry an id composed from the CLI entry point's own path
// (http://localhost/cli/joomla.php/index.php/...). Once such an id is inside a
// signed document it can never be corrected, because a wallet holding it is
// the only party who can, and it will not.
//
// So the host resolves its own public root from configuration rather than from
// the request, and everything a credential quotes is built on that: the
// credential id, the status list address, and the issuer a relying party reads
// to find out who awarded this.

heading('10q. Host facts behind a credential');

$siteRoot = \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::siteRootUrl();
$credentialBase = \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::credentialBaseUrl();
$statusListBase = \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::statusListBaseUrl();

assertThat(
    \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::hasWebRequest() === false,
    'this run has no request behind it, which is what makes the rest of this section worth asserting',
    'PHP_SAPI=' . PHP_SAPI . ' application=' . get_class(Factory::getApplication()),
);

if ($credentialBase === '') {
    pending(
        'a credential minted with no request carries a resolvable id',
        'neither live_site nor the site_base_url component option is set on this site,'
            . ' so the host refuses to guess a root rather than freezing a wrong one',
    );
} else {
    assertThat(
        !str_contains($credentialBase, basename(__FILE__))
            && !str_contains($credentialBase, '/cli/')
            && str_starts_with($credentialBase, $siteRoot),
        'the credential base is the site root and not the path of the script that happens to be running',
        'site root ' . $siteRoot . ', credential base ' . $credentialBase,
    );

    assertThat(
        $statusListBase !== '' && str_starts_with($statusListBase, $credentialBase),
        'the status list is published under the same base, so a verifier follows one host',
        'status list base ' . $statusListBase,
    );
}

// The kernel this run built has to be holding the same two, or the adapter
// resolves one thing and the core mints another.
assertThat(
    $kernel->credentialBaseUrl === $credentialBase && $kernel->statusListBaseUrl === $statusListBase,
    'the kernel was handed the bases the adapter resolved',
    'kernel credential base ' . ($kernel->credentialBaseUrl === '' ? '(empty)' : $kernel->credentialBaseUrl),
);

$frozenRow = $database->selectOne(
    Query::from('certificates')
        ->where('enrolment_id', '=', $enrolmentId)
        ->where('status', '=', 'active'),
);
$frozenJson = $frozenRow['credential_json'] ?? null;

if ($credentialBase === '') {
    pending(
        'the credential frozen onto the row names this site',
        'the host supplied no credential base, so the row is deliberately left unfrozen',
    );
} elseif (!\is_string($frozenJson) || $frozenJson === '') {
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
        (string) ($issuer['name'] ?? '')
            === \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::siteName(),
        'the issuer defaults to this site, which is the fact Joomla has and the core cannot',
        'site name ' . \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::siteName(),
    );
}

// The seeded default template's stable key. 1.7.0 added template_key without
// filling it, so on every upgraded site the shipped default carried NULL and a
// lookup by the shipped key missed, while a fresh install found it. A schema
// drift check cannot see this: both sides match structurally and only the row
// differs. The installer's data step is what closes it.
$seededDefault = $database->selectOne(
    Query::from('certificate_templates')->where('is_default', '=', 1),
);

assertThat(
    (string) ($seededDefault['template_key'] ?? '') === 'vidyalms.default',
    'the seeded default template carries the key the core looks it up by',
    'template_key=' . var_export($seededDefault['template_key'] ?? null, true),
);

// The reconciliation sweep. Completion is terminal and CourseCompleted cannot
// re-fire, so a completed enrolment whose issuance failed is a gap nothing
// else in the product will ever look for. It is provisioned the way every
// periodic handler is: the host runs one routine, and the core's registry says
// what that routine has to start.
$backfillType = 'vidyalms.certificate.backfill';

assertThat(
    $kernel->registries->scheduledTasks->has($backfillType)
        && $kernel->registries->scheduledTasks->get($backfillType)
            instanceof \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically,
    'the backfill sweep is a registered task type that declares the clock has to start it',
    $backfillType . ' registered=' . var_export($kernel->registries->scheduledTasks->has($backfillType), true),
);

$sweepRoutines = (int) $db->setQuery(
    $db->createQuery()
        ->select('COUNT(*)')
        ->from($db->quoteName('#__scheduler_tasks'))
        ->where($db->quoteName('type') . ' = ' . $db->quote('vidyalms.sweeps'))
)->loadResult();

// What the installer promises, in its own words. provisionScheduledTasks()
// creates nothing when a row of the type already exists, and its comment says
// a site may keep a second, disabled copy of a routine on purpose. So the
// invariant is not that the site holds exactly one row of the type, which
// anybody with the Scheduled Tasks screen may break without the installer
// having done anything: it is that the installer never adds a second copy of
// its own routine, and that at least one sweeps routine is enabled so the
// clock still turns. Asserting the site-wide count instead failed this harness
// on a shared development site over a routine another run had created, while
// the installer had behaved exactly as designed.
$installerSweepTitle = 'VidyaLMS: Start periodic sweeps';

$installerSweepRoutines = (int) $db->setQuery(
    $db->createQuery()
        ->select('COUNT(*)')
        ->from($db->quoteName('#__scheduler_tasks'))
        ->where($db->quoteName('type') . ' = ' . $db->quote('vidyalms.sweeps'))
        ->where($db->quoteName('title') . ' = ' . $db->quote($installerSweepTitle))
)->loadResult();

$enabledSweepRoutines = (int) $db->setQuery(
    $db->createQuery()
        ->select('COUNT(*)')
        ->from($db->quoteName('#__scheduler_tasks'))
        ->where($db->quoteName('type') . ' = ' . $db->quote('vidyalms.sweeps'))
        ->where($db->quoteName('state') . ' = 1')
)->loadResult();

assertThat(
    $installerSweepRoutines === 1 && $enabledSweepRoutines >= 1,
    'the host provisions exactly one sweeps routine of its own, however many times the package is'
        . ' installed, and at least one sweeps routine on the site is enabled',
    'vidyalms.sweeps rows = ' . $sweepRoutines . ', of them installer-titled = ' . $installerSweepRoutines
        . ', enabled = ' . $enabledSweepRoutines,
);

// Section 10l already dispatched the routine and asserted that this handler
// is among the ones it started, over the real com_scheduler event. What is
// left is the provisioning itself: the routine row exists, and installing the
// package again does not make a second one.

// The API mount's content negotiation. ApiApplication::route() negotiates
// against the route's own list before anything of ours is dispatched, so a
// media type missing from it is a 406 the host invented for a document the
// core would have served. The list used to be a constant naming six formats;
// it is the registry's answer now, so a seventh format is a registration.
$mountPlugin = 'VidyaLMS\\Plugin\\Webservices\\VidyaLms\\Extension\\VidyaLms';

if (!class_exists($mountPlugin)) {
    pending(
        'the API mount negotiates every media type this build can serve',
        'plg_webservices_vidyalms is not installed on this site',
    );
} else {
    /** @var list<string> $mountTypes */
    $mountTypes = $mountPlugin::negotiableMediaTypes();
    $servable = $kernel->getCredential->servableMediaTypes();
    $missing = array_values(array_diff($servable, $mountTypes));

    assertThat(
        $missing === []
            && \in_array('application/json', $mountTypes, true)
            && \in_array('application/problem+json', $mountTypes, true),
        'the API mount negotiates every media type this build can serve, plus the two the contract is written in',
        'mount=' . implode(', ', $mountTypes) . ' | servable=' . implode(', ', $servable)
            . ' | missing=' . ($missing === [] ? 'none' : implode(', ', $missing)),
    );
}

// The binary response convention. It used to be an untyped array literal
// specified by a comment, and each adapter wrote its own sniff to recognise
// it: two emitters had to agree forever on a rule written down nowhere, and a
// drift between them serves base64 text where a wallet expected a PNG. The
// core says so on the object now, and both mounts ask.
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
}


// ---------------------------------------------------------------------------
// 10r. The free seat subsystem
// ---------------------------------------------------------------------------
//
// Everything in this section is arithmetic over rows, and the two hosts count
// rows with different drivers, so it is a shared contract body rather than a
// section written twice. What rides on the arithmetic is the whole of the
// seat design: a signed ledger whose sum has to equal capacity minus the
// seats being held, a state machine whose edges are guarded, an invitation
// that reserves the seat it promises, and a reclaim that must not strand a
// learner's unfinished work.
//
// The contract seeds two organisations of its own, so the alert half can ask
// who an exhausting pool reached and, more importantly, who it did not. It
// removes every row it wrote in a finally, including its ledger rows: the
// ledger is append-only for a site's own history, and an organisation created
// and destroyed inside one run has none.

heading('10r. Seats, invitations and the reclaim ceremony');

$seatCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke seat course',
    SEAT_COURSE_SLUG,
    'A course created by build/smoke.php so the seat contract has something to fund.',
    '<p>Seeded by the VidyaLMS Joomla adapter smoke test.</p>',
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

$seatAccounts = createSeatContractAccounts($container);

step(
    'seat contract fixture',
    'course ' . $seatCourseId . ', accounts ' . implode(', ', array_values($seatAccounts)),
);

['failures' => $seatFailures, 'facts' => $seatFacts] =
    \Shondalai\VidyaLMS\Tooling\Contract\OrganisationSeatContract::run(
        $kernel,
        $ports,
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
    $seatUser = $container->get(UserFactoryInterface::class)->loadUserById($seatUserId);
    $ports->database->delete('notification_queue', ['recipient_user_id' => $seatUserId]);
    $ports->database->delete('activity_log', ['user_id' => $seatUserId]);
    $ports->database->delete('email_log', ['user_id' => $seatUserId]);
    $seatUser->delete();
}

step('seat course rows removed', (string) removeSmokeCourse($database, $seatCourseId) . ' row(s)');
step('seat contract accounts removed', \count($seatAccounts) . ' account(s)');


// ---------------------------------------------------------------------------
// 10s. The organisation import surface and the row level error model
// ---------------------------------------------------------------------------
//
// R4's whole surface in one journey, over the shipped routes against real
// MySQL: an organisation-scoped load with one malformed row that ends
// completed_with_problems, the paged problems page, the failed-rows download
// with its hostile cell neutralised, the dry pass writing nothing, the site
// list still serving a job that belongs to no organisation, and the six format
// importers refusing honestly on a host where none of their products is
// installed.
//
// It runs last of the sections that write, because it registers an importer
// into the booted registry for the length of the process and 10h counts that
// registry. Everything it creates it removes in its own finally; the
// admin_access_log rows its GETs write are append-only and are reported.

heading('10s. Organisation imports, row problems and the failed rows download');

['failures' => $importFailures, 'facts' => $importFacts, 'access_log_rows_added' => $importAccessRows] =
    \Shondalai\VidyaLMS\Tooling\Contract\OrganisationImportContract::run($kernel, $ports, $actor);

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

// The OneRoster half of the contract is about which host account each roster
// row lands on, and RosterImporter decides that by email: a person this site
// has no account for is skipped as a referential miss, and the two membership
// assertions then read nothing. Only the host can make a host account, so the
// three the package names are seeded here and removed with the rest.
$rosterAccounts = createRosterContractAccounts($container);

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
    $rosterUser = $container->get(UserFactoryInterface::class)->loadUserById($rosterUserId);
    smokeDelete($ports->database, 'notification_queue', ['recipient_user_id' => $rosterUserId]);
    smokeDelete($ports->database, 'activity_log', ['user_id' => $rosterUserId]);
    smokeDelete($ports->database, 'email_log', ['user_id' => $rosterUserId]);
    $rosterUser->delete();
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

// The half a shared contract cannot check on this host: whether the free
// identity clause has a caller here. R5 shipped the port, the source and the
// use case and nothing called it after a login, so the assertion path was
// wired and never fired.
$loginListeners = 0;

foreach ($application->getDispatcher()->getListeners('onUserAfterLogin') as $loginListener) {
    $loginTarget = \is_array($loginListener) ? ($loginListener[0] ?? null) : null;

    if (\is_object($loginTarget) && str_contains($loginTarget::class, 'VidyaLms')) {
        $loginListeners++;
    }
}

assertThat(
    $loginListeners > 0,
    'this host calls the core after a login, so the free identity clause has a caller',
    $loginListeners . ' VidyaLMS listener(s) on onUserAfterLogin',
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
// It runs after 10v because it writes to twelve tables that were empty until
// 1.26.0, and it removes every row it wrote in its own finally, restoring every
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
    '<p>Seeded by the VidyaLMS Joomla adapter smoke test.</p>',
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
$bridgeKeys     = $kernel->registries->modelBridges->keys();
$foreignBridges = providersOutsideTheFreeBuild($kernel->registries->modelBridges, ['null']);

assertThat(
    $proInstalled
        ? \in_array('null', $bridgeKeys, true)
            && $foreignBridges === array_values(array_diff($bridgeKeys, ['null']))
        : $bridgeKeys === ['null'],
    $proInstalled
        ? 'the null bridge is registered and every other bridge came from outside the free build'
        : 'the free build offers exactly one model bridge, and its key is null',
    implode(', ', $bridgeKeys)
        . ($proInstalled ? ' (from a package: ' . implode(', ', $foreignBridges) . ')' : ''),
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

$aiTableList = $db->getTableList();

foreach ($aiTables as $aiTable) {
    $aiPrefixed = $db->replacePrefix('#__vidyalms_' . $aiTable);

    if (!\in_array($aiPrefixed, $aiTableList, true)) {
        continue;
    }

    $aiPresent++;

    foreach (array_keys($db->getTableColumns($aiPrefixed)) as $aiColumn) {
        if (\in_array($aiColumn, ['ai_score', 'ai_confidence', 'ai_risk'], true)) {
            $aiForbidden[] = $aiTable . '.' . (string) $aiColumn;
        }
    }
}

$aiVectorTablePresent = \in_array($db->replacePrefix('#__vidyalms_ai_chunk_vectors'), $aiTableList, true);

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
    '<p>Seeded by the VidyaLMS Joomla adapter smoke test.</p>',
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
// that never fires would be silent. There is no task row for it and there never
// will be: it is started by "vidyalms.sweeps" and run by "vidyalms.drain_jobs",
// exactly as every other registered periodic type is.
$reportTaskType = \Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ScheduleReportsTask::KEY;
$reportTaskPeriodic = $kernel->registries->scheduledTasks->has($reportTaskType)
    && $kernel->registries->scheduledTasks->get($reportTaskType)
        instanceof \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;

assertThat(
    $reportTaskPeriodic,
    'reports.schedule is registered on this site and is started by the clock, with no task row of its own',
    $reportTaskType . ($reportTaskPeriodic ? ' is periodic' : ' is missing or is not periodic'),
);

// And the routine that starts it exists and is enabled. The installer creates
// it and never re-enables one somebody switched off, so a site whose sweeps are
// off is a site where scheduled reports silently never arrive; that is worth an
// assertion rather than a docblock.
$sweepStates = [];

foreach (['vidyalms.sweeps', 'vidyalms.drain_jobs'] as $clockRoutine) {
    $sweepQuery = $db->createQuery()
        ->select($db->quoteName('state'))
        ->from($db->quoteName('#__scheduler_tasks'))
        ->where($db->quoteName('type') . ' = ' . $db->quote($clockRoutine));
    $sweepStates[$clockRoutine] = array_map('intval', (array) $db->setQuery($sweepQuery)->loadColumn());
}

$sweepsEnabled = \in_array(1, $sweepStates['vidyalms.sweeps'], true)
    && \in_array(1, $sweepStates['vidyalms.drain_jobs'], true);

assertThat(
    $sweepsEnabled,
    'both clock routines every registered task type rides exist on this site and are enabled',
    'sweeps=' . implode(',', $sweepStates['vidyalms.sweeps'] ?: ['none'])
        . ' drain_jobs=' . implode(',', $sweepStates['vidyalms.drain_jobs'] ?: ['none']),
);

// The four tables 1.27.0 creates exist on this site, and grade_changes is the
// one with no updated_at. That absence is the design statement, and a host
// migration is where one would first appear.
$gradebookTables = ['report_schedules', 'grade_items', 'grade_cells', 'grade_changes'];
$gradebookPresent = 0;
$changeLogTouchable = false;

$gradebookTableList = $db->getTableList();

foreach ($gradebookTables as $gradebookTable) {
    $gradebookPrefixed = $db->replacePrefix('#__vidyalms_' . $gradebookTable);

    if (!\in_array($gradebookPrefixed, $gradebookTableList, true)) {
        continue;
    }

    $gradebookPresent++;

    if (
        $gradebookTable === 'grade_changes'
        && \array_key_exists('updated_at', $db->getTableColumns($gradebookPrefixed))
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

$catalogueCourse = $kernel->createCourse->execute(
    $actor,
    'VidyaLMS smoke catalogue course',
    CATALOGUE_COURSE_SLUG,
    'A course created by build/smoke.php so the catalogue contract has something to release.',
    '<p>Seeded by the VidyaLMS Joomla adapter smoke test.</p>',
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
$catalogueAccounts = createSeatContractAccounts($container);

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
// section's own course and its four accounts, which go the way 10r's do.
foreach ($catalogueAccounts as $catalogueUserId) {
    $catalogueUser = $container->get(UserFactoryInterface::class)->loadUserById($catalogueUserId);
    smokeDelete($ports->database, 'notification_queue', ['recipient_user_id' => $catalogueUserId]);
    smokeDelete($ports->database, 'activity_log', ['user_id' => $catalogueUserId]);
    smokeDelete($ports->database, 'email_log', ['user_id' => $catalogueUserId]);
    $catalogueUser->delete();
}

step('catalogue course rows removed', (string) removeSmokeCourse($database, $catalogueCourseId) . ' row(s)');
step('catalogue contract accounts removed', \count($catalogueAccounts) . ' account(s)');


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

heading('11. Schema seal');

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
    out('    php build/smoke.php --joomla=' . $joomlaRoot . ' --cleanup');
}

exit($failures === 0 ? 0 : 1);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Boots com_vidyalms and returns the shared kernel, which is exactly what a
 * plugin or a scheduled task does.
 */
function bootKernel(): CoreKernel
{
    return \VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory::kernel();
}

function superUserId(DatabaseInterface $db): int
{
    $query = $db->createQuery()
        ->select($db->quoteName('u.id'))
        ->from($db->quoteName('#__users', 'u'))
        ->join(
            'INNER',
            $db->quoteName('#__user_usergroup_map', 'm'),
            $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id')
        )
        ->where($db->quoteName('m.group_id') . ' = 8')
        ->where($db->quoteName('u.block') . ' = 0')
        ->order($db->quoteName('u.id') . ' ASC')
        ->setLimit(1);

    return (int) $db->setQuery($query)->loadResult();
}

/**
 * Creates a throwaway user group holding core.manage on com_vidyalms and
 * nothing else, plus a user in it, so the administration implication is tested
 * against Joomla's real ACL rather than a mock.
 *
 * @return array{0: int, 1: int} group id, user id
 */
function createComponentManager(\Joomla\DI\Container $container, DatabaseInterface $db): array
{
    return createAclGroup(
        $container,
        $db,
        'VidyaLMS smoke managers',
        'VidyaLMS Smoke Manager',
        'vidyalms_smoke_manager',
        ['core.manage'],
    );
}

/**
 * Creates a throwaway user group holding exactly the named actions on
 * com_vidyalms, plus a user in it.
 *
 * @param list<string> $actions
 *
 * @return array{0: int, 1: int} group id, user id
 */
function createAclGroup(
    \Joomla\DI\Container $container,
    DatabaseInterface $db,
    string $groupTitle,
    string $name,
    string $username,
    array $actions,
): array {
    $group = new \Joomla\CMS\Table\Usergroup($db);
    $group->bind(['title' => $groupTitle, 'parent_id' => 1]);

    if (!$group->check() || !$group->store()) {
        fwrite(STDERR, 'Could not create the group ' . $groupTitle . ': ' . $group->getError() . "\n");
        exit(1);
    }

    $groupId = (int) $group->id;

    // Merge into the component asset rules rather than replacing them, so an
    // existing configuration on the test site survives the run.
    $asset = new \Joomla\CMS\Table\Asset($db);
    $asset->loadByName('com_vidyalms');

    $rules = json_decode((string) $asset->rules, true);
    $rules = \is_array($rules) ? $rules : [];

    foreach ($actions as $action) {
        $rules[$action][$groupId] = 1;
    }

    $asset->rules = json_encode($rules, JSON_THROW_ON_ERROR);

    if (!$asset->store()) {
        fwrite(STDERR, 'Could not grant the actions to ' . $groupTitle . ': ' . $asset->getError() . "\n");
        exit(1);
    }

    // Access caches the group tree and every asset's rules in statics on first
    // use. A group and a rule created inside this process are invisible until
    // those are dropped, which would make the checks below pass for the wrong
    // reason.
    \Joomla\CMS\Access\Access::clearStatics();

    /** @var User $user */
    $user = $container->get(UserFactoryInterface::class)->loadUserById(0);
    $user->set('name', $name);
    $user->set('username', $username);
    $user->set('email', str_replace('_', '.', $username) . '@example.invalid');
    $user->set('password_clear', bin2hex(random_bytes(16)));
    $user->set('groups', [$groupId]);

    if (!$user->save()) {
        fwrite(STDERR, 'Could not create ' . $username . ': ' . $user->getError() . "\n");
        exit(1);
    }

    return [$groupId, (int) $user->id];
}

/**
 * Removes the throwaway manager, its group and the rule that granted it,
 * leaving the component asset exactly as it was found.
 */
function removeComponentManager(
    \Joomla\DI\Container $container,
    DatabaseInterface $db,
    int $groupId,
    int $userId,
): void {
    removeAclGroup($container, $db, $groupId, $userId, ['core.manage']);
}

/**
 * Removes a throwaway user, its group and the rules that granted it, leaving
 * the component asset exactly as it was found.
 *
 * @param list<string> $actions
 */
function removeAclGroup(
    \Joomla\DI\Container $container,
    DatabaseInterface $db,
    int $groupId,
    int $userId,
    array $actions,
): void {
    $user = $container->get(UserFactoryInterface::class)->loadUserById($userId);

    if ((int) $user->id === $userId) {
        $user->delete();
    }

    $asset = new \Joomla\CMS\Table\Asset($db);

    if ($asset->loadByName('com_vidyalms')) {
        $rules   = json_decode((string) $asset->rules, true);
        $changed = false;

        foreach ($actions as $action) {
            if (\is_array($rules) && isset($rules[$action][$groupId])) {
                unset($rules[$action][$groupId]);

                if ($rules[$action] === []) {
                    unset($rules[$action]);
                }

                $changed = true;
            }
        }

        if ($changed) {
            $asset->rules = json_encode($rules, JSON_THROW_ON_ERROR);
            $asset->store();
        }
    }

    $group = new \Joomla\CMS\Table\Usergroup($db);

    if ($group->load($groupId)) {
        $group->delete($groupId);
    }
}

/**
 * Creates the throwaway learner the run enrols. The account is registered
 * (group 2) and blocked from logging in is deliberately not set: the site
 * pages are fetched anonymously, so no password is ever used.
 */
function createLearner(\Joomla\DI\Container $container): int
{
    /** @var User $user */
    $user = $container->get(UserFactoryInterface::class)->loadUserById(0);

    $user->set('name', 'VidyaLMS Smoke Learner');
    $user->set('username', LEARNER_USERNAME);
    $user->set('email', LEARNER_EMAIL);
    $user->set('password_clear', bin2hex(random_bytes(16)));
    $user->set('groups', [2]);

    if (!$user->save()) {
        fwrite(STDERR, 'Could not create the smoke learner: ' . $user->getError() . "\n");
        exit(1);
    }

    return (int) $user->id;
}

/**
 * The four CMS accounts the seat contract needs.
 *
 * Two administrators in two different organisations and two learners: the
 * alert half of the contract exists to prove that an exhausting pool reaches
 * the first organisation's administrator and not the second's, and that is
 * only a proof if the second one is a real account that really could have
 * been reached.
 *
 * Plain registered users with no group but the site's default: an
 * organisation role is granted inside the organisation, not by the CMS, and
 * giving these accounts a Joomla capability would make the contract prove
 * something weaker than it claims.
 *
 * @return array{admin_a: int, admin_b: int, learner_one: int, learner_two: int}
 */
function createSeatContractAccounts(\Joomla\DI\Container $container): array
{
    $accounts = [];

    /** @var DatabaseInterface $db */
    $db = $container->get(DatabaseInterface::class);

    foreach (['admin_a', 'admin_b', 'learner_one', 'learner_two'] as $role) {
        $username = SEAT_ACCOUNT_PREFIX . $role;

        // An account of this run's own username is adopted rather than refused,
        // the way the learner account already is. A run that died inside this
        // section leaves its four accounts behind, and refusing to start again
        // until somebody deletes them by hand makes the harness unrunnable
        // exactly when it most needs to be run.
        $existing = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('username') . ' = :username')
            ->bind(':username', $username, ParameterType::STRING);
        $found = (int) $db->setQuery($existing)->loadResult();

        if ($found > 0) {
            $accounts[$role] = $found;

            continue;
        }

        /** @var User $user */
        $user = $container->get(UserFactoryInterface::class)->loadUserById(0);

        $user->set('name', 'VidyaLMS Smoke Seat ' . $role);
        $user->set('username', $username);
        $user->set('email', str_replace('_', '.', SEAT_ACCOUNT_PREFIX . $role) . '@example.invalid');
        $user->set('password_clear', bin2hex(random_bytes(16)));
        $user->set('groups', [2]);

        if (!$user->save()) {
            fwrite(STDERR, 'Could not create the seat contract account ' . $role . ': ' . $user->getError() . "\n");
            exit(1);
        }

        $accounts[$role] = (int) $user->id;
    }

    return $accounts;
}

/**
 * The three host accounts OrganisationIdentityContract's OneRoster package
 * names, by the exact addresses the package carries.
 *
 * The contract cannot make them: there is no account-creating port, by design,
 * and there is not going to be one. Without them RosterImporter skips every
 * person row as a referential miss and the tombstone and the untouched
 * membership are both read off rows that were never written.
 *
 * @return array<string, int>
 */
function createRosterContractAccounts(\Joomla\DI\Container $container): array
{
    $marker = \Shondalai\VidyaLMS\Tooling\Contract\OrganisationIdentityContract::MARKER;
    $accounts = [];

    /** @var DatabaseInterface $db */
    $db = $container->get(DatabaseInterface::class);

    foreach (['ada' => 'Ada Byron', 'grace' => 'Grace Hopper', 'alan' => 'Alan Turing'] as $given => $name) {
        $email = $given . '-' . $marker . '@example.test';
        $username = str_replace('-', '_', $given . '_' . $marker);

        $existing = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('email') . ' = :email')
            ->bind(':email', $email, ParameterType::STRING);
        $found = (int) $db->setQuery($existing)->loadResult();

        if ($found > 0) {
            $accounts[$given] = $found;

            continue;
        }

        /** @var User $user */
        $user = $container->get(UserFactoryInterface::class)->loadUserById(0);
        $user->set('name', $name);
        $user->set('username', $username);
        $user->set('email', $email);
        $user->set('password_clear', bin2hex(random_bytes(16)));
        $user->set('groups', [2]);

        if (!$user->save()) {
            fwrite(STDERR, 'Could not create the roster contract account ' . $given . ': ' . $user->getError() . "\n");
            exit(1);
        }

        $accounts[$given] = (int) $user->id;
    }

    return $accounts;
}

/**
 * The ids of the roster contract accounts still on this site, if any.
 *
 * @return list<int>
 */
function rosterContractAccountIds(DatabaseInterface $db): array
{
    $marker = \Shondalai\VidyaLMS\Tooling\Contract\OrganisationIdentityContract::MARKER;
    $ids = [];

    foreach (['ada', 'grace', 'alan'] as $given) {
        $email = $given . '-' . $marker . '@example.test';
        $query = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('email') . ' = :email')
            ->bind(':email', $email, ParameterType::STRING);
        $found = (int) $db->setQuery($query)->loadResult();

        if ($found > 0) {
            $ids[] = $found;
        }
    }

    return $ids;
}

/**
 * The ids of the seat contract accounts still on this site, if any.
 *
 * By the usernames the run mints rather than by a pattern, so nothing a
 * site owner created can be caught by the cleanup.
 *
 * @return list<int>
 */
function seatContractAccountIds(DatabaseInterface $db): array
{
    $ids = [];

    foreach (['admin_a', 'admin_b', 'learner_one', 'learner_two'] as $role) {
        $username = SEAT_ACCOUNT_PREFIX . $role;
        $query = $db->createQuery()
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('username') . ' = :username')
            ->bind(':username', $username, ParameterType::STRING);
        $found = (int) $db->setQuery($query)->loadResult();

        if ($found > 0) {
            $ids[] = $found;
        }
    }

    return $ids;
}
