<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseAttemptRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

/**
 * What the Pro schema has to be true about, on a real site, through the host's
 * own database port.
 *
 * The drift check (tooling/schema/verify.php --pro) proves the shape: that a
 * site the Pro package upgraded holds what a fresh Pro install would create.
 * That is a comparison of two INFORMATION_SCHEMA readings, and it never asks
 * the adapter a question. This asks the adapter, over the three shapes wave 3b
 * introduced and in the order an author meets them:
 *
 *   - **a banked quiz with a pool slot.** A category, a bank entry, a version
 *     pinned to the immutable question row, and a slot tree with a section, a
 *     question slot resolving the latest version and a pool slot carrying a
 *     serialised predicate tree. It proves the tree reassembles through this
 *     host's port, that "where is this used" is answered by an index rather
 *     than by scanning stored specs, that required and fixed stay two
 *     independent columns, and that a nested and/or/not tree survives the
 *     host's JSON column with its structure intact.
 *   - **a rubric-graded submission.** A rubric, a version, criteria and
 *     levels, and a rater's grade row carrying one criterion-level row per
 *     criterion, appended beside a machine grade this site actually has. It
 *     proves the level id is stored beside the points, which is what makes
 *     analytic feedback and rater agreement possible at all, that the grade
 *     names the rubric version it ran under, and that a second rubric version
 *     leaves the historic grade pointing at the first.
 *   - **a regrade.** A further grade row for the same attempt, question and
 *     part. It proves the append-only invariant holds with the new columns on
 *     the row: the row it started from is untouched, the frozen manifest is
 *     untouched, and the attempt still reads afterwards.
 *
 * Nothing here updates a row it did not create, which is what lets the
 * survivor check below be a list of deletes with nothing to restore.
 *
 * Every row it writes goes in inside a transaction it deliberately rolls back,
 * exactly as DatabasePortContract does, so a run against a live site leaves
 * nothing behind. It creates no table and drops none, which is the rule in
 * 10-coding-standards.md, and it runs inside both Pro smoke harnesses under
 * their schema seals.
 *
 * It writes rows rather than reading them because the question it answers
 * cannot be answered by reading: on a site where nobody has authored a bank
 * yet, every one of these tables is empty, and an empty table proves only that
 * it exists.
 */
final class ProSchemaContract
{
    /**
     * Marks every row this contract writes, so a failed rollback is visible
     * and removable. MARKED_BY says which column carries it in each table;
     * every other row hangs off one that does.
     */
    public const MARKER = '__vidyalms_pro_schema_contract__';

    /**
     * The Pro schema, which is the list of tables the Pro package installs.
     *
     * Read from the schema rather than copied out of it, because a table can
     * move between the two editions and one of them already has: a copy here
     * would go on naming a Pro table the free package now installs, and would
     * pass while saying something untrue. What is being proved is that this
     * adapter can address every table the Pro package ships, and the shipped
     * list is the only honest source for that.
     */
    private const PRO_SCHEMA = '/packages/pro-core/schema/tables.php';

    /**
     * The pool query a pool slot stores: a predicate tree, not two columns.
     * Nested on purpose, because the thing worth proving about a host's JSON
     * column is that a tree comes back a tree.
     *
     * @var array<string, mixed>
     */
    private const POOL_QUERY = [
        'strategy' => 'stratified_by_difficulty',
        'params' => ['bands' => ['easy' => 1, 'hard' => 1]],
        'query' => [
            'kind' => 'and',
            'children' => [
                ['kind' => 'category', 'category_id' => 0, 'include_subcategories' => true],
                [
                    'kind' => 'or',
                    'children' => [
                        ['kind' => 'tag', 'tag' => self::MARKER],
                        ['kind' => 'difficulty', 'band' => 'hard'],
                    ],
                ],
                ['kind' => 'not', 'children' => [['kind' => 'status', 'status' => 'draft']]],
            ],
        ],
    ];

    /**
     * Where the marker lands, per table. It is also the cleanup list for the
     * case where a host's transaction support quietly did nothing: every row
     * this contract writes carries the marker itself or hangs off a row that
     * does, and these seven are the ones that carry it.
     *
     * @var array<string, string>
     */
    private const MARKED_BY = [
        'question_banks' => 'name',
        'question_categories' => 'name',
        'rubrics' => 'name',
        'quiz_slots' => 'title',
        'learning_objectives' => 'identifier',
        'attempt_grades' => 'feedback',
        'attempt_grade_criteria' => 'comment',
    ];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $facts = [];

