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
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\HostGroupIssuer;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IssuerStatus;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\UnverifiedDomains;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Provider\Identity\HostGroupIdentityMapper;
use Shondalai\VidyaLMS\Core\Registry\Context\IdentityAssertionContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;

/**
 * R5's identity, rostering and governance surface, driven against a real site
 * and a real database.
 *
 * One body of assertions, two callers, exactly as OrganisationSeatContract and
 * OrganisationImportContract are run: each free adapter's build/smoke.php runs
 * it against its own ports. That matters more here than anywhere else in the
 * product, because almost everything this wave added is a row written on one
 * side of a tenancy predicate and read back on the other, through two different
 * drivers, and three of the columns are JSON that one driver hands back as a
 * string and the other as something else.
 *
 * What it proves that a unit test cannot:
 *
 *   - a connection and a domain are recorded and shown unverified on a free
 *     build, with no verifier installed and nothing pretending otherwise;
 *   - the free host-group dialect maps a real group list into the canonical
 *     shape, the mapping rule evaluator grants from it, and the membership
 *     event carries the decision reference the explainer is found by;
 *   - a OneRoster package with a manifest, a bulk file and a delta file loads
 *     through the shipped route, a tobedeleted row closes a membership and an
 *     absent row leaves one exactly as it was;
 *   - the three free organisation reports answer under the organisation's own
 *     path and the field disclosure policy omits the address in every one;
 *   - a compliance requirement stores with no rule registered, and the registry
 *     says so rather than implying behaviour the tables do not have;
 *   - an organisation may lengthen the access log floor and never shorten it,
 *     and the refusal is recorded on the event stream before it is thrown;
 *   - the privacy catalogue and the erasure receipt name a retention basis for
 *     every record class the erasure kept.
 *
 * **It seeds its own organisation, its own files and its own fixture people,
 * and removes every row it wrote.** The removal runs in a finally, so a failed
 * assertion still leaves the site as it was found. It creates no table and
 * drops none, and it never names a Community Quiz table.
 *
 * Three things it cannot remove and does not try to: admin_access_log,
 * seat_events and org_membership_events are append only by design. The rows it
 * appends to the first are counted and reported; the organisation-scoped rows
 * of the third go with the organisation, because they carry its id and the
 * organisation itself is removed.
 */
final class OrganisationIdentityContract
{
    /** Everything this contract creates carries it, so cleanup can be exact. */
    public const MARKER = 'vidyalms-identity-contract';

    /** A compliance rule key no provider on any build claims. */
    private const ABSENT_RULE = 'vidyalms-contract-no-such-compliance-rule';

    /** The package name the free build records its own providers under. */
    private const FREE_PACKAGE = 'vidyalms-core';

    /** The host group this contract's fixture person is asserted to be in. */
    private const GROUP = '8';

    /** The person the roster and the assertion both describe. */
    private const SUBJECT = 700100;

    /** The person a roster file names once and then never mentions again. */
    private const UNMENTIONED = 700101;

    /** The person a roster tombstone closes. */
    private const TOMBSTONED = 700102;

    private const SOURCE_FOLDER = 'imports';

    /** @var list<string> */
    private array $facts = [];

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $sources = [];

    /** @var list<int> */
    private array $jobIds = [];

    private int $organisationId = 0;

    private int $connectionId = 0;

    private int $accessLogAtStart = 0;

