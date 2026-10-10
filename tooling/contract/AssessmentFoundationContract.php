<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Application\Assessment\ServedQuestion;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseAttemptRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

/**
 * What the free schema has to be true about, on a real site, after a migration.
 *
 * The drift check (tooling/schema/verify.php) proves the shape: that a
 * migrated site holds the columns a fresh install would create. This proves
 * the half a shape check cannot reach, and it is the half that matters to a
 * learner:
 *
 *   - the four new tables answer a query compiled by this host's database
 *     port, not merely exist
 *   - every column either migration added is addressable in a predicate, so a
 *     column that landed under a different name fails here rather than on the
 *     first write. The two versions are counted separately, because a site
 *     that upgraded through 1.1.0 and stalled before 1.3.0 is a different
 *     problem from one whose 1.1.0 never ran
 *   - every attempt that predates the migration still reads, through the
 *     manifest that attempts written afterwards will read through: an attempt
 *     with no attempt_questions rows is the whole point of the fallback, and
 *     it can only be tested where such attempts exist
 *   - every scoring policy key on a question this build's registry owns
 *     resolves in that same registry, which is the rule that keeps a pin from
 *     naming a provider only Pro installs. A question of a type this build
 *     does not register is another package's, and is counted rather than
 *     failed: a Pro-typed row on a free site resolving neither its type nor
 *     its rule is ADR 0002 working, not a defect
 *   - every scheduled task type the core can queue has a handler, because a
 *     type with no handler is a queue row that fails silently for ever
 *
 * Read only, on purpose: it runs inside both adapters' smoke harnesses, under
 * their schema seals, and must leave the site exactly as it found it. It
 * writes nothing and creates nothing, so it can be pointed at any site.
 */
final class AssessmentFoundationContract
{
    /**
     * The tables the assessment foundation owns.
     */
    private const NEW_TABLES = [
        'attempt_questions',
        'question_definitions',
        'attempt_answer_events',
        'stimuli',
    ];

    /**
     * Every column the assessment foundation owns, by table. A column is proved by putting
     * it in a predicate: the host compiles the name into SQL, so a column that
     * never arrived is an error here and not a silent null later.
     *
     * @var array<string, list<string>>
     */
    private const NEW_COLUMNS_1_1_0 = [
        'questions' => [
            'definition_version',
            'response_shape',
            'scored',
            'scoring_policy',
            'scoring_params',
            'external_scored',
            'stimulus_id',
        ],
        'attempts' => [
            'presentation_seed',
            'policy_snapshot',
            'behaviour',
            'expires_at',
        ],
        'attempt_answers' => [
            'ui_state',
            'raw_input',
            'presentation_form',
            'certainty',
        ],
        'attempt_grades' => [
            'part_id',
            'raw_score',
            'min_score',
            'policy_key',
            'scoring_params',
            'session_status',
            'score_status',
            'state',
            'answered_status',
            'feedback_ids',
            'version',
        ],
        'quizzes' => [
            'shuffle_questions',
            'shuffle_options',
            'one_per_page',
        ],
    ];

    /**
     * Every column the quiz policy set owns, on the same terms.
     *
     * The quiz policy set is the bulk of it, and every one of these is a real
     * column because the attempt pipeline reads them on every start and every
     * submit, with any provider absent. The three joins on the other two
     * tables point at Pro storage and are deliberately free: the free build
     * never writes them, and a paid release cannot retroactively populate a
     * column that was never there.
     *
     * Kept as a second list rather than merged into the first, because a
     * failure has to name the version whose migration did not land: a site
     * that upgraded through 1.1.0 and stalled before 1.3.0 is a different
     * problem from one whose 1.1.0 never ran.
     *
     * @var array<string, list<string>>
     */
    private const NEW_COLUMNS_1_3_0 = [
        'quizzes' => [
            'behaviour',
            'navigation_mode',
            'submission_mode',
            'opens_at',
            'closes_at',
            'expiry_policy',
            'grace_seconds',
            'auto_submit',
            'cooldown_after_first_seconds',
            'cooldown_after_later_seconds',
            'attempts_counted',
            'attempt_aggregation',
            'attempt_aggregation_params',
            'negative_marking_policy',
            'allow_quiz_negative',
            'try_penalty',
            'item_max_attempts',
            'item_show_feedback',
            'item_allow_review',
            'item_show_solution',
            'item_allow_comment',
            'item_allow_skipping',
            'item_validate_responses',
            'review_visibility',
        ],
        'questions' => [
            'bank_entry_id',
            'rubric_version_id',
        ],
        'attempt_grades' => [
            'rubric_version_id',
        ],
    ];

