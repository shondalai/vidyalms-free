<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Provider\Report\EnrolmentRosterReport;
use Shondalai\VidyaLMS\Core\Provider\Report\OrgAccessLogReport;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The first organisation-scoped report, through the real routed kernel.
 *
 * It is the report that exercises every seam this wave builds at once, so the
 * assertions here are the wave's own acceptance criteria rather than one
 * provider's: the organisation is in the path, the scope comes from
 * OrganisationAccess, `report_runs.scope_digest` holds OrgScope::digest()
 * verbatim, the run is keyed on that digest and the actor together, the chain
 * verification is a report fact, and a provider that does not declare
 * HonoursOrgScope is refused rather than trusted to narrow itself.
 */
final class OrganisationReportTest extends TestCase
{
    private const SITE_MANAGER = 1;
    private const ORG_OWNER = 2;
    private const ORG_REPORTER = 3;
    private const OUTSIDER = 4;

    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;
    private int $organisationId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();

        foreach ([self::SITE_MANAGER, self::ORG_OWNER, self::ORG_REPORTER, self::OUTSIDER] as $id) {
            $this->env->users->add($id, 'Synthetic ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(self::SITE_MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::SITE_MANAGER, Capability::VIEW_REPORTS);

        foreach ([self::ORG_OWNER, self::ORG_REPORTER, self::OUTSIDER] as $id) {
            $this->env->authorization->grant($id, Capability::MANAGE_GROUPS);
            $this->env->authorization->grant($id, Capability::VIEW_REPORTS);
        }

        $this->kernel = new CoreKernel($this->env->ports);
        $this->api = $this->kernel->apiKernel();

        $this->organisationId = $this->kernel->organisationStore->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'created_by' => self::SITE_MANAGER,
        ]);