    private int $accessLogAtEnd = 0;

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
        private readonly Principal $actor,
    ) {
    }

    /**
     * @return array{failures: list<string>, facts: list<string>, access_log_rows_added: int}
     */
    public static function run(CoreKernel $kernel, Ports $ports, Principal $actor): array
    {
        $contract = new self($kernel, $ports, $actor);

        try {
            $contract->seed();
            $contract->checkAConnectionAndADomainAreRecordedUnverified();
            $contract->checkTheEvaluatorGrantsAndTheDecisionIsRecorded();
            $contract->checkAOneRosterPackageClosesATombstoneAndLeavesAnAbsentRow();
            $contract->checkTheThreeReportsHonourTheDenyList();
            $contract->checkAComplianceRequirementStoresWithNoRuleRegistered();
            $contract->checkTheRetentionFloorMayBeLengthenedAndNeverShortened();
            $contract->checkTheErasureReceiptNamesABasisForEveryKeptRecordClass();
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        } finally {
            $contract->removeEverythingItWrote();
        }

        return [
            'failures' => $contract->failures,
            'facts' => $contract->facts,
            'access_log_rows_added' => max(0, $contract->accessLogAtEnd - $contract->accessLogAtStart),
        ];
    }

    // -----------------------------------------------------------------------
    // The contract
    // -----------------------------------------------------------------------

    private function seed(): void
    {
        $this->accessLogAtStart = $this->accessLogRows();

        $created = $this->call('POST', '/manage/organisations', [
            'name' => 'Identity contract',
            'slug' => self::MARKER,
        ], 201);
        $this->organisationId = (int) ($created['id'] ?? 0);

        $this->facts[] = 'organisation ' . $this->organisationId;
    }

    /**
     * A claim is free; being believed is not.
     *
     * Both rows are recorded and both are shown unverified, and on a free build
     * nothing can move either one: no domain verification provider is
     * installed, so the domain answers that it is unverifiable rather than
     * offering a button that always fails.
     */
    private function checkAConnectionAndADomainAreRecordedUnverified(): void
    {
        $connection = $this->call('POST', '/manage/organisations/' . $this->organisationId . '/connections', [
            'issuer' => HostGroupIssuer::forOrganisation($this->organisationId),
            'dialect' => HostGroupIdentityMapper::KEY,
        ], 201);
        $this->connectionId = (int) ($connection['id'] ?? 0);

        $this->expect(
            (string) ($connection['issuer_status'] ?? '') === IssuerStatus::PENDING,
            'a new connection is recorded pending, never verified',
            'issuer_status ' . (string) ($connection['issuer_status'] ?? 'null'),
        );
        $this->expect(
            ($connection['drives_provisioning'] ?? true) === false
            && ($connection['may_remove'] ?? true) === false,
            'an unverified connection drives nothing and may remove nothing',
            self::describe($connection),
        );

        $domain = $this->call('POST', '/manage/organisations/' . $this->organisationId . '/domains', [
            'domain' => self::MARKER . '.example.test',
        ], 201);

        $this->expect(
            (string) ($domain['status'] ?? '') !== 'verified',
            'a claimed domain is recorded unverified',
            'status ' . (string) ($domain['status'] ?? 'null'),
        );
        // Whether a claim can be proved is a fact about the port this site
        // has wired, and ManageOrganisationJoining::canVerify() answers it by
        // probing exactly this default. Asserting the constant false instead
        // asserted "no paid package is installed", which is not what the
        // sentence says and is false on any site that has Pro attached; the
        // free harness runs on such a site the moment somebody installs the
        // paid package beside it. Comparing against the wired port is the
        // stronger statement in both editions: the screen and the port may
        // never disagree.
        // The kernel's own bundle and never the one on the container. Each
        // host seals a second Ports from a PortsReady collector one step
        // before the kernel is built, which is where a paid package seats a
        // verifier, so the container bundle still holds the refusing default
        // on a site whose routes verify domains. Probing the container copy
        // would compare the route's answer against a port the route does not
        // use.
        $wired = $this->kernel->ports()->domainVerification;
        $canVerify = !$wired instanceof UnverifiedDomains;

        $this->expect(
            ($domain['verifiable'] ?? null) === $canVerify,
            $canVerify
                ? 'the domain says it can be verified exactly when a verification provider is wired'
                : 'a free build says the domain cannot be verified rather than offering a button that always fails',
            self::describe($domain) . ' against ' . $wired::class,
        );

        $listed = $this->call('GET', '/manage/organisations/' . $this->organisationId . '/connections', [], 200);
        $this->expect(
            $this->listHas($listed, $this->connectionId),
            'the connection reads back under the organisation own path',
            self::describe($listed),
        );
    }

    /**
     * The free dialect, the evaluator and the explainer, end to end.
     *
     * The group list is the shape an adapter's host-group source hands over,
     * and everything after it is the shipped code: the mapper turns it into the
     * canonical bag, the connection's own stored rules decide what it grants,
     * and the decision reference is written onto the membership event so the
     * question "why did this person have access on that date" has something to
     * point at.
     */
    private function checkTheEvaluatorGrantsAndTheDecisionIsRecorded(): void
    {
        if ($this->connectionId < 1) {
            return;
        }

        $this->call('PATCH', '/manage/organisations/' . $this->organisationId
            . '/connections/' . $this->connectionId, [
                'expected_revision' => $this->revision(),
                'mapping_rules' => [[
                    'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => self::GROUP, 'value_kind' => 'id'],
                    'then' => ['org_role' => 'org_member'],
                    'stop' => true,
                ]],
            ], 200);

        // Only a site-scoped principal decides whether a claim is believed.
        $this->kernel->organisationConnections->decide(
            $this->actor,
            $this->organisationId,
            $this->connectionId,
            ['issuer_status' => IssuerStatus::VERIFIED],
        );

        $scope = $this->kernel->organisationAccess->require($this->actor, $this->organisationId, 'read');
        $connection = (new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore(
            $this->ports->database,
            $this->ports->clock,
        ))->connection($scope, $this->connectionId);

        if ($connection === null) {
            $this->failures[] = 'the verified connection could not be read back';

            return;
        }

        $assertion = $this->hostGroupAssertion();

        if ($assertion === null) {
            $this->failures[] = 'the free host-group dialect refused a host group list';

            return;
        }

        $this->expect(
            $assertion->issuer === HostGroupIssuer::forOrganisation($this->organisationId)
            && $assertion->isComplete(),
            'the host-group dialect mints the issuer per organisation and calls itself complete',
            $assertion->issuer . ', completeness ' . $assertion->completeness,
        );

        $outcome = $this->kernel->applyProvisioningAssertion->execute(
            $scope,
            $connection,
            $assertion,
            'manual',
            self::SUBJECT,
        );

        $this->expect(
            $outcome->applied,
            'the evaluator granted a role from the host group',
            'reason ' . (string) $outcome->reason,
        );
        $this->expect(
            \is_string($outcome->decisionRef) && $outcome->decisionRef !== '',
            'the decision has a reference the event stream can point at',
            (string) $outcome->decisionRef,
        );

        $event = $this->ports->database->selectOne(
            Query::from('org_membership_events')
                ->where('organisation_id', '=', $this->organisationId)
                ->whereNotNull('decision_ref')
                ->orderBy('id', 'desc'),
        );

        $this->expect(
            $event !== null && (string) ($event['source'] ?? '') === 'directory',
            'the membership event records the directory as the source and carries the decision reference',
            $event === null ? 'no event' : json_encode($event['source'] ?? null) . ' ' . (string) $event['decision_ref'],
        );
    }

    /**
     * A OneRoster package with a manifest, a bulk file and a delta file.
     *
     * Two loads: the bulk one names three people, the delta one tombstones one
     * of them and never mentions another. Deletion is never inferred from
     * absence, so the unmentioned membership must be exactly as it was and the
     * tombstoned one must be closed.
     */
    private function checkAOneRosterPackageClosesATombstoneAndLeavesAnAbsentRow(): void
    {
        // Which host account each roster person lands on is decided by
        // RosterImporter::loadPeople through findByEmail, and never by the
        // identifier column: a person this site has no account for is skipped
        // with a referential miss. Reading the ids back by the same email the
        // package carries is therefore the only way these assertions can be
        // about the rows the import actually wrote. Asserting on the synthetic
        // identifiers instead made both of them vacuous on any real host,
        // because no site has a user 700101.
        $unmentionedId = $this->rosterAccountId('grace');
        $tombstonedId  = $this->rosterAccountId('alan');

        $this->expect(
            $unmentionedId > 0 && $tombstonedId > 0,
            'this site has a host account for each person the roster package names',
            'grace ' . $unmentionedId . ', alan ' . $tombstonedId
                . ($unmentionedId > 0 && $tombstonedId > 0
                    ? ''
                    : '; the caller must seed them, or the importer skips every row as a referential miss'),
        );

        $bulk = $this->store('roster-bulk.zip', self::bulkPackage());
        $this->runRoster($bulk);

        $before = $this->activeMemberships();

        $this->expect(
            $before >= 2,
            'the bulk load created the memberships the delta will be judged against',
            $before . ' active membership(s)',
        );

        $delta = $this->store('roster-delta.zip', self::deltaPackage());
        $this->runRoster($delta);

        $tombstoned = $tombstonedId > 0 ? $this->membershipState($tombstonedId) : null;
        $unmentioned = $unmentionedId > 0 ? $this->membershipState($unmentionedId) : null;

        $this->expect(
            $tombstoned !== 'active',
            'a tombstone closes the membership it names',
            'state ' . (string) $tombstoned,
        );
        $this->expect(
            $unmentioned === 'active',
            'a membership the delta never mentions is left exactly as it was',
            'state ' . (string) $unmentioned,
        );
    }

    /**
     * The three free organisation reports, and the one field none of them
     * discloses.
     *
     * The deny list is applied by one pipeline stage, so it holds on screen and
     * in an export alike. An address is the field the free policy omits, and it
     * is omitted from the column list as well as from the rows: a column header
     * with no values under it is a disclosure of its own.
     */
    private function checkTheThreeReportsHonourTheDenyList(): void
    {
        foreach (['org-overview', 'org-roster', 'org-access-log'] as $key) {
            $report = $this->call(
                'GET',
                '/manage/organisations/' . $this->organisationId . '/reports/' . $key,
                [],
                200,
            );

            $this->expect(
                $report !== [],
                'the ' . $key . ' report answers under the organisation own path',
                (string) json_encode($report),
            );

            $columns = array_map(
                static fn (mixed $column): string => \is_array($column) ? (string) ($column['key'] ?? '') : '',
                \is_array($report['columns'] ?? null) ? $report['columns'] : [],
            );

            $this->expect(
                !\in_array('email', $columns, true),
                'the ' . $key . ' report declares no address column',
                implode(', ', $columns),
            );

            $disclosed = [];

            foreach (\is_array($report['items'] ?? null) ? $report['items'] : [] as $item) {
                if (\is_array($item) && \array_key_exists('email', $item)) {
                    $disclosed[] = (string) ($item['user_id'] ?? '?');
                }
            }

            $this->expect(
                $disclosed === [],
                'the ' . $key . ' report discloses no address in a row',
                implode(', ', $disclosed),
            );
        }

        // The roster is the one of the three that holds an address column and
        // never shows it, which is what makes the disclosure stage a running
        // stage rather than a sentence.
        $roster = $this->call(
            'GET',
            '/manage/organisations/' . $this->organisationId . '/reports/org-roster',
            [],
            200,
        );
        $omitted = \is_array($roster['omitted_fields'] ?? null) ? $roster['omitted_fields'] : [];

        $this->expect(
            \in_array('email', $omitted, true),
            'the roster names the address as omitted rather than dropping it silently',
            implode(', ', array_map('strval', $omitted)),
        );
    }

    /**
     * Compliance ships as tables with no rule registered, and says so.
     *
     * The tables imply no behaviour: a Pro or Business rule is a registration
     * into the seam rather than a migration, and until one exists a miss is
     * never reported as compliant.
     */
    private function checkAComplianceRequirementStoresWithNoRuleRegistered(): void
    {
        // The free build registers nothing into this seam, which is what the
        // sentence is about. Asserting the empty list unconditionally turned
        // it into "no paid package is installed", so the section failed on
        // every site that has Pro attached beside the free build, which is
        // the site this harness most often runs on. The claim is kept exactly
        // as it was where it belongs (a build with no paid package), and the
        // published miss direction is asserted in both editions with a key no
        // provider on this site claims, so neither branch can go vacuous.
        $registry = $this->kernel->registries->complianceRules;
        $registered = $registry->keys();
        $paid = $this->paidPackages();

        $this->expect(
            !$registry->has(self::ABSENT_RULE) && ($paid !== [] || $registered === []),
            $paid === []
                ? 'no compliance rule is registered on a free build'
                : 'every compliance rule here comes from a paid package, and a key nothing claims still misses',
            (implode(', ', $registered) ?: 'none') . ' with ' . (implode(', ', $paid) ?: 'no paid package'),
        );

        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');
        $this->ports->database->insert('compliance_requirements', [
            'organisation_id' => $this->organisationId,
            'scope_type' => 'organisation',
            'scope_id' => $this->organisationId,
            'title' => 'Identity contract requirement',
            'requirement' => 'mandatory',
            'rule_key' => 'none',
            'created_by' => max(0, $this->actor->userId),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $stored = $this->ports->database->count(
            Query::from('compliance_requirements')->where('organisation_id', '=', $this->organisationId),
        );

        $this->expect(
            $stored === 1,
            'a compliance requirement stores on the organisation with no rule behind it',
            $stored . ' row(s)',
        );
    }

    /**
     * The audited party does not set the audit log's retention.
     *
     * An organisation may keep the access log longer than the site floor and
     * may never keep it for less, and the refused attempt is itself recorded:
     * an owner shortening their own audit trail is exactly the event the trail
     * exists to hold.
     */
    private function checkTheRetentionFloorMayBeLengthenedAndNeverShortened(): void
    {
        $floor = $this->kernel->organisationPrivacy->accessLogFloorDays();
        $path = '/manage/organisations/' . $this->organisationId . '/retention-policy';

        $longer = $this->call('PUT', $path, [
            'expected_revision' => $this->revision(),
            'retention_policy' => [
                'access_log_days' => $floor + 365,
                'basis' => RetentionBasis::LEGAL_OBLIGATION,
            ],
        ], 200);

        $this->expect(
            (int) ($longer['retention_policy']['access_log_days'] ?? 0) === $floor + 365,
            'an organisation may keep its access log longer than the site floor',
            self::describe($longer['retention_policy'] ?? null),
        );

        $refused = $this->status('PUT', $path, [
            'expected_revision' => $this->revision(),
            'retention_policy' => ['access_log_days' => max(1, $floor - 1)],
        ]);

        $this->expect(
            $refused === 422,
            'and may never keep it for less',
            'status ' . $refused,
        );

        $recorded = $this->ports->database->count(
            Query::from('org_membership_events')
                ->where('organisation_id', '=', $this->organisationId)
                ->where('event_key', '=', 'retention_policy_refused'),
        );

        $this->expect(
            $recorded === 1,
            'the refused attempt is recorded before it is thrown',
            $recorded . ' event row(s)',
        );
    }

    /**
     * The catalogue and the receipt, and the one thing that makes a receipt a
     * receipt.
     *
     * Every record class the erasure kept names the basis it was kept under.
     * A kept record with no basis is a retention nobody can justify, which is
     * the state the previous warning string described in prose and this
     * replaces with a per record class answer.
     */
    private function checkTheErasureReceiptNamesABasisForEveryKeptRecordClass(): void
    {
        $catalogue = $this->call('GET', '/manage/organisations/' . $this->organisationId . '/privacy', [], 200);
        $keys = array_column(\is_array($catalogue['items'] ?? null) ? $catalogue['items'] : [], 'key');

        $this->expect(
            \in_array('core-organisations', $keys, true) && \in_array('core-learning', $keys, true),
            'the privacy catalogue is built from the contributor registry',
            implode(', ', array_map('strval', $keys)),
        );

        $memberId = $this->activeMemberId(self::SUBJECT);

        if ($memberId < 1) {
            $this->facts[] = 'ok: no erasable member remained, so the receipt was not exercised';

            return;
        }

        $receipt = $this->call(
            'POST',
            '/manage/organisations/' . $this->organisationId . '/members/' . $memberId . '/erase',
            [
                'expected_revision' => $this->revision(),
                'acknowledgement' => 'remove_organization_relationship',
            ],
            200,
        );

        $receipts = \is_array($receipt['receipts'] ?? null) ? $receipt['receipts'] : [];
        $unjustified = [];

        foreach ($receipts as $contributor => $one) {
            if (!\is_array($one)) {
                continue;
            }

            $kept = array_keys(
                (\is_array($one['retained'] ?? null) ? $one['retained'] : [])
                + (\is_array($one['pseudonymised'] ?? null) ? $one['pseudonymised'] : []),
            );
            $bases = \is_array($one['retention_bases'] ?? null) ? $one['retention_bases'] : [];

            foreach ($kept as $recordClass) {
                $basis = $bases[$recordClass]['basis'] ?? RetentionBasis::NONE;

                if (!\is_string($basis) || $basis === RetentionBasis::NONE) {
                    $unjustified[] = (string) $contributor . '.' . (string) $recordClass;
                }
            }
        }

        $this->expect(
            $receipts !== [],
            'the erasure answers a receipt per contributor',
            self::describe(array_keys($receipts)),
        );
        $this->expect(
            $unjustified === [],
            'every record class the erasure kept names the basis it was kept under',
            implode(', ', $unjustified),
        );
    }

    // -----------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------

    private function hostGroupAssertion(): ?AssertedIdentity
    {
        return (new HostGroupIdentityMapper())->map(IdentityAssertionContext::hostGroups(
            $this->organisationId,
            self::SUBJECT,
            [['key' => self::GROUP, 'label' => 'Managers']],
        ));
    }

    private function runRoster(string $path): void
    {
        $queued = $this->kernel->organisationImports->start(
            $this->actor,
            $this->organisationId,
            'oneroster-csv-1.2:in',
            $path,
            [],
        );
        $this->jobIds[] = $queued->id;
        $job = $this->kernel->runQueuedImport->execute($queued->id);
        // The totals and the log, not the status alone: "completed_with_problems"
        // on its own says a row was refused without saying which or why, and the
        // assertions below then fail on a membership that was never created for a
        // reason this contract already had in its hand.
        $this->facts[] = 'roster ' . $path . ' finished as ' . $job->status
            . ' totals=' . json_encode($job->totals ?? [])
            . ' log=' . substr((string) $job->log, 0, 400);
    }

    private function store(string $name, string $bytes): string
    {
        $path = sprintf('%s/%d/%s-%s', self::SOURCE_FOLDER, max(0, $this->actor->userId), self::MARKER, $name);
        $this->ports->storage->putPrivate($path, $bytes);
        $this->sources[] = $path;

        return $path;
    }

    /** The bulk half: three people, three memberships, nothing closed. */
    private static function bulkPackage(): string
    {
        return self::zip([
            'manifest.csv' => self::manifest(['users' => 'bulk', 'roles' => 'bulk']),
            'users.csv' => self::usersCsv([
                'user-a,,,true,Ada,Byron,,' . self::SUBJECT . ',ada-' . self::MARKER . '@example.test',
                'user-b,,,true,Grace,Hopper,,' . self::UNMENTIONED . ',grace-' . self::MARKER . '@example.test',
                'user-c,,,true,Alan,Turing,,' . self::TOMBSTONED . ',alan-' . self::MARKER . '@example.test',
            ]),
            'roles.csv' => self::rolesCsv([
                'role-a,,,user-a,primary,student,,,org-1',
                'role-b,,,user-b,primary,student,,,org-1',
                'role-c,,,user-c,primary,student,,,org-1',
            ]),
        ]);
    }

    /**
     * The delta half: one tombstone and one silence.
     *
     * A delta file carries a status and a dateLastModified on every row, and
     * "tobedeleted" is the only instruction that closes anything. The person
     * this file never mentions is not evidence about that person.
     */
    private static function deltaPackage(): string
    {
        return self::zip([
            'manifest.csv' => self::manifest(['users' => 'delta', 'roles' => 'delta']),
            'users.csv' => self::usersCsv([
                'user-c,tobedeleted,2026-03-01T09:00:00.000Z,true,Alan,Turing,,'
                . self::TOMBSTONED . ',alan-' . self::MARKER . '@example.test',
            ]),
            'roles.csv' => self::rolesCsv([
                'role-c,tobedeleted,2026-03-01T09:00:00.000Z,user-c,primary,student,,,org-1',
            ]),
        ]);
    }

    /** @param array<string, string> $modes */
    private static function manifest(array $modes): string
    {
        $lines = ['propertyName,value', 'manifest.version,1.0', 'oneroster.version,1.2'];

        foreach ($modes as $file => $mode) {
            $lines[] = 'file.' . $file . ',' . $mode;
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /** @param list<string> $rows */
    private static function usersCsv(array $rows): string
    {
        return "sourcedId,status,dateLastModified,enabledUser,givenName,familyName,middleName,identifier,email\r\n"
            . implode("\r\n", $rows) . "\r\n";
    }

    /** @param list<string> $rows */
    private static function rolesCsv(array $rows): string
    {
        return "sourcedId,status,dateLastModified,userSourcedId,roleType,role,beginDate,endDate,orgSourcedId\r\n"
            . implode("\r\n", $rows) . "\r\n";
    }

    /**
     * A flat zip of stored entries, which is what a conformant package is.
     *
     * Built here rather than committed as a binary fixture so a reader can see
     * exactly which bytes the reader is being asked to read.
     *
     * @param array<string, string> $files
     */
    private static function zip(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;

        foreach ($files as $name => $contents) {
            $crc = crc32($contents);
            $size = \strlen($contents);
            $header = "\x50\x4b\x03\x04" . pack('vvvvvVVVvv', 20, 0, 0, 0, 0, $crc, $size, $size, \strlen($name), 0)
                . $name;
            $local .= $header . $contents;
            $central .= "\x50\x4b\x01\x02"
                . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 0, 0, 0, $crc, $size, $size, \strlen($name), 0, 0, 0, 0, 0, $offset)
                . $name;
            $offset += \strlen($header) + $size;
        }

        return $local . $central . "\x50\x4b\x05\x06"
            . pack('vvvvVVv', 0, 0, \count($files), \count($files), \strlen($central), $offset, 0);
    }

    // -----------------------------------------------------------------------
    // Reads, calls and cleanup
    // -----------------------------------------------------------------------

    private function revision(): int
    {
        $row = $this->ports->database->selectOne(
            Query::from('organisations')->select(['revision'])->where('id', '=', $this->organisationId),
        );

        return (int) ($row['revision'] ?? 1);
    }

    private function activeMemberships(): int
    {
        return $this->ports->database->count(
            Query::from('org_memberships')
                ->where('organisation_id', '=', $this->organisationId)
                ->where('state', '=', 'active'),
        );
    }

    /**
     * The host account id behind one of the roster package's people, or 0.
     *
     * The email is composed exactly as bulkPackage() and deltaPackage() write
     * it, so the two cannot drift apart.
     */
    private function rosterAccountId(string $given): int
    {
        $account = $this->ports->users->findByEmail($given . '-' . self::MARKER . '@example.test');

        return $account === null ? 0 : (int) $account->id;
    }

    private function membershipState(int $userId): ?string
    {
        $row = $this->ports->database->selectOne(
            Query::from('org_memberships')
                ->where('organisation_id', '=', $this->organisationId)
                ->where('user_id', '=', $userId),
        );

        return $row === null ? null : (string) ($row['state'] ?? '');
    }

    private function activeMemberId(int $userId): int
    {
        $row = $this->ports->database->selectOne(
            Query::from('org_memberships')
                ->select(['id'])
                ->where('organisation_id', '=', $this->organisationId)
                ->where('user_id', '=', $userId),
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * The packages other than the free build that have registered into this
     * kernel, read from the site's own feature-provider ledger.
     *
     * It is the only honest way a shared contract can tell which edition it is
     * running against: the free harness and the paid harness both run against
     * whatever the site has installed, and a claim written as "on a free
     * build" is a claim about the packages present rather than about which
     * script is driving. A ledger that cannot be read answers an empty list,
     * which keeps the stricter free-build branch: a contract that could not
     * tell must not relax anything.
     *
     * @return list<string>
     */
    private function paidPackages(): array
    {
        $packages = [];

        try {
            foreach ($this->kernel->featureProviders->listActive() as $row) {
                if ($row->package !== self::FREE_PACKAGE && !\in_array($row->package, $packages, true)) {
                    $packages[] = $row->package;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $packages;
    }

    /** @param array<string, mixed> $list */
    private function listHas(array $list, int $id): bool
    {
        /** @var list<array<string, mixed>> $items */
        $items = \is_array($list['items'] ?? null) ? $list['items'] : [];

        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body, int $expected): array
    {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            $this->actor,
            [],
            $body,
            self::MARKER . '-' . bin2hex(random_bytes(8)),
        ));

        if ($response->status !== $expected) {
            $this->failures[] = sprintf(
                '%s %s answered %d, expected %d: %s',
                $method,
                $path,
                $response->status,
                $expected,
                json_encode($response->data),
            );

            return [];
        }

        $payload = \is_array($response->data) ? $response->data : [];

        return \is_array($payload['item'] ?? null) ? $payload['item'] : $payload;
    }

    /**
     * A call whose refusal is the answer, so the status is what is returned.
     *
     * @param array<string, mixed> $body
     */
    private function status(string $method, string $path, array $body): int
    {
        return $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            $this->actor,
            [],
            $body,
            self::MARKER . '-' . bin2hex(random_bytes(8)),
        ))->status;
    }

    private function removeEverythingItWrote(): void
    {
        $database = $this->ports->database;

        foreach ($this->jobIds as $jobId) {
            try {
                $database->delete('import_row_problems', ['import_job_id' => $jobId]);
                // The map rows carry the job that wrote them, which is what
                // makes this exact: another organisation's rows under the same
                // importer key are somebody else's and are left alone.
                $database->delete('import_map', ['job_id' => $jobId]);
                $database->delete('import_jobs', ['id' => $jobId]);
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove import job ' . $jobId . ': ' . $failure->getMessage();
            }
        }

        if ($this->organisationId > 0) {
            foreach (
                ['compliance_requirements', 'compliance_assignments', 'assignment_exceptions',
                'org_identity_links', 'org_domains', 'org_join_rules', 'org_connections', 'org_role_assignments',
                'org_membership_events', 'org_memberships', 'org_units', 'organisations'] as $table
            ) {
                try {
                    $database->delete($table, $table === 'organisations'
                        ? ['id' => $this->organisationId]
                        : ['organisation_id' => $this->organisationId]);
                } catch (\Throwable) {
                    // A table this build does not carry is not a leak.
                }
            }
        }

        foreach ($this->sources as $source) {
            try {
                if ($this->ports->storage->exists($source)) {
                    $this->ports->storage->delete($source);
                }
            } catch (\Throwable $failure) {
                $this->failures[] = 'could not remove ' . $source . ': ' . $failure->getMessage();
            }
        }

        $this->accessLogAtEnd = $this->accessLogRows();
    }

    private function accessLogRows(): int
    {
        try {
            return $this->ports->database->count(Query::from('admin_access_log'));
        } catch (\Throwable) {
            return 0;
        }
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
