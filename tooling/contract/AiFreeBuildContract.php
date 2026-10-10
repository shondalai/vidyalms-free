<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Application\Ai\AiEnablementState;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunRequest;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor\QuestionAcceptor;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckReadability;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\QuestionTemplateAig;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;

/**
 * R8's free AI build, driven against a real site and a real database.
 *
 * One body of assertions, two callers, exactly as AutomationEngineContract and
 * OrganisationSeatContract are run: each free adapter's build/smoke.php runs it
 * against its own ports. It matters here for the reason it mattered there and
 * for one more. The AI spine writes twelve tables through the host's own driver,
 * six of their columns are JSON the two drivers hand back differently, and one
 * of the things it must never do is send anything anywhere.
 *
 * What it proves that a unit test on SQLite cannot:
 *
 *   - a site arrives with all four levels of enablement off, and turning them on
 *     is four separate acts by a named person, through four shipped routes;
 *   - a deterministic check task runs to completion over the null bridge, so a
 *     site that never buys Pro still has working AI tooling;
 *   - the dry run persists the redaction manifest, that manifest holds labels
 *     and not the text it stands for, and no attempt row is written at all,
 *     because nothing was attempted and nothing was sent;
 *   - a generated question draft is not reachable by a learner, is edited by a
 *     named reviewer, and becomes a question only through AcceptAiDraft, which
 *     writes the provenance row in the same transaction;
 *   - the one stop above all four levels stops a run from starting while it is
 *     set, and the run it stopped is recorded as cancelled rather than failed;
 *   - the learner whose work a run named reads that run back on their own
 *     activity page, with the fields that were sent and no draft payload.
 *
 * **It seeds its own quiz, policy, route and settings and removes every row it
 * wrote**, including the question its one acceptance produced and the settings
 * it switched on. The removal runs in a finally, so a failed assertion still
 * leaves the site as it was found. It creates no table and drops none, and it
 * never names a Community Quiz table.
 *
 * **It never overrules a decision the site owner already made.** An active data
 * use policy is reused rather than superseded, an existing enabled route is
 * reused rather than duplicated, every enablement row it writes is restored to
 * the value it found, and a site that was already stopped is left stopped with
 * that half of the journey recorded as skipped. A harness that unstopped a
 * stopped site would be the exact failure the stop exists to prevent.
 *
 * **Nothing here reaches a model, and there is none to reach.** The free build's
 * only registered bridge is the null one; every route this section writes names
 * it, and the run rows it leaves behind say so in their own bridge_key column.
 */
final class AiFreeBuildContract
{
    /** Everything this contract creates carries it, so cleanup can be exact. */
    public const MARKER = 'vidyalms-ai-contract';

    /** The findings-only check this section runs, and the draft-writing task it accepts from. */
    private const CHECK_TASK = CheckReadability::KEY;

    private const DRAFT_TASK = QuestionTemplateAig::KEY;

    /**
     * The placement both tasks publish, so one toggle governs the journey.
     *
     * A placement key is asserted rather than assumed: a run naming a placement
     * whose switch is off is refused, which is the second of the four levels.
     */
    private const PLACEMENT = 'workbench_question_bank';

    /** The body this section's own policy version carries, and finds itself by. */
    /**
     * The eight-part document this section drafts and removes again.
     *
     * A body used to be free prose and activation validated nothing, so a
     * site could put a version with an empty part in force and then record
     * everybody's acceptance of it. The marker sentence is kept on the first
     * part so the cleanup at the end still finds the row it wrote.
     *
     * @var array<string, string>
     */
    private const POLICY_PARTS = [
        'based_on_external_models' => 'The AI contract section wrote this version and removed it again.',
        'accuracy_varies_check_outputs' => 'Accuracy varies. Check what it produces before you use it.',
        'handled_under_provider_policy' => 'What is sent is handled under the provider policy this route names.',
        'interactions_may_be_recorded' => 'A record of interactions is saved on this site.',
        'enabled_tasks' => 'Only the tasks listed on the AI screen are enabled here.',
        'route_and_model_family' => 'The route and model family in force are shown beside each task.',
        'fields_sent_and_never_sent' => 'The redaction manifest lists exactly which fields are sent.',
        'retention_and_where_to_ask' => 'Records are kept for the stated window; ask the site administrator.',
    ];

    /**
     * The sentence the check reads. It is long enough to score, and distinctive
     * enough that finding it inside a stored manifest is proof the manifest
     * carried content rather than labels.
     */
    private const PROBE_SENTENCE = 'Photosynthesis converts luminous energy into chemical energy inside'
        . ' chloroplasts, which subsequently sustains virtually every terrestrial food web.';

    /** @var list<string> */
    private array $facts = [];

