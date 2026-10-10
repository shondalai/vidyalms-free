<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Provider\Automation\NotifyAction;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\EntitlementExpiringSweep;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationFanoutTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationStepTask;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * R6's free automation engine, driven against a real site and a real database.
 *
 * One body of assertions, two callers, exactly as OrganisationSeatContract,
 * OrganisationImportContract and OrganisationIdentityContract are run: each
 * free adapter's build/smoke.php runs it against its own ports. It matters
 * here for the reason it mattered there and for one more. The engine writes
 * eight tables through the host's own driver, three of its columns are JSON,
 * and the one effect it produces lands in notification_queue, which is a table
 * both development sites are holding hundreds of live rows in.
 *
 * What it proves that a unit test on SQLite cannot:
 *
 *   - a rule with one notify action authors through the shipped route, arrives
 *     disabled and unarmed at version one, and arms only when a named person
 *     states the acknowledgement;
 *   - a dry run writes its own rows and nothing else: not one notification
 *     queue row, and no run row that a real trigger would then be refused by;
 *   - the same rule fired for real produces exactly one queue row, through the
 *     two scheduled tasks the host's own drain runs;
 *   - a replay produces none, because the re-entry guard refuses the second
 *     run and the step's idempotency claim refuses the second effect;
 *   - a disarm clears the arming triple and cancels what is still queued,
 *     while what already executed stays as history.
 *
 * **It seeds its own rule and removes every row it wrote**, including the
 * notification queue row its one real run produced and the idempotency claims
 * that run took. The removal runs in a finally, so a failed assertion still
 * leaves the site as it was found. It creates no table and drops none, and it
 * never names a Community Quiz table.
 *
 * The one thing it deliberately does not do is let its mail leave. The queue
 * row is asserted and then removed inside the same run, so no send task can
 * ever pick it up, and the rule is disarmed before cleanup so no sweep can
 * open a second run for it between the assertion and the delete.
 */
final class AutomationEngineContract
{
    /** Everything this contract creates carries it, so cleanup can be exact. */
    public const MARKER = 'vidyalms-automation-contract';

    /**
     * The trigger the rule is authored on.
     *
     * A shipped free sweep trigger, because a sweep is the one kind the
     * fan-out can resolve a subject from without a domain event having
     * happened, and because this contract drives the occurrence itself rather
     * than waiting for an entitlement on the site to come within the horizon.
     * The call it makes is the sweep's own call.
     */
    private const TRIGGER = EntitlementExpiringSweep::KEY;

    /** The person the one notify action would write to. */
    private const SUBJECT = 700200;

    /** @var list<string> */
    private array $facts = [];

    /** @var list<string> */
    private array $failures = [];

    private int $ruleId = 0;

    private string $occurrenceKey = '';

    private int $queueRowsAtStart = 0;

