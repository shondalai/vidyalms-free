<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrgRole;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\DuplicateProviderException;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\NamesGoverningSource;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * The seams seated ahead of anything registering into them.
 *
 * The shipped Registries file is touched once per wave on purpose: it is a
 * constructor every adapter and every paid package receives, so adding
 * extension points one at a time is a signature change per point. This test
 * is what stops a later wave quietly dropping one of the fourteen.
 */
final class SeatedRegistriesTest extends TestCase
{
    /**
     * The standards page's count was verified by hand once and held by
     * nothing, so a registry seated tomorrow left the published statement
     * quietly wrong while every gate stayed green. It is derived here instead:
     * the reflected seats are the number, and the sentence has to agree.
     */
    public function testTheStandardsPageCountIsDerivedFromTheSeatsAndNotRemembered(): void
    {
        $seated = self::seatedProperties();
        self::assertGreaterThan(60, \count($seated), 'the reflection found almost nothing, which is the real failure');

        // The standards page lives in the development monorepo's docs, which
        // the public free source does not carry. Keyed on the folder, so a
        // missing page inside the monorepo still fails.
        if (!is_dir(__DIR__ . '/../../../../../docs/technical')) {
            self::markTestSkipped('docs/technical is not part of this checkout.');
        }

        $page = file_get_contents(__DIR__ . '/../../../../../docs/technical/10-coding-standards.md');
        self::assertIsString($page);

        $spelled = self::spelled(\count($seated));
        self::assertStringContainsString(
            'every one of the ' . $spelled . ' extension points',
            $page,
            sprintf(
                'Registries seats %d AbstractProviderRegistry properties; the standards page says otherwise. '
                . 'Update the sentence and add the row, or the published direction is missing for one of them.',
                \count($seated),
            ),
        );
    }

    /**
     * Every seated registry is a real extension point with a provider
     * interface, so a property added as something else cannot be counted as
     * one.
     */
    public function testEverySeatedPropertyIsAProviderRegistryWithAProviderInterface(): void
    {
        $registries = new Registries();

        foreach (self::seatedProperties() as $name) {
            $registry = $registries->{$name};
            self::assertInstanceOf(AbstractProviderRegistry::class, $registry, $name);

            $method = new \ReflectionMethod($registry, 'providerInterface');
            $method->setAccessible(true);
            $interface = $method->invoke($registry);

            self::assertIsString($interface, $name);
            self::assertTrue(interface_exists($interface), $name . ' names ' . $interface);
        }
    }

    /**
     * Every registry property Registries seats, by name and sorted.
     *
     * @return list<string>
     */
    private static function seatedProperties(): array
    {
        $registries = new Registries();
        $names = [];

        foreach ((new \ReflectionClass(Registries::class))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || !$registries->{$property->getName()} instanceof AbstractProviderRegistry) {
                continue;
            }

            $names[] = $property->getName();
        }

        sort($names);