    /** @var list<string> */
    private array $failures = [];

    private int $quizId = 0;

    /** Zero when the site already had an active policy this section reused. */
    private int $policyVersion = 0;

    /** @var list<int> The routes this section created, which are the only ones it removes. */
    private array $createdRoutes = [];

    private int $acceptedQuestionId = 0;

    private bool $stopWasAlreadySet = false;

    /** @var list<int> */
    private array $runIds = [];

    /**
     * The provider_settings rows as they were found, restored on the way out.
     *
     * @var array<string, array{registry: string, provider: string, row: array<string, mixed>|null}>
     */
    private array $settingsBefore = [];

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
        private readonly Principal $actor,
        private readonly int $courseId,
        private readonly int $learnerId,
    ) {
    }

    /**
     * @return array{failures: list<string>, facts: list<string>, runs: int, drafts_accepted: int}
     */
    public static function run(
        CoreKernel $kernel,
        Ports $ports,
        Principal $actor,
        int $courseId,
        int $learnerId,
    ): array {
        $contract = new self($kernel, $ports, $actor, $courseId, $learnerId);

        try {
            $contract->seed();
            $contract->checkEveryLevelIsOffUntilSomebodyTurnsItOn();
            $contract->checkTheDryRunPersistsTheManifestAndSendsNothing();
            $contract->checkACheckTaskRunsToCompletionOverTheNullBridge();
            $contract->checkAGeneratedDraftIsNotReachableByALearner();
            $contract->checkAcceptingWritesTheQuestionAndItsProvenanceTogether();
            $contract->checkTheStopRefusesAStart();
            $contract->checkTheLearnerReadsTheRunThatNamedThem();
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        } finally {
            $contract->removeEverythingItWrote();
        }

        return [
            'failures' => $contract->failures,
            'facts' => $contract->facts,
            'runs' => \count($contract->runIds),
            'drafts_accepted' => $contract->acceptedQuestionId > 0 ? 1 : 0,
        ];
    }

    // -----------------------------------------------------------------------
    // The contract
    // -----------------------------------------------------------------------

    /**
     * A quiz for the one accepted item to land in, and a snapshot of every
     * enablement row this section is about to write over.
     */
    private function seed(): void
    {
        $this->rememberSetting(AiEnablementState::REGISTRY, AiEnablementState::ENABLEMENT_KEY);
        $this->rememberSetting(AiEnablementState::REGISTRY, AiEnablementState::STOP_KEY);
        $this->rememberSetting(AiEnablementState::PLACEMENT_REGISTRY, self::PLACEMENT);

        $stop = $this->settingsBefore[AiEnablementState::REGISTRY . '/' . AiEnablementState::STOP_KEY]['row'] ?? null;
        $this->stopWasAlreadySet = \is_array($stop)
            && str_contains((string) ($stop['params'] ?? ''), '"stopped":true');

        $this->removeStrayPolicies();

        $quiz = $this->call('POST', '/manage/courses/' . $this->courseId . '/quizzes', [
            'title' => 'AI contract quiz',
            'pass_mark' => 50,
        ], 201);

        $this->quizId = (int) ($quiz['id'] ?? 0);

        $this->expect(
            $this->quizId > 0,
            'a quiz exists for the one accepted item to be added to',
            self::describe($quiz),
        );
    }

    /**
     * All four levels are off on arrival, and turning them on is four acts.
     *
     * This is the design's published behaviour and the thing a site owner is
     * entitled to: an upgrade that switched AI on would be a site sending text
     * somewhere because it was updated.
     */
    private function checkEveryLevelIsOffUntilSomebodyTurnsItOn(): void
    {
        $before = $this->call('GET', '/manage/ai/enablement', [], 200);

        if ($this->settingsBefore[AiEnablementState::REGISTRY . '/' . AiEnablementState::ENABLEMENT_KEY]['row'] === null) {
            $offByDefault = ($before['site_enabled'] ?? true) === false;

            foreach ($before['placements'] ?? [] as $placement) {
                if (($placement['enabled'] ?? true) !== false) {
                    $offByDefault = false;
                }
            }

            $this->expect(
                $offByDefault,
                'level one and level two are off until somebody turns them on',
                self::describe($before['site_enabled'] ?? null),
            );
        } else {
            $this->facts[] = 'ok: this site had already decided its enablement, so the default was read and not asserted';
        }

        // Level one and level two, in one act.
        $enabled = $this->call('PUT', '/manage/ai/enablement', [
            'site_enabled' => true,
            'placements' => [self::PLACEMENT => true],
        ], 200);

        $this->expect(
            ($enabled['site_enabled'] ?? false) === true,
            'the site switch and the placement switch are saved as one act',
            self::describe($enabled['site_enabled'] ?? null),
        );

        $this->resolvePolicy();

        // Level three: one enabled route per task, on the null bridge, with a
        // real per-run ceiling. Zero refuses, which is why a provisioning
        // import cannot leave an uncapped route behind.
        $check = $this->resolveRoute(self::CHECK_TASK);
        $draft = $this->resolveRoute(self::DRAFT_TASK);

        $this->expect(
            $check > 0 && $draft > 0,
            'both routes point at the null bridge, which is the only bridge a free build has',
            'routes ' . $check . ' and ' . $draft,
        );
    }

    /**
     * Level four: a versioned data-use policy, and this person's acceptance of
     * that exact version.
     *
     * An active version the site owner already published is reused. Activating a
     * second one would supersede theirs, and a cleanup that then removed mine
     * would leave the site with no policy in force at all.
     */
    private function resolvePolicy(): void
    {
        $state = $this->call('GET', '/ai/policy', [], 200);
        $active = \is_array($state['policy'] ?? null) ? $state['policy'] : null;

        if ($active !== null) {
            $version = (int) ($active['version'] ?? 0);
            $this->facts[] = 'ok: this site already publishes policy version ' . $version . ', which is reused';
        } else {
            $policy = $this->call('POST', '/manage/ai/policies', [
                'title' => 'How this site uses AI',
                'body' => self::POLICY_PARTS,
                'retention_days' => 400,
                'redaction_level' => 'strict',
                'enabled_tasks' => [self::CHECK_TASK, self::DRAFT_TASK],
            ], 201);

            $this->policyVersion = (int) ($policy['version'] ?? 0);
            $version = $this->policyVersion;

            $this->expect(
                $version > 0 && (string) ($policy['state'] ?? '') === 'draft',
                'a policy version is drafted, and activation is a separate act',
                self::describe($policy['state'] ?? null),
            );

            $activated = $this->call('PATCH', '/manage/ai/policies/' . $version, ['state' => 'active'], 200);

            $this->expect(
                (string) ($activated['state'] ?? '') === 'active',
                'the policy version is in force once a named person activates it',
                self::describe($activated['state'] ?? null),
            );
        }

        if (($state['accepted'] ?? false) === true && (int) ($state['accepted_version'] ?? 0) === $version) {
            $this->facts[] = 'ok: this person had already accepted the version in force';

            return;
        }

        $accepted = $this->call('PUT', '/ai/policy/acceptance', [
            'version' => $version,
            'placement_key' => self::PLACEMENT,
        ], 200);

        $this->expect(
            ($accepted['accepted'] ?? false) === true,
            'the person who will run the task has accepted the version in force',
            self::describe($accepted),
        );
    }

    /**
     * The dry run is the privacy artefact.
     *
     * It resolves the inputs, renders exactly what would be sent, writes the run
     * in state previewed and calls nothing. Two halves are asserted here that a
     * schema cannot state: the manifest is stored, and the manifest holds labels
     * rather than the text it stands for. A manifest that carried content would
     * be a second copy of the learner's words in a table nobody purges on the
     * draft's timetable.
     */
    private function checkTheDryRunPersistsTheManifestAndSendsNothing(): void
    {
        $estimate = $this->call('POST', '/ai/estimate', $this->checkBody('estimate'), 200);
        $runId = (int) ($estimate['run_id'] ?? 0);

        if ($runId > 0) {
            $this->runIds[] = $runId;
        }

        $this->expect(
            $runId > 0 && ($estimate['allowed'] ?? false) === true,
            'the estimate is allowed on a site whose four levels are on',
            self::describe($estimate['refusal'] ?? null),
        );

        $row = $this->runRow($runId);

        $this->expect(
            (string) ($row['state'] ?? '') === 'previewed' && (int) ($row['dry_run'] ?? 0) === 1,
            'the dry run is recorded as previewed rather than as a run that happened',
            (string) ($row['state'] ?? 'missing'),
        );

        $manifest = (string) ($row['redaction_manifest'] ?? '');

        $this->expect(
            $manifest !== '' && str_contains($manifest, 'fields'),
            'the manifest of what would be sent is persisted on the run row',
            $manifest === '' ? 'no manifest' : substr($manifest, 0, 120),
        );

        $this->expect(
            !str_contains($manifest, 'Photosynthesis'),
            'the manifest names the fields and never carries the text they hold',
            substr($manifest, 0, 120),
        );

        $this->expect(
            (string) ($row['inputs_digest'] ?? '') !== '',
            'the dry run records a digest of its inputs, so the real run can be compared with it',
            (string) ($row['inputs_digest'] ?? ''),
        );

        // Nothing was sent, and the evidence is that nothing was even attempted.
        $this->expect(
            $this->rows('ai_run_attempts', ['run_id' => $runId]) === 0
            && $this->rows('ai_drafts', ['run_id' => $runId]) === 0,
            'a preview writes no attempt row and no draft, because it called nothing',
            'attempts ' . $this->rows('ai_run_attempts', ['run_id' => $runId])
                . ', drafts ' . $this->rows('ai_drafts', ['run_id' => $runId]),
        );
    }

    /**
     * The check task itself, run for real over the null bridge.
     *
     * This is the whole free promise in one call: a site that never buys a model
     * still has a working reading-level check, and the attempt row it leaves
     * behind names the null bridge and a cost unit of none, which is what an
     * operator reading the run log a year later needs to see.
     */
    private function checkACheckTaskRunsToCompletionOverTheNullBridge(): void
    {
        $run = $this->call('POST', '/ai/runs', $this->checkBody('run'), 200, self::MARKER . '-check');
        $runId = (int) ($run['id'] ?? 0);

        if ($runId > 0) {
            $this->runIds[] = $runId;
        }

        $this->expect(
            (string) ($run['state'] ?? '') === 'succeeded',
            'a deterministic check runs to completion on a free build',
            (string) ($run['state'] ?? 'missing') . ' ' . self::describe($run['refusal'] ?? null),
        );

        // The route id first, because it is what makes the bridge key mean
        // anything. `bridge_key` used to be a literal the pipeline assigned
        // and no stage reassigned, so this assertion passed identically on a
        // site whose bridge registry was empty and on one whose enabled route
        // named something else entirely.
        $this->expect(
            ($run['route_id'] ?? null) !== null,
            'the run names the route it selected, so the bridge key below is the route\'s and not a default',
            self::describe($run['route_id'] ?? null),
        );

        $this->expect(
            (string) ($run['bridge_key'] ?? '') === 'null'
            && ($run['model'] ?? null) === null
            && (string) ($run['cost_unit'] ?? '') === 'none',
            'the run names the null bridge, no model and no cost',
            self::describe([
                'route' => $run['route_id'] ?? null,
                'bridge' => $run['bridge_key'] ?? null,
                'model' => $run['model'] ?? null,
                'unit' => $run['cost_unit'] ?? null,
            ]),
        );

        $attempts = \is_array($run['attempts'] ?? null) ? $run['attempts'] : [];

        $this->expect(
            \count($attempts) === 1 && (string) ($attempts[0]['bridge_key'] ?? '') === 'null',
            'exactly one attempt is recorded, and it is the free bridge',
            self::describe($attempts),
        );

        // No score, no confidence, no risk: the absence is the design statement
        // and the wire is where one would first appear.
        $this->expect(
            !\array_key_exists('score', $run)
            && !\array_key_exists('confidence', $run)
            && !\array_key_exists('risk', $run),
            'the run shape carries no score, no confidence and no risk',
            implode(', ', array_keys($run)),
        );
    }

    /**
     * A generated question draft holds correct answers by construction, so no
     * learner read path reaches it.
     *
     * The draft-writing task is deterministic template expansion, which needs no
     * model at all and is the oldest form of automatic item generation there is.
     */
    private function checkAGeneratedDraftIsNotReachableByALearner(): void
    {
        $run = $this->call('POST', '/ai/runs', $this->draftBody(), 200, self::MARKER . '-draft');
        $runId = (int) ($run['id'] ?? 0);

        if ($runId > 0) {
            $this->runIds[] = $runId;
        }

        $drafts = \is_array($run['drafts'] ?? null) ? $run['drafts'] : [];

        $this->expect(
            (string) ($run['state'] ?? '') === 'succeeded' && \count($drafts) > 0,
            'the template task writes its drafts through the run pipeline',
            (string) ($run['state'] ?? 'missing') . ', ' . \count($drafts) . ' draft(s)',
        );

        $draftId = (int) ($drafts[0]['id'] ?? 0);

        if ($draftId < 1) {
            return;
        }

        $learner = $this->kernel->apiKernel()->handle(new ApiRequest(
            'GET',
            '/ai/drafts/' . $draftId,
            Principal::user($this->learnerId),
        ));

        $queue = $this->kernel->apiKernel()->handle(new ApiRequest(
            'GET',
            '/ai/drafts',
            Principal::user($this->learnerId),
            ['course_id' => (string) $this->courseId],
        ));

        $this->expect(
            $learner->status === 403 && $queue->status === 403,
            'no learner read path reaches a draft, on the single read or on the queue',
            'single ' . $learner->status . ', queue ' . $queue->status,
        );
    }

    /**
     * The reviewer edits, and the acceptance is the only writer.
     *
     * The edit lands beside the payload and never over it, so what the machine
     * produced and what the person approved stay separable for ever. Acceptance
     * goes through AddQuestion and never a table write, so the type's own schema
     * check runs on the way in, and the provenance row is written inside the same
     * transaction as the artefact.
     */
    private function checkAcceptingWritesTheQuestionAndItsProvenanceTogether(): void
    {
        $draftId = $this->firstDraftId();

        if ($draftId < 1 || $this->quizId < 1) {
            return;
        }

        $draft = $this->call('GET', '/ai/drafts/' . $draftId, [], 200);
        $payload = \is_array($draft['payload'] ?? null) ? $draft['payload'] : [];

        // The one thing the machine cannot know: which quiz this item belongs
        // to. A person decides that, which is what makes this an edit.
        $payload[QuestionAcceptor::PAYLOAD_QUIZ_ID] = $this->quizId;

        $edited = $this->call('PATCH', '/ai/drafts/' . $draftId, [
            'edited_payload' => $payload,
        ], 200, self::MARKER . '-edit');

        $this->expect(
            (string) ($edited['state'] ?? '') === 'in_review'
            && (int) ($edited['reviewer_user_id'] ?? 0) === $this->actor->userId,
            'the edit moves the draft into review and stamps the named reviewer',
            self::describe($edited['state'] ?? null),
        );

        $accepted = $this->call('POST', '/ai/drafts/' . $draftId . '/accept', [
            'acknowledge_blocking' => true,
        ], 200, self::MARKER . '-accept');

        $artefact = \is_array($accepted['artefact'] ?? null) ? $accepted['artefact'] : [];
        $this->acceptedQuestionId = (int) ($artefact['entity_id'] ?? 0);

        $this->expect(
            $this->acceptedQuestionId > 0 && (string) ($artefact['entity_type'] ?? '') === 'question',
            'the accepted draft becomes a question through the ordinary authoring path',
            self::describe($artefact),
        );

        $this->expect(
            $this->rows('questions', ['id' => $this->acceptedQuestionId]) === 1,
            'the question exists in the questions table, written by AddQuestion',
            'question ' . $this->acceptedQuestionId,
        );

        $provenance = \is_array($accepted['provenance'] ?? null) ? $accepted['provenance'] : [];

        $this->expect(
            (int) ($provenance['accepted_by'] ?? 0) === $this->actor->userId
            && (string) ($provenance['disclosure_tier'] ?? '') !== ''
            && ($provenance['human_edited'] ?? null) === true,
            'the provenance row records who accepted it, that a person edited it, and the tier that follows',
            self::describe($provenance),
        );

        // The row a reader reaches later, which is the half that outlives the
        // draft and earns the editorial-responsibility exemption.
        $read = $this->call('GET', '/ai/provenance/question/' . $this->acceptedQuestionId, [], 200);
        $items = \is_array($read['items'] ?? null) ? $read['items'] : [];
        $first = \is_array($items[0] ?? null) ? $items[0] : [];

        $this->expect(
            (int) ($first['run_id'] ?? 0) > 0 && (string) ($first['task_key'] ?? '') === self::DRAFT_TASK,
            'the provenance is readable by subject afterwards and names the task that produced it',
            self::describe($items),
        );
    }

    /**
     * The one stop above all four levels.
     *
     * A site owner who has to put the whole thing down does it once, and it holds
     * for a run that has not started and for one already in flight. The stopped
     * run is still recorded, because a stop nobody can see afterwards is a stop
     * nobody can account for, and it is recorded as cancelled rather than as a
     * failure, because the site working exactly as instructed is not a fault.
     *
     * A site that arrived stopped is left stopped and this half is skipped: a
     * harness that cleared somebody's stop to test the stop would be the failure
     * the control exists to prevent.
     */
    private function checkTheStopRefusesAStart(): void
    {
        if ($this->stopWasAlreadySet) {
            $this->facts[] = 'ok: this site arrived stopped, so the stop journey was skipped and the stop left set';

            return;
        }

        $set = $this->call('POST', '/manage/ai/stop', [
            'reason' => 'The AI contract section is asserting the stop.',
        ], 200, self::MARKER . '-stop');

        $this->expect(
            ($set['stopped'] ?? false) === true && (int) ($set['set_by'] ?? 0) === $this->actor->userId,
            'the stop records who set it',
            self::describe($set),
        );

        $visible = $this->call('GET', '/ai/placements', [], 200);

        $this->expect(
            ($visible['stopped'] ?? false) === true,
            'the stop is visible to every caller and not only to the person who set it',
            self::describe($visible['stopped'] ?? null),
        );

        $run = $this->call('POST', '/ai/runs', $this->checkBody('stopped'), 200, self::MARKER . '-stopped');
        $runId = (int) ($run['id'] ?? 0);

        if ($runId > 0) {
            $this->runIds[] = $runId;
        }

        // Cancelled and not failed: the stop is an operator's decision, so the
        // row records that the site was put down rather than that something
        // broke. A site counting real errors must not count this one.
        $this->expect(
            (string) ($run['state'] ?? '') === 'cancelled' && ($run['error_code'] ?? null) === null,
            'a run started while the site is stopped is cancelled and is not recorded as a failure',
            (string) ($run['state'] ?? 'missing') . ' ' . self::describe($run['error_code'] ?? null),
        );

        $this->expect(
            $this->rows('ai_run_attempts', ['run_id' => $runId]) === 0
            && $this->rows('ai_drafts', ['run_id' => $runId]) === 0,
            'a stopped run attempts nothing and writes no draft',
            'attempts ' . $this->rows('ai_run_attempts', ['run_id' => $runId]),
        );

        $cleared = $this->call('DELETE', '/manage/ai/stop', [], 200, self::MARKER . '-resume');

        $this->expect(
            ($cleared['stopped'] ?? true) === false && (int) ($cleared['cleared_by'] ?? 0) === $this->actor->userId,
            'clearing the stop records who cleared it, so both halves are in the record',
            self::describe($cleared),
        );
    }

    /**
     * The learner's right to know, end to end.
     *
     * A run that acted on behalf of a named person writes a subject row, and that
     * person reads it back on their own page: what was sent, by whom, and under
     * which bridge. The run is started through the pipeline rather than through
     * the interactive route because that route names no subject, and a helper
     * that drafts feedback for one learner is exactly the placement that does.
     *
     * The page carries no draft payload, no score and no confidence, which is the
     * redline this whole design turns on.
     */
    private function checkTheLearnerReadsTheRunThatNamedThem(): void
    {
        $report = $this->kernel->aiPipeline->run(AiRunRequest::create(
            $this->actor,
            self::CHECK_TASK,
            AiTaskRequest::create('course', $this->courseId, $this->checkOptions('subject')),
            self::PLACEMENT,
            'course',
            $this->courseId,
            $this->courseId,
            null,
            $this->learnerId,
        ));

        if ($report->runId > 0) {
            $this->runIds[] = $report->runId;
        }

        $this->expect(
            $this->rows('ai_run_subjects', ['run_id' => $report->runId]) === 1,
            'a run that acted on behalf of a named person writes exactly one subject row',
            'subjects ' . $this->rows('ai_run_subjects', ['run_id' => $report->runId]),
        );

        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            'GET',
            '/me/ai-activity',
            Principal::user($this->learnerId),
        ));

        $data = \is_array($response->data) ? $response->data : [];
        $items = \is_array($data['items'] ?? null) ? $data['items'] : [];
        $mine = null;

        foreach ($items as $item) {
            if (\is_array($item) && (int) ($item['run_id'] ?? 0) === $report->runId) {
                $mine = $item;
            }
        }

        $this->expect(
            $response->status === 200 && $mine !== null,
            'the learner reads the run that named them on their own activity page',
            'status ' . $response->status . ', ' . \count($items) . ' item(s)',
        );

        if ($mine === null) {
            return;
        }

        $this->expect(
            (string) ($mine['my_role'] ?? '') === 'subject'
            && (int) ($mine['invoked_by'] ?? 0) === $this->actor->userId
            && (string) ($mine['bridge_key'] ?? '') === 'null'
            && ($mine['model'] ?? null) === null,
            'it says who ran it, under which bridge, and that no model was involved',
            self::describe($mine),
        );

        $this->expect(
            !\array_key_exists('payload', $mine)
            && !\array_key_exists('score', $mine)
            && !\array_key_exists('confidence', $mine),
            'the activity page carries no draft payload, no score and no confidence',
            implode(', ', array_keys($mine)),
        );

        $this->expect(
            (string) ($data['as_of'] ?? '') !== '',
            'the answer is stamped, because a retention purge runs daily behind it',
            self::describe($data['as_of'] ?? null),
        );
    }

    // -----------------------------------------------------------------------
    // Fixtures and helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function checkBody(string $tag): array
    {
        return [
            'task_key' => self::CHECK_TASK,
            'placement_key' => self::PLACEMENT,
            'scope_type' => 'course',
            'scope_id' => $this->courseId,
            'subject_type' => 'course',
            'subject_id' => $this->courseId,
            'options' => $this->checkOptions($tag),
        ];
    }

    /**
     * The inputs one run reads.
     *
     * The tag is part of the text rather than an extra option, because the run
     * key is a digest over what would be sent and two runs of one task with
     * identical inputs inside one second are one row. On a frozen clock that is
     * every run; on a real site it is the fast ones.
     *
     * @return array<string, mixed>
     */
    private function checkOptions(string $tag): array
    {
        return [
            'target_grade' => 8,
            'drafts' => [['payload' => [
                'prompt' => self::PROBE_SENTENCE . ' Reading ' . $tag . ' ' . bin2hex(random_bytes(4)) . '.',
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function draftBody(): array
    {
        return [
            'task_key' => self::DRAFT_TASK,
            'placement_key' => self::PLACEMENT,
            'scope_type' => 'course',
            'scope_id' => $this->courseId,
            'subject_type' => 'course',
            'subject_id' => $this->courseId,
            'options' => [
                QuestionTemplateAig::OPTION_STEM => 'Which organelle performs {process}? ('
                    . bin2hex(random_bytes(4)) . ')',
                QuestionTemplateAig::OPTION_CORRECT => 'The chloroplast',
                QuestionTemplateAig::OPTION_DISTRACTORS => ['The ribosome', 'The lysosome'],
                QuestionTemplateAig::OPTION_SLOTS => ['process' => ['photosynthesis']],
                QuestionTemplateAig::OPTION_LIMIT => 1,
            ],
        ];
    }

    /**
     * An enabled null-bridge route for the task, reusing one the site already
     * has rather than adding a second.
     */
    private function resolveRoute(string $taskKey): int
    {
        $existing = $this->call('GET', '/manage/ai/routes', [], 200);

        foreach ($existing['items'] ?? [] as $row) {
            if (
                \is_array($row)
                && (string) ($row['task_key'] ?? '') === $taskKey
                && (string) ($row['bridge_key'] ?? '') === 'null'
                && ($row['enabled'] ?? false) === true
            ) {
                $this->facts[] = 'ok: this site already routes ' . $taskKey . ' at the null bridge, which is reused';

                return (int) ($row['id'] ?? 0);
            }
        }

        $created = $this->call('POST', '/manage/ai/routes', [
            'task_key' => $taskKey,
            'bridge_key' => 'null',
            'max_cost_per_run' => 1000,
        ], 201, self::MARKER . '-route-' . $taskKey);

        $id = (int) ($created['id'] ?? 0);

        if ($id < 1) {
            return 0;
        }

        $this->createdRoutes[] = $id;

        $this->expect(
            ($created['enabled'] ?? true) === false,
            'a provisioned route arrives inert until a person turns it on',
            self::describe($created['enabled'] ?? null),
        );

        $enabled = $this->call('PATCH', '/manage/ai/routes/' . $id, [
            'enabled' => true,
        ], 200, self::MARKER . '-route-on-' . $taskKey);

        $this->expect(
            ($enabled['enabled'] ?? false) === true,
            'the route runs once a person enables it, and not before',
            self::describe($enabled['enabled'] ?? null),
        );

        return $id;
    }

    private function firstDraftId(): int
    {
        foreach ($this->runIds as $runId) {
            $rows = $this->ports->database->selectAll(
                Query::from('ai_drafts')->select(['id'])->where('run_id', '=', $runId),
            );

            foreach ($rows as $row) {
                return (int) ($row['id'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function runRow(int $runId): array
    {
        if ($runId < 1) {
            return [];
        }

        $rows = $this->ports->database->selectAll(Query::from('ai_runs')->where('id', '=', $runId));

        return \is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    /**
     * @param array<string, mixed> $where
     */
    private function rows(string $table, array $where): int
    {
        $query = Query::from($table);

        foreach ($where as $column => $value) {
            $query = $query->where($column, '=', $value);
        }

        return $this->ports->database->count($query);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body, int $expected, ?string $key = null): array
    {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            $this->actor,
            [],
            $body,
            ($key ?? self::MARKER) . '-' . bin2hex(random_bytes(8)),
        ));

        if ($response->status !== $expected) {
            $this->failures[] = sprintf(
                '%s %s answered %d, expected %d: %s',
                $method,
                $path,
                $response->status,
                $expected,
                self::describe($response->data),
            );

            return [];
        }

        $payload = \is_array($response->data) ? $response->data : [];

        return \is_array($payload['item'] ?? null) ? $payload['item'] : $payload;
    }

    /**
     * The row as it is now, so the way out can put it back exactly.
     */
    private function rememberSetting(string $registry, string $provider): void
    {
        $row = null;

        try {
            $rows = $this->ports->database->selectAll(
                Query::from('provider_settings')
                    ->where('registry_key', '=', $registry)
                    ->where('provider_key', '=', $provider)
                    ->where('scope_type', '=', 'site')
                    ->where('scope_id', '=', 0),
            );
            $row = \is_array($rows[0] ?? null) ? $rows[0] : null;
        } catch (\Throwable) {
            $row = null;
        }

        $this->settingsBefore[$registry . '/' . $provider] = [
            'registry' => $registry,
            'provider' => $provider,
            'row' => $row,
        ];
    }

    /**
     * A policy version this section left behind, from a run killed between its
     * writes and its finally.
     *
     * Found by the body it writes and never by a pattern, so a version the site
     * owner published is not touched.
     */
    private function removeStrayPolicies(): void
    {
        try {
            $stale = $this->ports->database->selectAll(
                Query::from('ai_policies')
                    ->select(['version'])
                    ->where('body', '=', json_encode(self::POLICY_PARTS, \JSON_THROW_ON_ERROR)),
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($stale as $row) {
            $version = (int) ($row['version'] ?? 0);

            if ($version < 1) {
                continue;
            }

            $this->ports->database->delete('ai_policy_acceptances', ['policy_version' => $version]);
            $this->ports->database->delete('ai_policies', ['version' => $version]);
            $this->facts[] = 'ok: removed a policy version left behind by an earlier run of this section';
        }
    }

    /**
     * Everything, including the one question the acceptance produced and the
     * enablement the section switched on.
     */
    private function removeEverythingItWrote(): void
    {
        $database = $this->ports->database;

        foreach ($this->runIds as $runId) {
            foreach (
                $database->selectAll(
                    Query::from('ai_drafts')->select(['id'])->where('run_id', '=', $runId),
                ) as $draft
            ) {
                try {
                    $database->delete('ai_draft_findings', ['draft_id' => (int) ($draft['id'] ?? 0)]);
                } catch (\Throwable) {
                    // A table this build does not carry is not a leak.
                }
            }

            foreach (['ai_artefact_provenance', 'ai_drafts', 'ai_run_subjects', 'ai_run_attempts'] as $table) {
                try {
                    $database->delete($table, ['run_id' => $runId]);
                } catch (\Throwable) {
                    // A table this build does not carry is not a leak.
                }
            }

            try {
                $database->delete('ai_runs', ['id' => $runId]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove a run row: ' . $failure->getMessage();
            }
        }

        if ($this->acceptedQuestionId > 0) {
            try {
                $database->delete('questions', ['id' => $this->acceptedQuestionId]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove the accepted question: ' . $failure->getMessage();
            }
        }

        foreach ($this->createdRoutes as $routeId) {
            try {
                $database->delete('ai_routes', ['id' => $routeId]);
            } catch (\Throwable) {
                // Already gone is not a leak.
            }
        }

        if ($this->policyVersion > 0) {
            try {
                $database->delete('ai_policy_acceptances', ['policy_version' => $this->policyVersion]);
                $database->delete('ai_policies', ['version' => $this->policyVersion]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove the policy version: ' . $failure->getMessage();
            }
        }

        $this->restoreSettings();

        if ($this->quizId > 0) {
            foreach (['questions'] as $table) {
                try {
                    $database->delete($table, ['quiz_id' => $this->quizId]);
                } catch (\Throwable) {
                    // A table this build does not carry is not a leak.
                }
            }

            try {
                $database->delete('quizzes', ['id' => $this->quizId]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove the quiz: ' . $failure->getMessage();
            }
        }
    }

    /**
     * Every enablement row back to the value it was found at, and gone again
     * when there was none.
     *
     * A site left with AI switched on by a smoke run is a site whose owner never
     * decided, which is the one thing four levels of enablement exist to stop.
     */
    private function restoreSettings(): void
    {
        foreach ($this->settingsBefore as $entry) {
            $where = [
                'registry_key' => $entry['registry'],
                'provider_key' => $entry['provider'],
                'scope_type' => 'site',
                'scope_id' => 0,
            ];

            try {
                if ($entry['row'] === null) {
                    $this->ports->database->delete('provider_settings', $where);

                    continue;
                }

                $this->ports->database->update('provider_settings', [
                    'params' => $entry['row']['params'] ?? null,
                    'params_version' => (int) ($entry['row']['params_version'] ?? 1),
                    'enabled' => (int) ($entry['row']['enabled'] ?? 1),
                ], $where);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not restore ' . $entry['registry'] . '/' . $entry['provider']
                    . ': ' . $failure->getMessage();
            }
        }
    }

    /**
     * A value as evidence, never false.
     *
     * json_encode answers false on a value it cannot encode, and evidence a
     * caller cannot read is worse than none: the one place this is reached is a
     * failure report somebody is about to debug from.
     */
    private static function describe(mixed $value): string
    {
        $encoded = json_encode($value);

        return $encoded === false ? gettype($value) : $encoded;
    }

    private function expect(bool $condition, string $what, string $evidence): void
    {
        if ($condition) {
            $this->facts[] = 'ok: ' . $what;

            return;
        }

        $this->failures[] = $what . ': ' . $evidence;
    }
}