    /**
     * Every Idempotency-Key this contract has put on the wire.
     *
     * The route ledger claims one row per write request, under a scope naming
     * the operation and the caller, and those rows outlive the rule: the
     * claims the *engine* takes are read off automation_queue and removed with
     * it, but a request claim belongs to no rule and nothing was reading it
     * back. Section 10u therefore left four rows on a real site per run, which
     * a contract whose own docblock says it removes every row it wrote may not
     * do. Collected here rather than matched by prefix, because a prefix match
     * would also take a row another harness had minted under the same marker.
     *
     * @var list<string>
     */
    private array $requestKeys = [];

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
        private readonly Principal $actor,
    ) {
        $this->occurrenceKey = self::MARKER . ':' . bin2hex(random_bytes(6));
    }

    /**
     * @return array{failures: list<string>, facts: list<string>, queue_rows_added: int}
     */
    public static function run(CoreKernel $kernel, Ports $ports, Principal $actor): array
    {
        $contract = new self($kernel, $ports, $actor);
        $added = 0;

        try {
            $contract->seed();
            $contract->checkARuleWithANotifyActionAuthorsAndArms();
            $contract->checkADryRunWritesNothingButItsOwnRows();
            $contract->checkTheRealRunProducesExactlyOneQueueRow();
            $contract->checkAReplayProducesNone();
            $contract->checkDisarmingClearsTheArmingAndCancelsTheQueue();
            $added = $contract->notificationRowsForThisRun();
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        } finally {
            $contract->removeEverythingItWrote();
        }

        return [
            'failures' => $contract->failures,
            'facts' => $contract->facts,
            'queue_rows_added' => $added,
        ];
    }

    // -----------------------------------------------------------------------
    // The contract
    // -----------------------------------------------------------------------

    private function seed(): void
    {
        $this->queueRowsAtStart = $this->ports->database->count(Query::from('notification_queue'));

        // A rule this section left behind, from a run that was killed between
        // the create and the finally. automation_rules is unique on
        // (rule_key, version), so one stranded row would make every later run
        // of this section fail at the create for ever. It is removed by the
        // marker key and never by a pattern, so nobody else's rule is touched.
        $this->removeAnyStrandedRule();

        $this->facts[] = 'notification_queue held ' . $this->queueRowsAtStart . ' row(s) before this section';
        $this->facts[] = 'occurrence ' . $this->occurrenceKey;
    }

    private function removeAnyStrandedRule(): void
    {
        try {
            $stranded = $this->ports->database->selectAll(
                Query::from('automation_rules')->select(['id'])->where('rule_key', '=', self::MARKER),
            );
        } catch (\Throwable) {
            return;
        }

        foreach ($stranded as $row) {
            $this->ruleId = (int) ($row['id'] ?? 0);

            if ($this->ruleId > 0) {
                $this->removeEverythingItWrote();
                $this->facts[] = 'removed a rule left behind by an earlier run of this section';
            }
        }

        $this->ruleId = 0;
    }

    /**
     * Authoring and arming are two acts, and the second one names a person.
     *
     * A rule arrives disabled and unarmed however it was authored, because
     * "who authorised this to act on people" is a column rather than a JSON
     * path in an audit, and a rule that armed itself on save would have no
     * moment to record.
     */
    private function checkARuleWithANotifyActionAuthorsAndArms(): void
    {
        $created = $this->call('POST', '/manage/automation-rules', [
            'rule_key' => self::MARKER,
            'title' => 'Automation contract',
            'trigger_key' => self::TRIGGER,
            'subject_binding' => 'learner',
            'actions' => [[
                'action_key' => NotifyAction::KEY,
                'params' => [
                    'template_key' => self::TRIGGER,
                    'category_key' => 'account',
                    'channel_key' => 'email',
                    'class' => 'optional',
                ],
                'params_version' => 1,
            ]],
        ], 201);

        $this->ruleId = (int) ($created['id'] ?? 0);

        // armed_by is compared with ?? null and never ?? 0: the key is present
        // and null, and a coalesce to a non-null default would answer that
        // default for exactly the value being asserted.
        $this->expect(
            $this->ruleId > 0
            && (int) ($created['version'] ?? 0) === 1
            && ($created['active'] ?? true) === false
            && (string) ($created['state'] ?? '') === 'disabled'
            && ($created['armed_by'] ?? null) === null,
            'a rule is created at version one, disabled and unarmed',
            self::describe($created),
        );

        $armed = $this->call('POST', '/manage/automation-rules/' . $this->ruleId . '/arm', [
            'expected_version' => 1,
            'acknowledgement' => 'this_rule_may_act_on_people',
        ], 200);

        $this->expect(
            (int) ($armed['armed_by'] ?? 0) === $this->actor->userId
            && \is_string($armed['armed_digest'] ?? null)
            && ($armed['armed_digest'] ?? '') !== '',
            'arming records the person and a digest over the ordered action set',
            self::describe($armed),
        );
    }

    /**
     * A rehearsal costs the subject nothing.
     *
     * One code path serves the preview and the real run, so `dry_run` is part
     * of three keys rather than a column beside them. What that has to mean on
     * a real site is this: not one notification queue row, and no run row that
     * occupies the re-entry guard the real trigger is about to need.
     */
    private function checkADryRunWritesNothingButItsOwnRows(): void
    {
        if ($this->ruleId < 1) {
            return;
        }

        $before = $this->ports->database->count(Query::from('notification_queue'));

        $preview = $this->call('POST', '/manage/automation-rules/' . $this->ruleId . '/preview', [
            'subject_ids' => [self::SUBJECT],
            'subject_type' => 'user',
        ], 200);

        $this->expect(
            ($preview['dry_run'] ?? false) === true,
            'a rehearsal reports itself as a dry run',
            self::describe($preview),
        );

        $this->expect(
            $this->ports->database->count(Query::from('notification_queue')) === $before,
            'a rehearsal writes no notification queue row',
            'queue was ' . $before . ' and is now '
                . $this->ports->database->count(Query::from('notification_queue')),
        );

        $this->expect(
            $this->runRows(true) > 0 && $this->runRows(false) === 0,
            'a rehearsal writes a dry run row and never a real one',
            'dry ' . $this->runRows(true) . ', real ' . $this->runRows(false),
        );
    }

    /**
     * The real run, on the path the host's own clock takes.
     *
     * Opening the run is the sweep's own call, made here with an occurrence
     * this contract owns rather than one that depends on a learner's
     * entitlement happening to expire this week. Everything after it is the
     * two scheduled tasks the drain runs: the fan-out writes the steps and the
     * step machine executes them, both through the host's driver.
     */
    private function checkTheRealRunProducesExactlyOneQueueRow(): void
    {
        if ($this->ruleId < 1) {
            return;
        }

        $before = $this->ports->database->count(Query::from('notification_queue'));
        $runId = $this->open();

        $this->expect(
            $runId !== null && $runId > 0,
            'the trigger opens a real run for the armed rule',
            'run ' . self::describe($runId),
        );

        $this->drain();

        $mine = $this->notificationRowsForThisRun();

        $this->expect(
            $mine === 1,
            'one armed rule, one occurrence, exactly one notification queue row',
            $mine . ' row(s) carry occurrence ' . $this->occurrenceKey,
        );

        $this->expect(
            $this->ports->database->count(Query::from('notification_queue')) === $before + 1,
            'the run added exactly one row to the queue as a whole, so nothing else was touched',
            'queue was ' . $before . ' and is now '
                . $this->ports->database->count(Query::from('notification_queue')),
        );

        $this->expect(
            $this->actionLogRows() >= 1,
            'the step is recorded in the action log with the identity it ran under',
            $this->actionLogRows() . ' action log row(s)',
        );
    }

    /**
     * A replay produces nothing, twice over.
     *
     * The re-entry guard refuses a second run for the same rule, subject and
     * occurrence, and the step's idempotency claim refuses a second effect for
     * the same step. Both are asserted, because either one alone would let the
     * other rot: a guard with no claim behind it sends twice the day a run is
     * retried, and a claim with no guard behind it writes a run row per
     * repetition until the table is the log.
     */
    private function checkAReplayProducesNone(): void
    {
        if ($this->ruleId < 1) {
            return;
        }

        $before = $this->ports->database->count(Query::from('notification_queue'));
        $replayed = $this->open();

        $this->expect(
            $replayed === null,
            'the same occurrence does not open a second run',
            'run ' . self::describe($replayed),
        );

        $this->drain();

        $this->expect(
            $this->notificationRowsForThisRun() === 1
            && $this->ports->database->count(Query::from('notification_queue')) === $before,
            'a replay produces no second notification',
            $this->notificationRowsForThisRun() . ' row(s) for this occurrence, queue at '
                . $this->ports->database->count(Query::from('notification_queue')),
        );
    }

    /**
     * Disarming stops the rule and cancels what it has queued.
     *
     * What already executed is history and stays with its action log; what is
     * still pending is cancelled, because a rule an operator has just switched
     * off must not keep acting on people from a backlog.
     */
    private function checkDisarmingClearsTheArmingAndCancelsTheQueue(): void
    {
        if ($this->ruleId < 1) {
            return;
        }

        $disarmed = $this->call('POST', '/manage/automation-rules/' . $this->ruleId . '/disarm', [
            'expected_version' => 1,
        ], 200);

        // Disabled and unarmed, and the version stays the active one. Those are
        // two different words: "active" names which of the numbered versions a
        // new run would execute, and disarming does not retire a version, it
        // stops the rule firing at all. A run already executing keeps its own
        // version either way.
        $this->expect(
            ($disarmed['armed_by'] ?? null) === null
            && ($disarmed['armed_at'] ?? null) === null
            && ($disarmed['armed_digest'] ?? null) === null
            && (string) ($disarmed['state'] ?? '') === 'disabled',
            'disarming clears the arming triple and disables the rule',
            self::describe($disarmed),
        );

        $pending = $this->ports->database->count(
            Query::from('automation_queue')
                ->where('rule_id', '=', $this->ruleId)
                ->where('state', '=', 'pending'),
        );

        $this->expect(
            $pending === 0,
            'a disarmed rule has nothing left pending in the automation queue',
            $pending . ' pending step(s)',
        );

        $this->expect(
            $this->actionLogRows() >= 1,
            'what already executed is still in the action log after the disarm',
            $this->actionLogRows() . ' action log row(s)',
        );
    }

    // -----------------------------------------------------------------------
    // Reads, calls and cleanup
    // -----------------------------------------------------------------------

    /**
     * Opens a run the way RunAutomationSweep opens one.
     *
     * The occurrence is this contract's own so the section does not depend on
     * a learner's entitlement expiring this week, and the call underneath is
     * the shipped one rather than a second path written for a harness.
     */
    private function open(): ?int
    {
        $rule = $this->kernel->automationRules->find($this->ruleId);

        if ($rule === null) {
            $this->failures[] = 'the rule this contract authored could not be read back';

            return null;
        }

        return $this->kernel->openAutomationRuns->forRule(
            $rule,
            self::TRIGGER,
            TriggerOccurrence::forSweep(
                self::TRIGGER,
                self::SUBJECT,
                $this->occurrenceKey,
                'user',
                self::SUBJECT,
            ),
        );
    }

    /** The two scheduled tasks a host's own queue drain runs, in their order. */
    private function drain(): void
    {
        $this->kernel->runScheduledTask(AutomationFanoutTask::TYPE, 0, [], $this->actor);
        $this->kernel->runScheduledTask(AutomationStepTask::TYPE, 0, [], $this->actor);
    }

    private function runRows(bool $dryRun): int
    {
        return $this->ports->database->count(
            Query::from('automation_runs')
                ->where('rule_id', '=', $this->ruleId)
                ->where('dry_run', '=', $dryRun ? 1 : 0),
        );
    }

    private function actionLogRows(): int
    {
        return $this->ports->database->count(
            Query::from('automation_action_log')->where('rule_id', '=', $this->ruleId),
        );
    }

    /** Only the rows this run produced, never the site's own backlog. */
    private function notificationRowsForThisRun(): int
    {
        return $this->ports->database->count(
            Query::from('notification_queue')->where('occurrence_key', '=', $this->occurrenceKey),
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body, int $expected): array
    {
        $requestKey = self::MARKER . '-' . bin2hex(random_bytes(8));
        $this->requestKeys[] = $requestKey;

        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            $this->actor,
            [],
            $body,
            $requestKey,
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
     * Everything, including the one piece of mail.
     *
     * The queue row this section produced is deleted here rather than left for
     * a send task, because a harness that mails a fixture address once a run
     * is a harness somebody eventually stops running, and because the address
     * belongs to nobody.
     */
    private function removeEverythingItWrote(): void
    {
        $database = $this->ports->database;

        // Every occurrence this rule ever ran under, and not only this run's:
        // a run killed between the create and this finally leaves both a rule
        // and the mail it queued, and the second is the one that would go out.
        foreach ($this->occurrenceKeysOfThisRule() as $key) {
            try {
                $database->delete('notification_queue', ['occurrence_key' => $key]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove the queued notification: ' . $failure->getMessage();
            }
        }

        // The route ledger's own claims, one per write request this contract
        // put on the wire. Removed outside the rule branch because a run that
        // failed at the create still made the request that claimed the first
        // one, and removed by key alone because the scope names the operation
        // and the caller and this contract is not the authority on either.
        foreach ($this->requestKeys as $requestKey) {
            try {
                $database->delete('idempotency_keys', ['idem_key' => $requestKey]);
            } catch (\Throwable) {
                // A claim already pruned is not a leak.
            }
        }

        $this->requestKeys = [];

        if ($this->ruleId > 0) {
            foreach ($this->idempotencyKeysTaken() as $key) {
                try {
                    $database->delete('idempotency_keys', [
                        'scope' => AutomationActionContext::IDEMPOTENCY_SCOPE,
                        'idem_key' => $key,
                    ]);
                } catch (\Throwable) {
                    // A claim already pruned is not a leak.
                }
            }

            foreach (
                ['automation_action_log', 'automation_queue', 'automation_runs',
                'automation_rule_actions', 'automation_rule_tests', 'automation_rule_versions'] as $table
            ) {
                try {
                    $database->delete($table, ['rule_id' => $this->ruleId]);
                } catch (\Throwable) {
                    // A table this build does not carry is not a leak.
                }
            }

            try {
                $database->delete('automation_rules', ['id' => $this->ruleId]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove the rule: ' . $failure->getMessage();
            }
        }
    }

    /**
     * @return list<string>
     */
    private function occurrenceKeysOfThisRule(): array
    {
        $keys = $this->occurrenceKey === '' ? [] : [$this->occurrenceKey];

        if ($this->ruleId < 1) {
            return $keys;
        }

        try {
            $rows = $this->ports->database->selectAll(
                Query::from('automation_runs')->select(['occurrence_key'])->where('rule_id', '=', $this->ruleId),
            );
        } catch (\Throwable) {
            return $keys;
        }

        foreach ($rows as $row) {
            $key = (string) ($row['occurrence_key'] ?? '');

            if ($key !== '' && !\in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The claims this run took, read off its own queue rows before they go.
     *
     * Read rather than recomputed: the digest is the core's composition and a
     * harness that rebuilt it would be a second copy of it, wrong the day the
     * composition gains a component.
     *
     * @return list<string>
     */
    private function idempotencyKeysTaken(): array
    {
        $keys = [];

        try {
            $rows = $this->ports->database->selectAll(
                Query::from('automation_queue')->select(['idem_key'])->where('rule_id', '=', $this->ruleId),
            );
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $key = (string) ($row['idem_key'] ?? '');

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * A value as evidence, never false.
     *
     * json_encode answers false on a value it cannot encode, and evidence a
     * caller cannot read is worse than none: the one place this is reached is
     * a failure report somebody is about to debug from.
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