        $siteScope = $this->kernel->organisationAccess->require(
            Principal::user(self::SITE_MANAGER),
            $this->organisationId,
            'people',
        );
        $this->kernel->organisationStore->addMember($siteScope, self::ORG_OWNER, 'org_owner', 'manual', null);
        $this->kernel->organisationStore->addMember($siteScope, self::ORG_REPORTER, 'org_reporter', 'manual', null);
    }

    public function testTheReportIsReadThroughTheOrganisationPathAndCarriesTheChainResult(): void
    {
        $response = $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertTrue($response->data['computed']);
        self::assertSame(OrgAccessLogReport::KEY, $response->data['computed_by']);
        self::assertSame($this->organisationId, $response->data['organisation_id']);
        // The verification result is a report fact, so a reader knows whether
        // the record they are holding was intact when it was taken.
        self::assertArrayHasKey('chain_verified', $response->data);
        self::assertArrayNotHasKey('chain_rows_checked', $response->data);
        self::assertArrayNotHasKey('chain_breaks', $response->data);
        self::assertFalse($response->data['chain_complete']);
        self::assertSame('site_administrator_only', $response->data['chain_anchor']);
        self::assertIsArray($response->data['columns']);
    }

    /**
     * The rows are the tenant's own, and the middleware's own writes are what
     * put them there: reading an organisation route records a row about that
     * read, which is the point of an access log.
     */
    public function testTheRowsAreTheOrganisationsOwn(): void
    {
        // One read to make a row, then a second to see it.
        $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());
        $response = $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());

        self::assertIsArray($response->data);
        self::assertGreaterThan(0, $response->data['total']);

        foreach ($response->data['items'] as $row) {
            self::assertIsArray($row);
            self::assertArrayHasKey('route', $row);
            self::assertArrayHasKey('outcome', $row);
        }
    }

    /**
     * A filter narrows inside the anchor and can never replace it, which is the
     * condition-versus-filter rule applied to the one table where getting it
     * wrong hands one tenant another tenant's audit trail.
     */
    public function testTheOutcomeFilterNarrowsAndTheAnchorStillHolds(): void
    {
        $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());
        $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->outsider());

        $all = $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());
        $allowed = $this->call(
            'GET',
            $this->reportPath(OrgAccessLogReport::KEY),
            $this->reporter(),
            ['outcome' => 'allowed'],
        );

        self::assertIsArray($all->data);
        self::assertIsArray($allowed->data);
        self::assertLessThanOrEqual(\count($all->data['items']), \count($allowed->data['items']));

        foreach ($allowed->data['items'] as $row) {
            self::assertIsArray($row);
            self::assertSame('allowed', $row['outcome']);
        }
    }

    /** A window that excludes every row answers nothing rather than everything. */
    public function testADateWindowInTheFutureExcludesEveryRow(): void
    {
        $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());

        $future = $this->call(
            'GET',
            $this->reportPath(OrgAccessLogReport::KEY),
            $this->reporter(),
            ['from' => '2099-01-01T00:00:00+00:00'],
        );

        self::assertIsArray($future->data);
        self::assertSame([], $future->data['items']);
    }

    /**
     * Small-cell suppression runs over the report's declared count columns
     * without the provider asking, which is the only arrangement in which the
     * eleventh report cannot forget it.
     */
    public function testSuppressionRunsOverTheDeclaredCountColumns(): void
    {
        $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());
        $response = $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());

        self::assertIsArray($response->data);
        self::assertArrayHasKey('suppressed_cells', $response->data);

        $counts = array_column($response->data['columns'], 'type', 'key');

        self::assertSame('count', $counts['record_count'], 'record_count is the cell suppression acts on');
    }

    public function testSomebodyOutsideTheOrganisationIsRefused(): void
    {
        $response = $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->outsider());

        // 404 and never 403: every refusal is the same object with the same
        // message, so a foreign organisation and a missing one read alike. A
        // 403 here would tell a caller the organisation exists.
        self::assertSame(404, $response->status);
    }

    /**
     * A report that does not declare HonoursOrgScope is refused inside an
     * organisation run. `enrolment-roster` declares only FiltersByVisibility,
     * which is an unversioned promise made before organisations existed.
     */
    public function testAProviderThatDoesNotHonourTheOrgScopeIsRefusedRatherThanTrusted(): void
    {
        $response = $this->call('GET', $this->reportPath(EnrolmentRosterReport::KEY), $this->owner());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertFalse($response->data['computed']);
        self::assertNotNull($response->data['reason']);
    }

    public function testTheExportRecordsTheOrgScopeDigestVerbatimAndKeysOnTheActorToo(): void
    {
        $accepted = $this->call(
            'POST',
            $this->reportPath(OrgAccessLogReport::KEY) . '/exports',
            $this->reporter(),
            ['format' => 'csv'],
        );

        self::assertSame(202, $accepted->status);
        self::assertIsArray($accepted->data);
        self::assertSame($this->organisationId, $accepted->data['organisation_id']);

        $run = $this->kernel->reportRuns->find($accepted->data['id']);

        self::assertNotNull($run);

        $scope = $this->kernel->organisationAccess->require(
            Principal::user(self::ORG_REPORTER),
            $this->organisationId,
            'events',
        );

        self::assertSame($scope->digest(), $run->scopeDigest, 'the digest is OrgScope::digest() and not a second recipe');
        self::assertSame(self::ORG_REPORTER, $run->requestedBy);
        // OrgScope::digest() deliberately omits the actor, so the artefact key
        // is the digest AND the actor: two administrators of one organisation
        // share a digest and must not share a per-actor artefact.
        self::assertSame(
            $run->scopeDigest . ':u' . self::ORG_REPORTER,
            ReportRun::artefactKey($run->scopeDigest, $run->requestedBy),
        );
        self::assertNotSame(
            ReportRun::artefactKey($run->scopeDigest, self::ORG_OWNER),
            ReportRun::artefactKey($run->scopeDigest, self::ORG_REPORTER),
        );
    }

    public function testTheExportIsRefusedForSomebodyOutsideTheOrganisation(): void
    {
        $response = $this->call(
            'POST',
            $this->reportPath(OrgAccessLogReport::KEY) . '/exports',
            $this->outsider(),
            ['format' => 'csv'],
        );

        self::assertSame(404, $response->status);
        self::assertNull($this->kernel->reportRuns->find(1), 'a refused export writes no run');
    }

    public function testTheExportedArtefactIsCsvWithTheDeclaredHeader(): void
    {
        // One read first, so the log has a row to export. A run over an empty
        // log is EMPTY with no artefact, which is the state the manifest keeps
        // apart from a failure, and asserting on the bytes needs bytes.
        $this->call('GET', $this->reportPath(OrgAccessLogReport::KEY), $this->reporter());

        $accepted = $this->call(
            'POST',
            $this->reportPath(OrgAccessLogReport::KEY) . '/exports',
            $this->reporter(),
            ['format' => 'csv'],
        );

        self::assertIsArray($accepted->data);
        $run = $this->kernel->reportRuns->find($accepted->data['id']);

        self::assertNotNull($run);
        self::assertNotNull($run->storagePath);

        $csv = $this->env->storage->readPrivate($run->storagePath);

        self::assertStringStartsWith("\xEF\xBB\xBF\"", $csv, 'the BOM and RFC 4180 quoting');
        self::assertStringContainsString("\r\n", $csv, 'CRLF terminators');
    }

    /** @param array<string, string> $query */
    private function call(string $method, string $path, Principal $principal, array $query = []): ApiResponse
    {
        return $this->api->handle(new ApiRequest($method, $path, $principal, $query));
    }

    private function reportPath(string $key): string
    {
        return '/manage/organisations/' . $this->organisationId . '/reports/' . $key;
    }

    private function owner(): Principal
    {
        return Principal::user(self::ORG_OWNER);
    }

    private function reporter(): Principal
    {
        return Principal::user(self::ORG_REPORTER);
    }

    private function outsider(): Principal
    {
        return Principal::user(self::OUTSIDER);
    }
}