    /**
     * The task types the core queues through the scheduler port. Each is a key
     * in the scheduled task registry, and each host's runner resolves a queue
     * row's job_type there and nowhere else.
     */
    private const CORE_TASK_TYPES = [
        'vidyalms.entitlements.expire',
        'vidyalms.certificate.render',
        'vidyalms.report.generate',
        'vidyalms.import.run',
        'vidyalms.progress.recompute',
        'vidyalms.course.recompute_progress',
    ];

    /**
     * Who marks an item. The migration writes one of these onto every row.
     */
    private const SCORING_MARKERS = ['machine', 'human'];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $facts = [];

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
    ) {
    }

    /**
     * Runs every check. The first element is what broke, the second is what a
     * harness prints when nothing did.
     *
     * @return array{failures: list<string>, facts: list<string>}
     */
    public static function run(CoreKernel $kernel, Ports $ports): array
    {
        $contract = new self($kernel, $ports);

        try {
            $contract->checkTables();
            $contract->checkColumns();
            $contract->checkPins();
            $contract->checkLegacyAttempts();
            $contract->checkScheduledTasks();
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        }

        return ['failures' => $contract->failures, 'facts' => $contract->facts];
    }

    private function checkTables(): void
    {
        $counts = [];

        foreach (self::NEW_TABLES as $table) {
            try {
                $counts[] = $table . '=' . $this->ports->database->count(Query::from($table));
            } catch (\Throwable $error) {
                $this->failures[] = 'the ' . $table . ' table does not answer a query: ' . $error->getMessage();
            }
        }

        $this->facts[] = 'new tables: ' . implode(', ', $counts);
    }

    private function checkColumns(): void
    {
        $counted = [];

        foreach (['1.1.0' => self::NEW_COLUMNS_1_1_0, '1.3.0' => self::NEW_COLUMNS_1_3_0] as $version => $tables) {
            $checked = 0;

            foreach ($tables as $table => $columns) {
                foreach ($columns as $column) {
                    try {
                        $this->ports->database->count(Query::from($table)->whereNotNull($column));
                        $checked++;
                    } catch (\Throwable $error) {
                        $this->failures[] = $table . '.' . $column . ' (schema ' . $version . ')'
                            . ' is not queryable: ' . $error->getMessage();
                    }
                }
            }

            $counted[] = $checked . ' from ' . $version;
        }

        $this->facts[] = 'new columns answering a predicate: ' . implode(', ', $counted);
    }

    /**
     * The migration writes a marking rule onto every existing question, and
     * every rule it writes has to be one the free build can actually grade
     * with. A key only Pro registers would leave a free site resolving nothing
     * and an existing question unmarkable.
     *
     * "Can actually grade with" is scoped to the questions this build is
     * responsible for, which is what the type key says. A question whose type
     * this build does not register belongs to a package that is not installed
     * here, and its marking rule is that package's: the core resolves neither,
     * serves the placeholder, and records the item notscored rather than
     * wrong. That is ADR 0002's designed state for a Pro-typed row on a free
     * site, so counting it as a failure would make the product fail its own
     * contract for behaving correctly. Such a row is reported as a fact and
     * still has to carry a policy, a shape and a marker like every other.
     */
    private function checkPins(): void
    {
        $questions = $this->ports->database->selectAll(
            Query::from('questions')->select(['id', 'type', 'scoring_policy', 'response_shape', 'external_scored']),
        );

        if ($questions === []) {
            $this->facts[] = 'no questions on this site, so no pins to check';

            return;
        }

        $policies = [];
        $markers = [];
        $contributed = 0;

        foreach ($questions as $question) {
            $id = (string) ($question['id'] ?? '?');
            $type = $question['type'] ?? null;
            $policy = $question['scoring_policy'] ?? null;
            $shape = $question['response_shape'] ?? null;
            $marker = $question['external_scored'] ?? null;

            $ownedHere = \is_string($type) && $this->kernel->registries->questionTypes->has($type);

            if (!\is_string($policy) || $policy === '') {
                $this->failures[] = 'question ' . $id . ' has no scoring_policy; the migration did not pin it';
            } elseif ($this->kernel->registries->scoringPolicies->has($policy)) {
                $policies[$policy] = ($policies[$policy] ?? 0) + 1;
            } elseif ($ownedHere) {
                $this->failures[] = 'question ' . $id . ' is a "' . $type . '", which this build registers, but'
                    . ' names scoring policy "' . $policy . '", which it does not';
            } else {
                $contributed++;
            }

            if (!\is_string($shape) || $shape === '') {
                $this->failures[] = 'question ' . $id . ' has no response_shape, so the fallback renderer'
                    . ' and the exporter have nothing to key on';
            }

            if (!\is_string($marker) || !\in_array($marker, self::SCORING_MARKERS, true)) {
                $this->failures[] = 'question ' . $id . ' has external_scored ' . var_export($marker, true)
                    . ', which is not one of ' . implode(', ', self::SCORING_MARKERS);
            } else {
                $markers[$marker] = ($markers[$marker] ?? 0) + 1;
            }
        }

        $described = [];

        foreach ($policies as $policy => $count) {
            $described[] = $policy . ' x' . $count;
        }

        foreach ($markers as $marker => $count) {
            $described[] = $marker . ' x' . $count;
        }

        if ($contributed > 0) {
            $described[] = $contributed . ' of a type and a rule this build does not register';
        }

        $this->facts[] = \count($questions) . ' questions pinned: ' . implode(', ', $described);
    }

    /**
     * Every attempt already on the site, read the way the code that grades and
     * reviews reads it.
     *
     * An attempt started before 1.1.0 has no attempt_questions rows, and the
     * manifest answers it from the live published rows instead. That path is
     * the migration's whole promise to a site with history, and no fresh
     * install can exercise it: it needs an attempt that predates the manifest,
     * which is what a development site that has been running for a while has.
     */
    private function checkLegacyAttempts(): void
    {
        $rows = $this->ports->database->selectAll(Query::from('attempts')->select(['id']));

        if ($rows === []) {
            $this->facts[] = 'no attempts on this site, so nothing predates the manifest here';

            return;
        }

        $attempts = new DatabaseAttemptRepository($this->ports->database, $this->ports->clock);
        $read = 0;
        $legacy = 0;
        $frozen = 0;

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $attempt = $attempts->byId($id);

            if (!$attempt instanceof Attempt) {
                $this->failures[] = 'attempt ' . $id . ' no longer loads through the attempt repository';

                continue;
            }

            $read++;

            try {
                $served = $this->kernel->attemptManifest->forAttempt($attempt);
            } catch (\Throwable $error) {
                $this->failures[] = 'attempt ' . $id . ' does not read through the manifest: ' . $error->getMessage();

                continue;
            }

            $wasFrozen = $served !== [] && $served[0]->frozen;
            $wasFrozen ? $frozen++ : $legacy++;

            if (!$wasFrozen) {
                $published = $this->ports->database->count(
                    Query::from('questions')
                        ->where('quiz_id', '=', $attempt->quizId)
                        ->where('state', '=', 'published'),
                );

                if (\count($served) !== $published) {
                    $this->failures[] = 'attempt ' . $id . ' reads ' . \count($served)
                        . ' served question(s) where its quiz publishes ' . $published;
                }
            }

            foreach ($served as $question) {
                if (!$question instanceof ServedQuestion || $question->typeKey === '') {
                    $this->failures[] = 'attempt ' . $id . ' served a question with no type';
                }
            }

            try {
                $attempts->answersFor($id);
                $attempts->submissionsFor($id);
                $this->kernel->manualGradingStatus->pendingCountFor($attempt);
            } catch (\Throwable $error) {
                $this->failures[] = 'attempt ' . $id . ' no longer reads its answers or its pending count: '
                    . $error->getMessage();
            }

            $grades = $this->ports->database->count(Query::from('attempt_grades')->where('attempt_id', '=', $id));

            if ($attempt->status === 'graded' && $grades === 0) {
                $this->failures[] = 'attempt ' . $id . ' is graded and has no grade rows left';
            }
        }

        $this->facts[] = $read . ' attempt(s) read: ' . $frozen . ' from a frozen manifest, '
            . $legacy . ' from the pre-manifest fallback';
    }

    /**
     * A queued row whose job_type resolves to nothing is the silent failure
     * this check exists for: the runner records it as an unknown type, the
     * work never happens, and the only evidence is a failed row nobody reads.
     */
    private function checkScheduledTasks(): void
    {
        $registry = $this->kernel->registries->scheduledTasks;

        foreach (self::CORE_TASK_TYPES as $type) {
            if (!$registry->has($type)) {
                $this->failures[] = 'no handler is registered for the queued task type "' . $type . '"';
            }
        }

        $unknown = $this->kernel->runScheduledTask('vidyalms.contract.no_such_type', 0, [], Principal::guest());

        if ($unknown !== null) {
            $this->failures[] = 'an unregistered task type answered instead of reporting a miss';
        }

        $this->facts[] = \count($registry->keys()) . ' task type(s) registered: ' . implode(', ', $registry->keys());
    }
}