        return $names;
    }

    /** The number as the standards page spells it, which is how prose counts. */
    private static function spelled(int $count): string
    {
        $units = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine'];
        $tens = ['', 'ten', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $teens = ['ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen',
            'eighteen', 'nineteen'];

        if ($count < 10) {
            return $units[$count];
        }

        if ($count < 20) {
            return $teens[$count - 10];
        }

        $ten = $tens[intdiv($count, 10)];
        $unit = $units[$count % 10];

        return $unit === '' ? $ten : $ten . ' ' . $unit;
    }

    public function testTheThreeCredentialSeamsAreSeatedAndEmptyUntilFilled(): void
    {
        $registries = new Registries();

        // Empty here and filled at boot: the constructor seats the seam, and
        // the free providers arrive through the kernel's own provider list,
        // which is what lets a generator register exactly what the kernel
        // registers.
        self::assertSame([], $registries->certificateElements->keys());
        self::assertSame([], $registries->credentialFormats->keys());
        self::assertSame([], $registries->creditSchemes->keys());
    }

    public function testTheElevenAiAndAutomationSeamsAreSeated(): void
    {
        $registries = new Registries();

        foreach (
            [
                $registries->aiRecipes,
                $registries->aiPlacements,
                $registries->aiSurfaces,
                $registries->modelBridges,
                $registries->aiPolicies,
                $registries->aiRedactors,
                $registries->aiFactProviders,
                $registries->aiDraftAcceptors,
                $registries->automationActions,
                $registries->retryPolicies,
                $registries->failurePolicies,
            ] as $registry
        ) {
            self::assertSame([], $registry->keys());
        }
    }

    public function testTheAiTaskContractTakesAContextAndAnswersAResult(): void
    {
        $run = new \ReflectionMethod(AiTaskInterface::class, 'run');

        // The correction is free exactly once, while nothing implements the
        // interface. After the first task ships, the rule the question type
        // interface carries applies verbatim and nothing may ever be added.
        self::assertCount(2, $run->getParameters());
        self::assertSame(
            'Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest',
            (string) $run->getParameters()[0]->getType(),
        );
        self::assertSame(
            'Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext',
            (string) $run->getParameters()[1]->getType(),
        );
        self::assertSame(
            'Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult',
            (string) $run->getReturnType(),
        );
    }

    /**
     * The eighteen organisation seams, named one by one rather than counted.
     *
     * Naming them is the point: a later wave that drops one to avoid widening
     * a constructor fails here with the property in the message, and a wave
     * that adds a nineteenth has to say so out loud. Two of these were bespoke
     * Domain classes outside this object until R1, which is what made every
     * registration the organisations design names impossible.
     */
    public function testTheEighteenOrganisationSeamsAreSeated(): void
    {
        $registries = new Registries();

        foreach (
            [
                'orgRoles' => $registries->orgRoles,
                'orgTypes' => $registries->orgTypes,
                'orgCatalogueSources' => $registries->orgCatalogueSources,
                'orgJoinRules' => $registries->orgJoinRules,
                'orgVisibility' => $registries->orgVisibility,
                'orgMembershipTermination' => $registries->orgMembershipTermination,
                'orgUnitMove' => $registries->orgUnitMove,
                'seatScopes' => $registries->seatScopes,
                'seatReclaim' => $registries->seatReclaim,
                'seatOverAllocation' => $registries->seatOverAllocation,
                'seatExpiryDisposition' => $registries->seatExpiryDisposition,
                'seatEventReasons' => $registries->seatEventReasons,
                'invitationMatch' => $registries->invitationMatch,
                'identityMappers' => $registries->identityMappers,
                'provisioningSources' => $registries->provisioningSources,
                'rosterMappers' => $registries->rosterMappers,
                'complianceRules' => $registries->complianceRules,
                'fieldDisclosure' => $registries->fieldDisclosure,
            ] as $property => $registry
        ) {
            self::assertInstanceOf(AbstractProviderRegistry::class, $registry, $property);
            self::assertSame([], $registry->keys(), $property . ' is seated empty and filled at boot');
        }
    }

    /**
     * The four that ship providers ship exactly these, through the same public
     * seed the kernel calls.
     *
     * The other fourteen stay empty here because their free providers belong
     * to the waves that build their callers, and a free provider nothing calls
     * is the same failure as a seam only the paid build reaches, one step
     * further in.
     */
    public function testTheFreeOrganisationProvidersAreExactlyTheFourFamilies(): void
    {
        $registries = new Registries();
        CoreOrganisationProviders::seed($registries);

        self::assertSame(['org_owner', 'org_admin', 'org_reporter', 'org_member'], $registries->orgRoles->keys());
        self::assertSame(['never', 'always', 'before_start', 'before_completion'], $registries->seatReclaim->keys());
        self::assertSame(['invitation_only'], $registries->orgJoinRules->keys());
        self::assertSame([
            'organisation', 'department', 'team', 'location', 'division',
            'school', 'district', 'local', 'state', 'national', 'company',
        ], $registries->orgTypes->keys());

        // The identity seam is filled, and by exactly one dialect: the host's
        // own groups, which is the only thing either CMS can actually assert.
        // A free provider a free route reaches is the rule every seam here is
        // held to, and this is the identity seam meeting it.
        self::assertSame(['host-group'], $registries->identityMappers->keys());

        // The four free provenances org_memberships.provisioned_by may hold.
        // They register so the column resolves through a registry rather than
        // being a string a screen printed, which is what lets a paid directory
        // be a registration instead of a fifth word in a comment. host_group is
        // the one the free identity path attributes its writes to.
        self::assertSame(
            ['manual', 'invitation', 'csv', 'host_group'],
            $registries->provisioningSources->keys(),
        );

        // None of the four governs a row, so the console's read-only third
        // row shape is reachable and unreached on a stock build, which is the
        // honest state rather than a capability nothing declares.
        foreach ($registries->provisioningSources->all() as $source) {
            self::assertNotInstanceOf(NamesGoverningSource::class, $source, $source->key());
        }

        // Only the host is asked again on every sign-in. The other three are
        // one-shot provenances and say so, which is what stops the sweep
        // pretending a CSV upload will notice somebody leaving.
        self::assertSame(
            ['host_group'],
            array_values(array_map(
                static fn ($source): string => $source->key(),
                array_filter(
                    $registries->provisioningSources->all(),
                    static fn ($source): bool => $source->isContinuous(),
                ),
            )),
        );

        // Compliance stays empty after seeding, which is the honest half of
        // the claim: seating a seam is not the same as filling it, and the
        // compliance tables ship with no rule registered on purpose.
        self::assertSame([], $registries->complianceRules->keys());
    }

    /**
     * The organisation seams take a provider from anywhere, replace one
     * explicitly, and refuse a duplicate key.
     *
     * This is the acceptance test the whole area is measured by: a package
     * outside the core registers an org role at KernelReady with no core edit.
     * Before R1 it could not, because the registry was a local variable inside
     * the kernel that threw LogicException and had no replace().
     */
    public function testAThirdPartyRoleRegistersReplacesAndCannotCollide(): void
    {
        $registries = new Registries();
        CoreOrganisationProviders::seed($registries);

        $registries->orgRoles->register(new CoreOrgRole('acme.regional_lead', ['read', 'events'], 90, true));
        self::assertTrue($registries->orgRoles->has('acme.regional_lead'));
        self::assertContains('acme.regional_lead', $registries->orgRoles->keys());

        $displaced = $registries->orgRoles->replace(new CoreOrgRole('acme.regional_lead', ['read'], 90, false));
        self::assertSame(['read', 'events'], $displaced->actions());
        self::assertSame(['read'], $registries->orgRoles->get('acme.regional_lead')->actions());

        $this->expectException(DuplicateProviderException::class);
        $registries->orgRoles->register(new CoreOrgRole('acme.regional_lead', [], 90, true));
    }

    /** Learning reports retain their independent export and aggregation registries. */
    public function testTheReportingSeamsAreSeated(): void
    {
        $registries = new Registries();

        foreach ([
            'exportFormats' => $registries->exportFormats,
            'reportDeliveries' => $registries->reportDeliveries,
            'gradeAggregations' => $registries->gradeAggregations,
            'statementForwarders' => $registries->statementForwarders,
        ] as $property => $registry) {
            self::assertInstanceOf(AbstractProviderRegistry::class, $registry, $property);
        }
    }

    public function testTheAutomationSurfaceIsDeclaredAndStoresElsewhere(): void
    {
        self::assertContains('automation', AvailabilitySurface::keys());

        // It stores in the automation rule's own row, so the two surfaces
        // that share availability_rules are unchanged and the unique index
        // they collide on is untouched.
        self::assertFalse(AvailabilitySurface::Automation->storesInAvailabilityRules());
        self::assertTrue(AvailabilitySurface::ContentGating->storesInAvailabilityRules());
        self::assertTrue(AvailabilitySurface::EnrolmentGating->storesInAvailabilityRules());
    }
}