    /**
     * The newest grade row on this quiz as it stood before anything was
     * written, or null on a site whose seeded quiz has never been submitted.
     *
     * Resolved once, so the rubric section and the regrade section are both
     * talking about the same pre-existing row: what each of them proves is
     * that appending beside it leaves it exactly as it was.
     *
     * @var array<string, mixed>|null
     */
    private ?array $baseGrade = null;

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
        private readonly int $quizId,
        private readonly int $questionId,
    ) {
    }

    /**
     * Runs every check against one seeded quiz and one of its questions, which
     * is what gives the rubric and the regrade a real grade row to hang off.
     *
     * @return array{failures: list<string>, facts: list<string>}
     */
    public static function run(CoreKernel $kernel, Ports $ports, int $quizId, int $questionId): array
    {
        $contract = new self($kernel, $ports, $quizId, $questionId);

        try {
            $ports->database->transactional(static function () use ($contract): void {
                $contract->readBaseGrade();
                $contract->checkTables();
                $contract->checkBankedQuizWithPoolSlot();
                $contract->checkRubricGradedSubmission();
                $contract->checkRegrade();

                throw new RollbackSignal();
            });
        } catch (RollbackSignal) {
            // The intended exit: the work is done and the transaction is gone.
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        }

        $contract->assertNothingSurvived();

        return ['failures' => $contract->failures, 'facts' => $contract->facts];
    }

    /**
     * Checked rather than assumed: a host whose transaction support quietly
     * did nothing would otherwise leave rows on a live site and report a clean
     * run.
     *
     * It matters most for the two free tables in the list. Everything else
     * this contract writes is in a table only the paid package reads, so a
     * leftover is untidy; a leftover grade row is a mark a learner did not
     * earn, and a leftover criterion row hangs off it.
     */
    private function assertNothingSurvived(): void
    {
        $left = [];

        foreach (self::MARKED_BY as $table => $column) {
            $count = $this->countQuietly(Query::from($table)->where($column, '=', self::MARKER));

            if ($count > 0) {
                $left[] = $count . ' ' . $table;
            }
        }

        if ($left === []) {
            return;
        }

        $this->failures[] = 'the contract run left ' . implode(', ', $left)
            . ' row(s) behind: transactional() did not roll back';

        // The transaction guarantee is already known to be absent, so the rows
        // are removed rather than left on a live site. Only the marked ones:
        // this run wrote nothing else, and it updated no row that was already
        // there, so there is nothing to restore.
        foreach (self::MARKED_BY as $table => $column) {
            try {
                $this->ports->database->delete($table, [$column => self::MARKER]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'and the cleanup of ' . $table . ' failed too: ' . $failure->getMessage();
            }
        }
    }

    /**
     * The logical names in packages/pro-core/schema/tables.php.
     *
     * @return list<string>
     */
    private function proTables(): array
    {
        $file = \dirname(__DIR__, 2) . self::PRO_SCHEMA;

        /** @var mixed $schema */
        $schema = is_readable($file) ? require $file : null;

        if (!\is_array($schema) || $schema === []) {
            $this->failures[] = 'the Pro schema is unreadable or empty at ' . $file
                . ', so there is no list of tables to check';

            return [];
        }

        $names = [];

        foreach (array_keys($schema) as $name) {
            if (\is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function checkTables(): void
    {
        $tables = $this->proTables();
        $answered = 0;

        foreach ($tables as $table) {
            try {
                $this->ports->database->count(Query::from($table));
                $answered++;
            } catch (\Throwable $error) {
                $this->failures[] = 'the ' . $table . ' table does not answer a query compiled by this host: '
                    . $error->getMessage();
            }
        }

        $this->facts[] = $answered . ' of ' . \count($tables) . ' Pro tables answer a query';
    }

    /**
     * A bank entry placed in a quiz twice: once as a pinned question slot and
     * once through a pool that could draw it.
     */
    private function checkBankedQuizWithPoolSlot(): void
    {
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');
        $database = $this->ports->database;

        $bankId = $database->insert('question_banks', [
            'owner_scope' => 'quiz',
            'owner_id' => $this->quizId,
            'name' => self::MARKER,
            'description' => 'Cross-host schema contract bank',
            'state' => 'active',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $categoryId = $database->insert('question_categories', [
            'bank_id' => $bankId,
            'owner_scope' => 'quiz',
            'owner_id' => $this->quizId,
            'parent_id' => null,
            'name' => self::MARKER,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $entryId = $database->insert('question_bank_entries', [
            'bank_id' => $bankId,
            'owner_scope' => 'quiz',
            'owner_id' => $this->quizId,
            'category_id' => $categoryId,
            'status' => 'ready',
            'difficulty_hint' => 'hard',
            'estimated_time_seconds' => 90,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionId = $database->insert('question_versions', [
            'bank_entry_id' => $entryId,
            'version' => 1,
            'question_id' => $this->questionId,
            'status' => 'ready',
            'interaction_type' => 'textEntryInteraction',
            'composite' => 0,
            'time_dependent' => 0,
            'feedback_type' => 'none',
            'solution_available' => 0,
            'scoring_mode' => 'responseprocessing',
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $database->update('question_bank_entries', ['current_version_id' => $versionId], ['id' => $entryId]);

        $database->insert('question_tags', [
            'bank_entry_id' => $entryId,
            'tag' => self::MARKER,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $objectiveId = $database->insert('learning_objectives', [
            'framework_uri' => 'https://example.org/case/v1p1',
            'identifier' => self::MARKER,
            'label' => 'Power in a resistive circuit',
            'parent_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $database->insert('question_objectives', [
            'bank_entry_id' => $entryId,
            'objective_id' => $objectiveId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sectionId = $database->insert('quiz_slots', [
            'quiz_id' => $this->quizId,
            'parent_id' => null,
            'position' => 1,
            'kind' => 'section',
            'title' => self::MARKER,
            'bank_entry_id' => null,
            'question_version_id' => null,
            'ref' => null,
            'points' => 0,
            'weight' => 1,
            'required' => 1,
            'fixed' => 1,
            'visible' => 1,
            'keep_together' => 1,
            'selection' => null,
            'ordering' => $this->json(['shuffle' => true]),
            'policy_key' => null,
            'policy_params' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // A null question_version_id is "resolve the latest at attempt start",
        // which is a different placement from a pin and has to survive as null
        // rather than as zero.
        $questionSlotId = $database->insert('quiz_slots', [
            'quiz_id' => $this->quizId,
            'parent_id' => $sectionId,
            'position' => 1,
            'kind' => 'question',
            'title' => null,
            'bank_entry_id' => $entryId,
            'question_version_id' => null,
            'ref' => null,
            'points' => 2,
            'weight' => 1,
            'required' => 1,
            'fixed' => 0,
            'visible' => 1,
            'keep_together' => 1,
            'selection' => null,
            'ordering' => null,
            'policy_key' => 'numeric_tolerance',
            'policy_params' => $this->json(['tolerance' => 0.01]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $poolSlotId = $database->insert('quiz_slots', [
            'quiz_id' => $this->quizId,
            'parent_id' => $sectionId,
            'position' => 2,
            'kind' => 'pool',
            'title' => null,
            'bank_entry_id' => null,
            'question_version_id' => null,
            'ref' => $this->json(self::POOL_QUERY),
            'points' => 1,
            'weight' => 1,
            'required' => 0,
            'fixed' => 0,
            'visible' => 1,
            'keep_together' => 1,
            'selection' => $this->json(['select' => 2, 'with_replacement' => false]),
            'ordering' => null,
            'policy_key' => null,
            'policy_params' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The tree reassembles: the section is a root of this quiz and the two
        // leaves name it as their parent, in position order.
        $children = $database->selectAll(
            Query::from('quiz_slots')
                ->select(['id', 'kind', 'position', 'required', 'fixed', 'points'])
                ->where('parent_id', '=', $sectionId)
                ->orderBy('position'),
        );

        $kinds = array_map(static fn (array $row): string => (string) $row['kind'], $children);

        $this->expect(
            $kinds === ['question', 'pool'],
            'the slot tree reassembles in position order',
            'section ' . $sectionId . ' has ' . implode(', ', $kinds),
        );

        // A root of this quiz rather than the only one: the assertion is about
        // the tree shape, and a site whose author has already built a slot
        // tree of their own is not a defect.
        $isRoot = $database->count(
            Query::from('quiz_slots')
                ->where('id', '=', $sectionId)
                ->where('quiz_id', '=', $this->quizId)
                ->whereNull('parent_id'),
        );

        $this->expect(
            $isRoot === 1,
            'the section is a root of the quiz',
            'slot ' . $sectionId . ' has a null parent on quiz ' . $this->quizId,
        );

        // "Where is this used" is the query the bank_entry_id index exists for,
        // and answering it from a stored spec instead is the mistake the
        // column was made real to avoid.
        $usage = $database->count(Query::from('quiz_slots')->where('bank_entry_id', '=', $entryId));

        $this->expect(
            $usage === 1,
            'the bank entry answers "where is this used" through a column',
            'entry ' . $entryId . ' is placed in ' . $usage . ' slot(s)',
        );

        // required governs selection and fixed governs ordering. They are
        // orthogonal, and a host that stored them as one flag would answer the
        // same for both here.
        $questionSlot = $database->selectOne(Query::from('quiz_slots')->where('id', '=', $questionSlotId));

        $this->expect(
            (int) ($questionSlot['required'] ?? -1) === 1 && (int) ($questionSlot['fixed'] ?? -1) === 0,
            'required and fixed are two independent columns',
            'required=' . var_export($questionSlot['required'] ?? null, true)
            . ', fixed=' . var_export($questionSlot['fixed'] ?? null, true),
        );

        $unpinned = $database->count(
            Query::from('quiz_slots')->where('id', '=', $questionSlotId)->whereNull('question_version_id'),
        );

        $this->expect(
            $unpinned === 1,
            'a slot with no pinned version reads back as null',
            'null is "resolve the latest at attempt start", and zero would be a version that does not exist',
        );

        // The version points at the immutable content row, which is what makes
        // an edit a new row rather than a rewrite of what a learner was asked.
        $pinned = $database->selectOne(
            Query::from('question_versions', 'v')
                ->select(['v.id', 'q.id AS question_id', 'q.type'])
                ->join('questions', 'q', 'v.question_id', 'q.id')
                ->where('v.id', '=', $versionId),
        );

        $this->expect(
            (int) ($pinned['question_id'] ?? 0) === $this->questionId,
            'the version resolves to the immutable question row',
            'version ' . $versionId . ' -> question ' . var_export($pinned['question_id'] ?? null, true)
            . ' of type ' . var_export($pinned['type'] ?? null, true),
        );

        // The pool query is a serialised predicate tree, and the thing worth
        // proving about a JSON column is that a tree comes back a tree. MySQL
        // normalises key order and whitespace, so the comparison is over the
        // decoded structure and never over the string.
        $pool = $database->selectOne(Query::from('quiz_slots')->where('id', '=', $poolSlotId));
        $decoded = json_decode((string) ($pool['ref'] ?? ''), true);

        $this->expect(
            $this->sameTree($decoded, self::POOL_QUERY),
            'the pool query survives the host JSON column as a tree',
            \is_array($decoded)
                ? 'and, or and not nodes over ' . $this->leafCount($decoded) . ' typed leaves'
                : 'came back as ' . \gettype($decoded),
        );

        $this->facts[] = 'banked quiz: category ' . $categoryId . ', entry ' . $entryId . ', version '
            . $versionId . ', slots ' . $sectionId . '/' . $questionSlotId . '/' . $poolSlotId;
    }

    /**
     * A rubric marked against a submission this site actually has.
     *
     * The human grade is appended beside the machine one rather than written
     * over it, which is both what a rater does and what keeps this contract
     * from touching a row it did not create: grades are append-only, and the
     * assertion at the end is that the machine row is exactly where it was.
     */
    private function checkRubricGradedSubmission(): void
    {
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');
        $database = $this->ports->database;
        $grade = $this->baseGrade;

        if ($grade === null) {
            $this->facts[] = 'no grade row on this quiz, so the rubric grade has nothing to hang off';

            return;
        }

        $rubricId = $database->insert('rubrics', [
            'owner_scope' => 'quiz',
            'owner_id' => $this->quizId,
            'name' => self::MARKER,
            'kind' => 'analytic',
            'min_score_is_zero' => 1,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $firstVersionId = $database->insert('rubric_versions', [
            'rubric_id' => $rubricId,
            'version' => 1,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $criteria = [];

        foreach (['Method' => 2.0, 'Units' => 1.0] as $description => $top) {
            $criterionId = $database->insert('rubric_criteria', [
                'rubric_version_id' => $firstVersionId,
                'position' => \count($criteria) + 1,
                'description' => $description,
                'weight' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $levels = [];

            foreach ([0.0, $top] as $position => $points) {
                $levels[] = $database->insert('rubric_levels', [
                    'criterion_id' => $criterionId,
                    'position' => $position,
                    'points' => $points,
                    'descriptor' => $points > 0.0 ? 'Met' : 'Not met',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $criteria[$criterionId] = ['top' => $top, 'levels' => $levels];
        }

        $awarded = 0.0;

        foreach ($criteria as $criterion) {
            $awarded += $criterion['top'];
        }

        // The rater's grade: a row of its own, naming the rubric version it
        // ran under, so a later edit to the rubric cannot silently restate
        // what a human decided.
        $gradeId = $database->insert('attempt_grades', [
            'attempt_id' => $grade['attempt_id'],
            'question_id' => $grade['question_id'],
            'grader_id' => null,
            'points_awarded' => $awarded,
            'max_points' => $grade['max_points'],
            'feedback' => self::MARKER,
            'graded_at' => $now,
            'part_id' => $grade['part_id'],
            'raw_score' => $awarded,
            'min_score' => 0,
            'policy_key' => 'rubric_analytic',
            'scoring_params' => $this->json(['rubric_version_id' => $firstVersionId]),
            'session_status' => 'final',
            'score_status' => 'scored',
            'state' => 'correct',
            'answered_status' => 'answered',
            'feedback_ids' => null,
            'version' => (int) $grade['version'] + 1,
            'rubric_version_id' => $firstVersionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($criteria as $criterionId => $criterion) {
            $database->insert('attempt_grade_criteria', [
                'grade_id' => $gradeId,
                'criterion_id' => $criterionId,
                'level_id' => $criterion['levels'][1],
                'points' => $criterion['top'],
                'comment' => self::MARKER,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rows = $database->selectAll(
            Query::from('attempt_grade_criteria')
                ->select(['criterion_id', 'level_id', 'points'])
                ->where('grade_id', '=', $gradeId)
                ->orderBy('criterion_id'),
        );

        $this->expect(
            \count($rows) === \count($criteria),
            'the criterion rows join back to the grade',
            \count($rows) . ' criterion row(s) on grade ' . $gradeId,
        );

        $withoutLevel = 0;
        $sum = 0.0;

        foreach ($rows as $row) {
            $withoutLevel += $row['level_id'] === null ? 1 : 0;
            $sum += (float) $row['points'];
        }

        // The level id, never only the points: it is what makes analytic
        // feedback, rater agreement analysis and a safe rubric revision
        // possible, and it cannot be reconstructed from a number.
        $this->expect(
            $withoutLevel === 0,
            'every criterion row names the level the rater chose',
            'and not only the points it happened to be worth',
        );

        $this->expect(
            abs($sum - $awarded) < 0.001,
            'the criterion points sum to what the rubric awarded',
            number_format($sum, 2) . ' of ' . number_format($awarded, 2),
        );

        $stamped = $database->selectOne(
            Query::from('attempt_grades')->select(['rubric_version_id'])->where('id', '=', $gradeId),
        );

        $this->expect(
            (int) ($stamped['rubric_version_id'] ?? 0) === $firstVersionId,
            'the grade names the rubric version it ran under',
            'attempt_grades.rubric_version_id = ' . var_export($stamped['rubric_version_id'] ?? null, true),
        );

        // Editing a rubric creates a version and never touches a historic
        // grade. The second version exists; the grade still names the first.
        $secondVersionId = $database->insert('rubric_versions', [
            'rubric_id' => $rubricId,
            'version' => 2,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stillFirst = $database->selectOne(
            Query::from('attempt_grades')->select(['rubric_version_id'])->where('id', '=', $gradeId),
        );

        $versions = $database->count(Query::from('rubric_versions')->where('rubric_id', '=', $rubricId));

        $this->expect(
            $versions === 2 && (int) ($stillFirst['rubric_version_id'] ?? 0) === $firstVersionId,
            'a rubric edit is a new version and leaves the historic grade alone',
            'rubric ' . $rubricId . ' has ' . $versions . ' versions; the grade still names ' . $firstVersionId
            . ' rather than ' . $secondVersionId,
        );

        $this->expectBaseGradeUntouched('the rater\'s grade was appended beside the machine one');

        $this->facts[] = 'rubric grade: rubric ' . $rubricId . ' v' . $firstVersionId . ', '
            . \count($criteria) . ' criteria on grade ' . $gradeId;
    }

    /**
     * A regrade appends. It is the invariant the whole append-only grade
     * history exists for, and the new columns on the row are what make it
     * auditable rather than merely repeated.
     */
    private function checkRegrade(): void
    {
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');
        $database = $this->ports->database;
        $grade = $this->baseGrade;

        if ($grade === null) {
            $this->facts[] = 'no grade row on this quiz, so there is nothing to regrade';

            return;
        }

        $attemptId = (int) $grade['attempt_id'];
        $before = $database->selectAll(
            Query::from('attempt_questions')
                ->select(['id', 'definition_hash'])
                ->where('attempt_id', '=', $attemptId)
                ->orderBy('position'),
        );

        // Monotonic over what is already there rather than over the row this
        // started from: the rubric section has appended one of its own, and a
        // version number that repeated would make the history unorderable.
        $highest = $database->selectOne(
            Query::from('attempt_grades')
                ->select(['version'])
                ->where('attempt_id', '=', $attemptId)
                ->where('question_id', '=', $grade['question_id'])
                ->orderBy('version', Query::SORT_DESC),
        );

        $appendedId = $database->insert('attempt_grades', [
            'attempt_id' => $attemptId,
            'question_id' => $grade['question_id'],
            'grader_id' => null,
            'points_awarded' => (float) $grade['max_points'],
            'max_points' => $grade['max_points'],
            'feedback' => self::MARKER,
            'graded_at' => $now,
            'part_id' => $grade['part_id'],
            'raw_score' => $grade['max_points'],
            'min_score' => 0,
            'policy_key' => $grade['policy_key'],
            'scoring_params' => $this->json(['regrade' => 'full_credit']),
            'session_status' => 'final',
            'score_status' => 'scored',
            'state' => 'correct',
            'answered_status' => 'answered',
            'feedback_ids' => null,
            'version' => (int) ($highest['version'] ?? $grade['version']) + 1,
            'rubric_version_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $history = $database->selectAll(
            Query::from('attempt_grades')
                ->select(['id', 'version', 'points_awarded', 'policy_key'])
                ->where('attempt_id', '=', $attemptId)
                ->where('question_id', '=', $grade['question_id'])
                ->orderBy('version'),
        );

        $this->expect(
            \count($history) >= 2,
            'a regrade appends a grade row rather than mutating one',
            \count($history) . ' grade row(s) for attempt ' . $attemptId . ', versions '
            . implode(', ', array_map(static fn (array $row): string => (string) $row['version'], $history)),
        );

        $this->expectBaseGradeUntouched('the row the regrade replaced is untouched');

        // A regrade re-runs against the frozen manifest, so appending a grade
        // row must move nothing in attempt_questions: the definition it was
        // graded from is the definition it was served.
        $after = $database->selectAll(
            Query::from('attempt_questions')
                ->select(['id', 'definition_hash'])
                ->where('attempt_id', '=', $attemptId)
                ->orderBy('position'),
        );

        $this->expect(
            $before === $after,
            'the frozen manifest is untouched by the regrade',
            $after === []
                ? 'this attempt predates the manifest, so there was nothing to move'
                : \count($after) . ' manifest row(s), same definition hashes',
        );

        // And the attempt still reads, which is the check that would catch a
        // repository that assumed one grade row per question.
        $attempts = new DatabaseAttemptRepository($this->ports->database, $this->ports->clock);
        $attempt = $attempts->byId($attemptId);

        if (!$attempt instanceof Attempt) {
            $this->failures[] = 'attempt ' . $attemptId . ' no longer loads after the regrade';

            return;
        }

        try {
            $served = $this->kernel->attemptManifest->forAttempt($attempt);
            $this->kernel->manualGradingStatus->pendingCountFor($attempt);
        } catch (\Throwable $error) {
            $this->failures[] = 'attempt ' . $attemptId . ' does not read after the regrade: ' . $error->getMessage();

            return;
        }

        $this->expect(
            $served !== [],
            'the regraded attempt still reads through the manifest',
            \count($served) . ' served question(s), grade row ' . $appendedId . ' appended',
        );

        $this->facts[] = 'regrade: attempt ' . $attemptId . ' now carries ' . \count($history) . ' grade rows';
    }

    /**
     * Reads the newest grade row on this quiz as it stands before anything is
     * written, and remembers it.
     *
     * Read once and shared, because both the rubric section and the regrade
     * section append beside it and each of them asserts it did not move; two
     * separate reads would let the second one see the first one's row and
     * assert nothing.
     */
    private function readBaseGrade(): void
    {
        $this->baseGrade = $this->ports->database->selectOne(
            Query::from('attempt_grades', 'g')
                ->select([
                    'g.id', 'g.attempt_id', 'g.question_id', 'g.part_id', 'g.points_awarded',
                    'g.max_points', 'g.policy_key', 'g.version', 'g.rubric_version_id',
                    'g.score_status', 'g.state',
                ])
                ->join('attempts', 'a', 'g.attempt_id', 'a.id')
                ->where('a.quiz_id', '=', $this->quizId)
                ->orderBy('g.id', Query::SORT_DESC),
        );
    }

    /**
     * The grade row this contract found is still exactly the grade row this
     * contract found.
     *
     * It is the append-only invariant asked of the two sections that write a
     * grade, and it is also this contract's own guarantee: nothing here
     * updates a row it did not create, so a rollback that failed leaves rows
     * to delete and never a value to restore.
     */
    private function expectBaseGradeUntouched(string $what): void
    {
        $grade = $this->baseGrade;

        if ($grade === null) {
            return;
        }

        $now = $this->ports->database->selectOne(
            Query::from('attempt_grades')
                ->select([
                    'id', 'attempt_id', 'question_id', 'part_id', 'points_awarded',
                    'max_points', 'policy_key', 'version', 'rubric_version_id',
                    'score_status', 'state',
                ])
                ->where('id', '=', (int) $grade['id']),
        );

        $this->expect(
            $now === $grade,
            $what,
            $now === null
                ? 'grade ' . $grade['id'] . ' is gone'
                : 'grade ' . $grade['id'] . ' still awards ' . var_export($grade['points_awarded'], true)
                . ' at version ' . var_export($grade['version'], true) . ' under '
                . var_export($grade['policy_key'], true),
        );
    }

    /**
     * A count that reports nothing rather than throwing, for the survivor
     * check: on a site where the Pro package is not installed the table is not
     * there, and "no rows survived" is the right answer.
     */
    private function countQuietly(Query $query): int
    {
        try {
            return $this->ports->database->count($query);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * Structural equality, which is what a JSON column promises. A string
     * comparison would fail on MySQL for a reason that is not a defect: it
     * stores JSON parsed and prints it back normalised.
     */
    private function sameTree(mixed $decoded, mixed $expected): bool
    {
        if (\is_array($expected)) {
            if (!\is_array($decoded) || \count($decoded) !== \count($expected)) {
                return false;
            }

            foreach ($expected as $key => $value) {
                if (!\array_key_exists($key, $decoded) || !$this->sameTree($decoded[$key], $value)) {
                    return false;
                }
            }

            return true;
        }

        return $decoded === $expected;
    }

    /**
     * How many typed leaf predicates a decoded pool query holds, for the fact
     * line: a tree that came back flat would say one.
     */
    private function leafCount(mixed $node): int
    {
        if (!\is_array($node)) {
            return 0;
        }

        $children = $node['children'] ?? null;

        if (\is_array($children)) {
            $total = 0;

            foreach ($children as $child) {
                $total += $this->leafCount($child);
            }

            return $total;
        }

        if (isset($node['kind'])) {
            return 1;
        }

        $total = 0;

        foreach ($node as $child) {
            $total += $this->leafCount($child);
        }

        return $total;
    }

    private function expect(bool $held, string $what, string $detail): void
    {
        if ($held) {
            $this->facts[] = $what . ': ' . $detail;

            return;
        }

        $this->failures[] = $what . ' is not true here: ' . $detail;
    }
}
