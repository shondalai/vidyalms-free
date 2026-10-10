<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ComposedAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\ViewerRelation;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\NoAssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\NoVerifiedAddresses;
use Shondalai\VidyaLMS\Core\Port\Identity\UnverifiedDomains;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrgRole;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgJoinContext;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgRoleContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\DeclaresOwnership;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\ReachesUnits;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterMapperRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The organisation extensibility spine.
 *
 * Everything here is a claim the whole area rests on: the narrowing
 * arithmetic that replaced eight branches on a role key literal, the digest
 * inputs that keep one organisation's two readers from sharing an artefact,
 * the two refusals that keep the visibility scope and the tenancy scope one
 * composition rather than two ways in, and the three port defaults whose
 * directions differ from one another on purpose.
 *
 * The scopes are minted through the real resolver over a real database rather
 * than constructed, because the construction discipline is the point: there
 * are three factories, each takes a witness, and a test that reached past
 * them would be asserting a type this product does not ship. There are four
 * since R3 added the free organisation sweeps, which act as the administrator
 * whose own recorded action created the row being swept.
 */
final class OrganisationSpineTest extends TestCase
{
    private TestPorts $env;
    private Registries $registries;
    private OrganisationStore $store;
    private OrgScopeResolver $resolver;
    private OrganisationAccess $access;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->registries = new Registries();
        CoreOrganisationProviders::seed($this->registries);

        foreach ([1, 2, 3, 4] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);
        foreach ([2, 3, 4] as $id) {
            $this->env->authorization->grant($id, Capability::MANAGE_GROUPS);
        }

        $this->store = new OrganisationStore($this->env->database, $this->env->clock);
        $this->resolver = new OrgScopeResolver(
            new AccessGuard($this->env->authorization),
            $this->store,
            $this->registries->orgRoles,
        );
        $this->access = new OrganisationAccess($this->resolver);

        $this->store->create(['name' => 'Acme', 'slug' => 'acme', 'created_by' => 1]);
        $siteScope = $this->resolver->require(Principal::user(1), 1, 'people');
        $this->store->addMember($siteScope, 2, 'org_owner', 'manual', null);
        $this->store->addMember($siteScope, 3, 'org_admin', 'manual', null);
        $this->store->addMember($siteScope, 4, 'org_reporter', 'manual', null);
    }

    public function testTheRightsSubsetRuleIsComputedFromTheRegistryAndNotFromKeys(): void
    {
        $roles = $this->registries->orgRoles;

        // The owner holds the right to assign roles and every other right, so
        // every registered role is a subset of theirs.
        self::assertSame(
            ['org_owner', 'org_admin', 'org_reporter', 'org_member'],
            $roles->assignableBy($this->contextFor(2)),
        );

        // The administrator does not hold the right to assign roles, so the
        // only role they may hand out is the one carrying no right at all.
        self::assertSame(['org_member'], $roles->assignableBy($this->contextFor(3)));
        self::assertSame(['org_member'], $roles->assignableBy($this->contextFor(4)));
    }

    public function testARoleWhoseRightsExceedTheCallersIsRefusedEvenWithTheAssigningRight(): void
    {
        $roles = $this->registries->orgRoles;
        // A registered role that may assign roles and holds nothing else.
        $roles->register(new CoreOrgRole('acme.role_clerk', ['read', 'roles'], 90, true));
        $this->giveRole(2, 'acme.role_clerk');

        $assignable = $roles->assignableBy($this->contextFor(2));

        // It may assign itself and the empty role, and nothing wider: the
        // subset is over rights, so holding the assigning right is not a way
        // to mint the rights the caller does not have.
        self::assertContains('acme.role_clerk', $assignable);
        self::assertContains('org_member', $assignable);
        self::assertNotContains('org_owner', $assignable);
        self::assertNotContains('org_admin', $assignable);
    }

    public function testARoleNamingSiteAdministrationGrantsNothingToSomebodyTheHostHasNotMadeOne(): void
    {
        $roles = $this->registries->orgRoles;
        // A role that claims a capability the host never answers for an
        // organisation administrator.
        $roles->register(new class ('acme.pretender', ['read'], 91, true) extends CoreOrgRole {
            /**
             * @return list<string>
             */
            public function capabilities(): array
            {
                return [Capability::ADMIN];
            }
        });

        // The host answers only the organisation surface capability for this
        // person, so the declared site administration intersects to nothing.
        $context = $this->contextFor(3);

        self::assertSame([], $roles->effectiveCapabilities('acme.pretender', $context));
        self::assertSame(Capability::organisationSurface(), $roles->effectiveCapabilities('org_admin', $context));
        self::assertNotContains('acme.pretender', $roles->assignableBy($context));
    }

    /**
     * Two declarations were deciding two security questions and nothing
     * cross-checked them: the resolver authorised through the provider's own
     * permits() while the assignability subset was computed over actions().
     * A role whose predicate was broader than its list was therefore
     * rightless to the subset rule and permitted everything to the resolver.
     */
    public function testARoleWhosePredicateDisagreesWithItsRightsIsRefusedAtRegistration(): void
    {
        $roles = $this->registries->orgRoles;
        $liar = new class ('acme.assistant', [], 96, true) extends CoreOrgRole {
            public function permits(string $action): bool
            {
                return true;
            }
        };

        try {
            $roles->register($liar);
            self::fail('A role whose two halves disagree must not be admitted.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('acme.assistant', $refusal->getMessage());
            self::assertStringContainsString('permits() and actions()', $refusal->getMessage());
        }

        self::assertFalse($roles->has('acme.assistant'));
        // replace() is the same door, so the rule has no decorator-shaped hole.
        $roles->register(new CoreOrgRole('acme.assistant', [], 96, true));
        $this->expectException(\InvalidArgumentException::class);
        $roles->replace($liar);
    }

    /**
     * The residual the registration guard cannot see, closed at the only place
     * that matters: a predicate can only lie about a right no registered role
     * has named yet, and authorisation reads the enumerable list anyway.
     */
    public function testAuthorisationReadsTheEnumerableRightsAndNeverThePredicate(): void
    {
        $roles = $this->registries->orgRoles;
        $roles->register(new class ('acme.assistant', ['read'], 96, true) extends CoreOrgRole {
            public function permits(string $action): bool
            {
                return $action === 'read' || $action === 'acme.secret';
            }
        });
        $this->giveRole(3, 'acme.assistant');

        // A later package puts the right into the vocabulary, which is what
        // the registration guard could not have known about.
        $roles->register(new CoreOrgRole('acme.keeper', ['acme.secret', 'read'], 97, false));
        self::assertContains('acme.secret', $roles->actions());

        self::assertTrue($roles->permits('acme.assistant', 'read'));
        self::assertFalse($roles->permits('acme.assistant', 'acme.secret'));
        self::assertFalse($this->access->can(Principal::user(3), 1, 'acme.secret'));
        self::assertSame(['read'], $roles->actionsOf(['acme.assistant']));
    }

    public function testARoleNamingACapabilityOutsideTheShippedVocabularyIsRefused(): void
    {
        $invented = new class ('acme.inventor', ['read'], 98, true) extends CoreOrgRole {
            /** @return list<string> */
            public function capabilities(): array
            {
                return ['acme.super_powers'];
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/acme\.super_powers/');
        $this->registries->orgRoles->register($invented);
    }

    /**
     * The intersected comparison was vacuous for exactly the case it was added
     * for, because effectiveCapabilities() intersects the role's declared list
     * with what the host answers for the caller: a role declaring site
     * administration effectively declared nothing and was a subset of
     * everything.
     */
    public function testTheDeclaredCapabilitySubsetIsComparedAndNotOnlyTheIntersectedOne(): void
    {
        $roles = $this->registries->orgRoles;
        $roles->register(new class ('acme.super', ['people', 'read', 'roles'], 99, true) extends CoreOrgRole {
            /** @return list<string> */
            public function capabilities(): array
            {
                return [Capability::ADMIN];
            }
        });

        // The owner holds the assigning right and every right this role names,
        // and the intersected sets agree, so only the declared comparison can
        // refuse it.
        $owner = $this->contextFor(2);
        self::assertSame([], $roles->effectiveCapabilities('acme.super', $owner));
        self::assertSame([Capability::MANAGE_GROUPS], $owner->hostCapabilities);
        self::assertNotContains('acme.super', $roles->assignableBy($owner));
    }

    public function testOwnershipIsAMarkerAndNotAKey(): void
    {
        $roles = $this->registries->orgRoles;

        self::assertSame(['org_owner'], $roles->ownershipKeys());
        self::assertTrue($roles->holdsOwnership(['org_member', 'org_owner']));
        self::assertFalse($roles->holdsOwnership(['org_admin', 'org_reporter']));
        self::assertInstanceOf(DeclaresOwnership::class, $roles->get('org_owner'));

        // A registered ownership role inherits the last-owner invariant rather
        // than finding the core counting a key it has never heard of, and the
        // store counts both because it is handed the keys.
        $principal = new class ('acme.principal', ['people', 'read'], 92, false) extends CoreOrgRole implements
            DeclaresOwnership
        {
        };
        $roles->register($principal);
        self::assertSame(['org_owner', 'acme.principal'], $roles->ownershipKeys());
        self::assertSame(1, $this->store->activeOwnerCount($this->siteScope(), $roles->ownershipKeys()));
        $this->giveRole(3, 'acme.principal');
        self::assertSame(2, $this->store->activeOwnerCount($this->siteScope(), $roles->ownershipKeys()));
    }

    public function testTheActionVocabularyIsTheUnionOverTheProviders(): void
    {
        $roles = $this->registries->orgRoles;

        self::assertSame(['events', 'learning', 'people', 'read', 'roles', 'seats', 'settings'], $roles->actions());

        // A registered role introducing a right introduces it to the whole
        // vocabulary, which the two deleted literal lists made impossible.
        $roles->register(new CoreOrgRole('acme.auditor', ['audit', 'read'], 93, false));
        self::assertContains('audit', $roles->actions());
        self::assertSame(['audit', 'read'], $roles->actionsOf(['acme.auditor']));
    }

    public function testTheRoleCatalogueIsOrderedByTheProvidersOwnDeclaration(): void
    {
        $roles = $this->registries->orgRoles;
        $roles->register(new CoreOrgRole('acme.first', [], 5, true));

        // Registration order would have put the newcomer last; the declared
        // group and order put it where its author asked for it.
        self::assertSame(
            ['acme.first', 'org_owner', 'org_admin', 'org_reporter', 'org_member'],
            array_column($roles->catalogue(), 'key'),
        );
    }

    public function testTheScopeDigestCoversTheNarrowingAndNotOnlyTheIds(): void
    {
        $manager = $this->resolver->require(Principal::user(1), 1);
        $owner = $this->resolver->require(Principal::user(2), 1);
        $reporter = $this->resolver->require(Principal::user(4), 1);

        // One organisation, three readers, three artefacts, and none of them
        // is served under another's digest.
        self::assertNotSame($manager->digest(), $reporter->digest());
        self::assertNotSame($owner->digest(), $reporter->digest());
        self::assertSame($reporter->digest(), $this->resolver->require(Principal::user(4), 1)->digest());

        // The basis is a digest input, which is what separates the site
        // manager from a delegated reader over the same rows.
        self::assertSame(OrgScope::BASIS_SITE_CAPABILITY, $manager->basis);
        self::assertSame(OrgScope::BASIS_ROLE_GRANT, $reporter->basis);

        // It is a hex sha256 over the published v1 shape, so a stored digest
        // is a fixed width and the prefix invalidates every one at once.
        self::assertSame(64, \strlen($reporter->digest()));
        self::assertSame(
            hash('sha256', 'v1|org:1|unit:|basis:role_grant|roles:org_reporter|disc:org_minimal:1'),
            $reporter->digest(),
        );
        self::assertSame(OrgScope::DISCLOSURE_ORG_MINIMAL, $reporter->disclosureKey);
        self::assertSame(1, $reporter->disclosureVersion);
    }

    public function testTheScopeCarriesItsIdsAsSortedListsAndNeverAnEmptyOne(): void
    {
        $scope = $this->resolver->require(Principal::user(2), 1);

        self::assertSame([1], $scope->organisationIds());
        self::assertSame([], $scope->unitIds());
        self::assertSame(['org_owner'], $scope->roles);

        // There is no multi-organisation scope to build and no factory that
        // would take one: a genuinely cross-organisation read is a site-scoped
        // use case taking no scope at all.
        //
        // Four factories now, and the fourth is still a witness rather than a
        // way in: a free organisation sweep acts as the administrator whose
        // own recorded action created the row being swept, which is the same
        // authority fromTerminationJob recovers from a durable job. Every one
        // of them still needs a positive actor, so none of them can mint the
        // system principal the design forbids.
        self::assertSame(
            ['fromResolver', 'fromInvitationProof', 'fromTerminationJob', 'fromSweptRecord'],
            array_values(array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                array_filter(
                    (new \ReflectionClass(OrgScope::class))->getMethods(\ReflectionMethod::IS_STATIC),
                    static fn (\ReflectionMethod $method): bool => $method->isPublic(),
                ),
            )),
        );
        self::assertTrue((new \ReflectionMethod(OrgScope::class, '__construct'))->isPrivate());
    }

    public function testTheOrganisationVisibilityTypesCanOnlyBeBuiltFromAResolvedScope(): void
    {
        $scope = $this->resolver->require(Principal::user(4), 1);
        $organisation = VisibilityScope::forOrganisation($scope);

        self::assertSame(VisibilityScope::TYPE_ORGANISATION, $organisation->type);
        self::assertSame(1, $organisation->id);
        self::assertSame([1], $organisation->ids());
        self::assertSame($scope->digest(), $organisation->digest);
        self::assertTrue($organisation->isOrganisational());
        self::assertFalse($organisation->isSiteWide());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('VisibilityScope::forOrganisation()');
        VisibilityScope::of(VisibilityScope::TYPE_ORGANISATION, 1);
    }

    public function testOfManyRefusesTheOrganisationTypesToo(): void
    {
        self::assertSame([2, 5, 9], VisibilityScope::ofMany(VisibilityScope::TYPE_COHORT, [9, 5, 2, 5])->ids());
        self::assertSame(VisibilityScope::TYPE_USER, VisibilityScope::forUser(3)->type);

        $this->expectException(\InvalidArgumentException::class);
        VisibilityScope::ofMany(VisibilityScope::TYPE_ORG_UNIT, [4]);
    }

    public function testTheShippedVisibilityFactoriesAreUntouched(): void
    {
        self::assertTrue(VisibilityScope::site()->isSiteWide());
        self::assertSame([], VisibilityScope::site()->ids());
        self::assertSame(0, VisibilityScope::site()->id);

        $course = VisibilityScope::forCourse(11);
        self::assertSame(VisibilityScope::TYPE_COURSE, $course->type);
        self::assertSame(11, $course->id);
        self::assertSame([11], $course->ids());
        self::assertFalse($course->isSiteWide());

        $cohort = VisibilityScope::of(VisibilityScope::TYPE_COHORT, 3);
        self::assertSame(3, $cohort->id);
        self::assertNull($cohort->digest);
    }

    public function testTheMovedVisibilityMarkerAnswersToBothNames(): void
    {
        // The old fully qualified name still resolves through the alias, so a
        // paid package declaring it keeps working with no coordinated release.
        $old = 'Shondalai\\VidyaLMS\\Core\\Registry\\Notification\\Audience\\FiltersByVisibility';

        self::assertTrue(interface_exists($old));
        self::assertTrue(interface_exists(FiltersByVisibility::class));

        $provider = new class implements FiltersByVisibility {
        };

        self::assertTrue($provider instanceof $old);

        // The second marker is separate on purpose: declaring the first says
        // nothing about a scope kind that did not exist when it was written,
        // so nothing that carries one carries the other by implication.
        self::assertNotContains(HonoursOrgScope::class, class_implements($provider));
        self::assertSame([], class_implements(HonoursOrgScope::class));
    }

    public function testBothCarryingContextsTakeTheScopeAndDeriveTheirVisibilityFromIt(): void
    {
        $scope = $this->resolver->require(Principal::user(3), 1);

        $audience = AudienceContext::create(
            $this->env->database,
            $this->env->authorization,
            orgScope: $scope,
        );
        self::assertSame($scope, $audience->orgScope);
        self::assertNotNull($audience->visibilityScope);
        self::assertSame(VisibilityScope::TYPE_ORGANISATION, $audience->visibilityScope->type);

        $report = ReportContext::create([], Principal::user(3), orgScope: $scope);
        self::assertSame($scope, $report->orgScope);
        self::assertTrue($report->isScoped());
        self::assertSame($scope->digest(), $report->visibility?->digest);

        // No scope means no visibility either, which is the shipped default
        // every existing caller still gets.
        $plain = AudienceContext::create($this->env->database, $this->env->authorization);
        self::assertNull($plain->orgScope);
        self::assertNull($plain->visibilityScope);
        self::assertNull(ReportContext::create([], Principal::user(3))->orgScope);
    }

    public function testTheAccessPolicyAnswersTheSameRuleThrownAndUnthrownAndNamesTheResource(): void
    {
        self::assertSame('org:1', OrganisationAccess::resource(1));

        self::assertTrue($this->access->can(Principal::user(2), 1, 'settings'));
        self::assertFalse($this->access->can(Principal::user(4), 1, 'settings'));
        self::assertFalse($this->access->can(Principal::guest(), 1));

        // A foreign organisation and a missing one are the same answer, which
        // is the property the whole refusal order exists to preserve.
        self::assertFalse($this->access->can(Principal::user(2), 99));
        self::assertSame(
            $this->access->scopeFor(Principal::user(2), 1)->organisationId,
            $this->access->require(Principal::user(2), 1)->organisationId,
        );

        $this->expectException(EntityNotFoundException::class);
        $this->access->require(Principal::user(4), 1, 'settings');
    }

    public function testTheComposedRuleRefusesACourseTheSiteOwnerHasNotReleased(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('courses', ['title' => 'Course', 'slug' => 'course', 'state' => 'published',
            'created_by' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $composed = new ComposedAccess(
            $this->access,
            new CourseReleasePolicy($this->env->database, $this->env->clock),
        );

        $scope = $this->resolver->require(Principal::user(2), 1);
        self::assertFalse($composed->allows($scope, 1));

        $this->env->database->insert('org_course_releases', ['organisation_id' => 1, 'course_id' => 1,
            'state' => 'active', 'starts_at' => null, 'ends_at' => null, 'released_by' => 1,
            'created_at' => $now, 'updated_at' => $now]);

        self::assertTrue($composed->allows($scope, 1));
        $grant = $composed->require(Principal::user(2), 1, 1, 'learning');
        self::assertSame(1, $grant->scope->organisationId);
        self::assertNull($grant->endsAt());

        // The organisation half runs first, so a caller with no claim on the
        // organisation never learns which courses it holds.
        $this->expectException(EntityNotFoundException::class);
        $composed->require(Principal::user(2), 99, 1, 'learning');
    }

    public function testTheThreeIdentityPortDefaultsAnswerThreeDifferentHonestThings(): void
    {
        $ports = $this->env->ports;

        // An adapter that predates the three seams boots untouched and gets
        // the defaults, which is the whole reason they are defaulted.
        self::assertInstanceOf(NoAssertedIdentity::class, $ports->assertedIdentity);
        self::assertInstanceOf(UnverifiedDomains::class, $ports->domainVerification);
        self::assertInstanceOf(NoVerifiedAddresses::class, $ports->verifiedAddresses);

        // Silence, a refusal, and an unknown. Reading any of them as its
        // opposite is the bug each default is shaped against.
        self::assertNull($ports->assertedIdentity->assertedIdentityFor(Principal::user(3)));
        self::assertFalse($ports->domainVerification->isVerified(1, 'example.test'));
        self::assertSame([], $ports->verifiedAddresses->verifiedAddressesFor(3));
    }

    public function testAnAssertedIdentityIsKeyedOnIssuerAndSubjectAndNeverOnAnAddress(): void
    {
        $identity = AssertedIdentity::create('https://idp.example', 'abc123', 'someone@example.test', true);

        // The length of the first half in front of the separator, so a NUL
        // inside either half cannot make two distinct pairs one key.
        self::assertSame("19:https://idp.example\0abc123", $identity->key());
        self::assertTrue($identity->complete);

        $this->expectException(\InvalidArgumentException::class);
        AssertedIdentity::create('https://idp.example', '   ');
    }

    public function testTheFreeJoinRuleAdmitsOnlyAFreshMailboxProof(): void
    {
        $rules = $this->registries->orgJoinRules;

        self::assertTrue($rules->admits('invitation_only', OrgJoinContext::fromInvitationProof(1, 3, 'org_member')));
        self::assertFalse($rules->admits(
            'invitation_only',
            OrgJoinContext::withProof(1, 3, 'org_member', OrgJoinContext::PROOF_VERIFIED_DOMAIN),
        ));

        // Fail closed: a gate nobody can answer answers no, so an empty
        // registry admits nobody rather than falling back to admitting all.
        self::assertFalse((new Registries())->orgJoinRules->admits(
            'invitation_only',
            OrgJoinContext::fromInvitationProof(1, 3, 'org_member'),
        ));
    }

    public function testTheReclaimPolicyRegistryRefusesAnUnresolvedKeyAndACompletedSeat(): void
    {
        $reclaim = $this->registries->seatReclaim;
        $context = \Shondalai\VidyaLMS\Core\Domain\Organisation\SeatReclaimContext::forRelease(
            [],
            [],
            started: false,
            completed: false,
            actorId: 2,
            now: $this->env->clock->now(),
        );

        self::assertTrue($reclaim->allows('always', $context));
        self::assertTrue($reclaim->allows('before_start', $context));
        self::assertFalse($reclaim->allows('never', $context));
        self::assertFalse($reclaim->allows('acme.unregistered', $context));

        // The completed refusal is a seat invariant and no longer a decision
        // the registry takes before it has asked anything. It used to answer
        // false here, which meant a registered provider was never consulted
        // about a finished seat and the free `always` provider was overridden
        // by its own lookup table. The registry is a pure lookup: `always`
        // says yes, `before_completion` says no, and the refusal that actually
        // protects a finished seat lives in SeatLifecycle::release() beside
        // the other seat invariants, where SeatAccountingTest drives it.
        $finished = \Shondalai\VidyaLMS\Core\Domain\Organisation\SeatReclaimContext::forRelease(
            [],
            [],
            started: true,
            completed: true,
            actorId: 2,
            now: $this->env->clock->now(),
        );
        self::assertTrue($reclaim->allows('always', $finished));
        self::assertFalse($reclaim->allows('before_completion', $finished));
        self::assertFalse($reclaim->allows('acme.unregistered', $finished));
    }

    public function testAnUnresolvedOrganisationTypeIsNamedRatherThanHiddenOrRefused(): void
    {
        $types = $this->registries->orgTypes;

        self::assertSame('org.type.department', $types->labelFor('department'));
        self::assertNull($types->labelFor('acme.holding_company'));
        self::assertCount(11, $types->keys());
    }

    public function testTheRosterKeyGrammarMatchesTheItemMapperOneExactly(): void
    {
        self::assertSame('oneroster-csv-1.2:in', RosterMapperRegistry::keyFor('oneroster-csv-1.2', 'in'));
        self::assertSame(
            \Shondalai\VidyaLMS\Core\Registry\Interop\ItemMapperRegistry::keyFor('x', 'out'),
            RosterMapperRegistry::keyFor('x', 'out'),
        );
    }

    public function testTheViewerRelationIsAClosedVocabularyThatNeverWidensOnAMiss(): void
    {
        self::assertFalse(ViewerRelation::wholeScope()->narrows());
        self::assertTrue(ViewerRelation::directReports()->narrows());
        self::assertSame('same_unit', ViewerRelation::sameUnit()->value);

        $this->expectException(\InvalidArgumentException::class);
        ViewerRelation::of('everything');
    }

    /**
     * The list read and the detail read agreed by accident and disagreed on
     * the case that matters.
     *
     * The list let the alphabetically last assignment win and the detail
     * returned null for anybody holding more than one, so a member with two
     * unit-scoped roles was two different answers depending on which screen
     * asked. Both now read one derivation, and the honest list of scoped roles
     * sits beside the single value it is derived from.
     */
    public function testTheListAndDetailReadsDeriveTheRoleUnitTheSameWay(): void
    {
        $scope = $this->siteScope();
        $memberId = $this->store->activeMemberId($scope, 3);
        self::assertNotNull($memberId);

        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        foreach ([['Alpha', 'Alpha'], ['Beta', 'Beta']] as $index => [$name, $path]) {
            $this->env->database->insert('org_units', [
                'organisation_id' => 1, 'parent_id' => null, 'name' => $name, 'path' => $path,
                'path_digest' => hash('sha256', '1:' . $path), 'depth' => 1, 'state' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Two unit-scoped roles at two different units: null is the only
        // honest answer and both reads must give it.
        $this->env->database->delete('org_role_assignments', ['organisation_id' => 1, 'membership_id' => $memberId]);
        foreach ([['org_admin', 1], ['org_reporter', 2]] as [$roleKey, $unitId]) {
            $this->env->database->insert('org_role_assignments', [
                'organisation_id' => 1, 'membership_id' => $memberId, 'role_key' => $roleKey,
                'scope_type' => 'unit', 'scope_id' => $unitId, 'assigned_by' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $detail = $this->store->member($scope, $memberId);
        $listed = $this->listedMember($scope, $memberId);

        self::assertNull($detail['role_unit_id']);
        self::assertSame($detail['role_unit_id'], $listed['role_unit_id']);
        self::assertSame(['org_admin', 'org_reporter'], $detail['role_keys']);
        self::assertSame($detail['role_keys'], $listed['role_keys']);
        self::assertSame([
            ['role_key' => 'org_admin', 'scope_type' => 'unit', 'unit_id' => 1],
            ['role_key' => 'org_reporter', 'scope_type' => 'unit', 'unit_id' => 2],
        ], $detail['role_scopes']);
        self::assertSame($detail['role_scopes'], $listed['role_scopes']);

        // Two roles at one unit is one unambiguous unit, and both reads agree
        // on that too rather than one of them returning null.
        $this->env->database->update(
            'org_role_assignments',
            ['scope_id' => 1],
            ['organisation_id' => 1, 'membership_id' => $memberId],
        );
        self::assertSame(1, $this->store->member($scope, $memberId)['role_unit_id']);
        self::assertSame(1, $this->listedMember($scope, $memberId)['role_unit_id']);
    }

    /**
     * activeUnitRoles() supports one explicit role scope and fails closed on
     * more than one. The direction is right and its consequence was invisible:
     * a second unit-scoped assignment revoked an existing unit
     * administrator's whole console with nothing anywhere saying why.
     */
    public function testAnAmbiguousUnitReachIsNamedOnTheReadAndNotOnlyFailedClosed(): void
    {
        $scope = $this->siteScope();
        $memberId = $this->store->activeMemberId($scope, 3);
        self::assertNotNull($memberId);

        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        foreach ([['North', 1], ['South', 2]] as [$name, $ignored]) {
            $this->env->database->insert('org_units', [
                'organisation_id' => 1, 'parent_id' => null, 'name' => $name, 'path' => $name,
                'path_digest' => hash('sha256', '1:' . $name), 'depth' => 1, 'state' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->store->setRole($scope, $memberId, 'org_admin', 1);
        self::assertFalse($this->store->member($scope, $memberId)['scope_ambiguous']);
        self::assertTrue($this->access->can(Principal::user(3), 1, 'people'));

        // The second assignment an importer or a second registered role writes.
        $this->env->database->insert('org_role_assignments', [
            'organisation_id' => 1, 'membership_id' => $memberId, 'role_key' => 'org_reporter',
            'scope_type' => 'unit', 'scope_id' => 2, 'assigned_by' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        self::assertFalse($this->access->can(Principal::user(3), 1, 'people'), 'the reach still fails closed');
        self::assertTrue($this->store->member($scope, $memberId)['scope_ambiguous']);
        self::assertTrue($this->listedMember($scope, $memberId)['scope_ambiguous']);
    }

    /**
     * The schema comment stated the OneRoster rule and said no index enforces
     * it, which left it enforced by nothing: the at-most-one-active-membership
     * key bounds the active rows, so a closed membership and an active one
     * could both claim to be primary and a date-ranged report reads two.
     */
    public function testClaimingAPrimaryMembershipClearsEveryOtherOneForThatPerson(): void
    {
        $scope = $this->siteScope();
        $active = $this->store->activeMemberId($scope, 3);
        self::assertNotNull($active);

        // A closed membership the same person held before, marked primary.
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $closed = $this->store->database->insert('org_memberships', [
            'organisation_id' => 1, 'user_id' => 3, 'state' => 'left', 'joined_at' => $now,
            'left_at' => $now, 'provisioned_by' => 'manual', 'is_primary' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->store->setDirectory($scope, $active, ['is_primary' => 1]);

        $rows = $this->env->database->selectAll(
            \Shondalai\VidyaLMS\Core\Port\Database\Query::from('org_memberships')
                ->where('organisation_id', '=', 1)->where('user_id', '=', 3)->orderBy('id')
        );
        $primaries = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['is_primary'] === 1));
        self::assertCount(1, $primaries);
        self::assertSame($active, (int) $primaries[0]['id']);

        $cleared = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['id'] === $closed));
        self::assertCount(1, $cleared);
        self::assertSame(0, (int) $cleared[0]['is_primary']);
    }

    public function testTheUnitDepthBoundIsASettingsRowAndNotTheLiteralSix(): void
    {
        $settings = new \Shondalai\VidyaLMS\Core\Application\Platform\Settings(
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository(
                $this->env->database,
                $this->env->clock,
            ),
        );
        $units = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits(
            $this->resolver,
            $this->store,
            $settings,
        );

        self::assertSame(6, $units->maxDepth());
        self::assertSame(
            6,
            (new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits(
                $this->resolver,
                $this->store,
            ))->maxDepth(),
        );

        $settings->set(\Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits::SETTING_MAX_DEPTH, 9);
        self::assertSame(9, $units->maxDepth());

        // Clamped rather than trusted: a stored zero would make every unit
        // refuse, and a value past the path column's capacity would move the
        // failure from a named error to a truncated path nobody notices.
        $settings->set(\Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits::SETTING_MAX_DEPTH, 0);
        self::assertSame(1, $units->maxDepth());
        $settings->set(\Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits::SETTING_MAX_DEPTH, 4096);
        self::assertSame(64, $units->maxDepth());
    }

    /**
     * Raising the depth setting left the closure builder at the literal six it
     * used to share with the depth check, so from depth eight every unit
     * recorded only seven ancestors and a unit administrator anchored at the
     * root of the tree could not see the people at the bottom of it. At the
     * shipped default of six the defect is unreachable, so it was introduced
     * by making the bound configurable.
     */
    public function testEveryAncestorIsRecordedAtTheConfiguredDepthAndNotOnlyTheFirstSix(): void
    {
        $settings = new \Shondalai\VidyaLMS\Core\Application\Platform\Settings(
            new \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository(
                $this->env->database,
                $this->env->clock,
            ),
        );
        $settings->set(\Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits::SETTING_MAX_DEPTH, 10);
        $units = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits(
            $this->resolver,
            $this->store,
            $settings,
        );

        $parent = null;
        $ids = [];

        foreach (range(1, 10) as $depth) {
            $saved = $units->save(Principal::user(1), 1, null, [
                'expected_revision' => $this->revision(),
                'name' => 'D' . $depth,
                'parent_id' => $parent,
            ]);
            $parent = (int) $saved['item']['id'];
            $ids[$depth] = $parent;
        }

        foreach ($ids as $depth => $unitId) {
            self::assertSame($depth, $this->env->database->count(
                \Shondalai\VidyaLMS\Core\Port\Database\Query::from('org_unit_closure')
                    ->where('organisation_id', '=', 1)->where('descendant_id', '=', $unitId)
            ), 'depth ' . $depth . ' must record one ancestor row per level including itself');
        }

        // The invariant that actually bites: the root reaches the deepest unit.
        self::assertSame($ids[10], $this->store->unitSubtree(1, $ids[1])[9] ?? 0);
        self::assertCount(10, $this->store->unitSubtree(1, $ids[1]));
    }

    /**
     * The design's carried unit list already includes descendants; the scope
     * carried the anchor alone, so a unit moved between two resolutions
     * changed the reach and produced a byte-identical digest.
     */
    public function testTheScopeCarriesTheWholeSubtreeSoAUnitMoveChangesItsDigest(): void
    {
        $units = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits(
            $this->resolver,
            $this->store,
        );
        $root = (int) $units->save(Principal::user(1), 1, null, [
            'expected_revision' => $this->revision(), 'name' => 'Root',
        ])['item']['id'];
        $branch = (int) $units->save(Principal::user(1), 1, null, [
            'expected_revision' => $this->revision(), 'name' => 'Branch', 'parent_id' => $root,
        ])['item']['id'];
        $leaf = (int) $units->save(Principal::user(1), 1, null, [
            'expected_revision' => $this->revision(), 'name' => 'Leaf', 'parent_id' => $branch,
        ])['item']['id'];

        $memberId = $this->store->activeMemberId($this->siteScope(), 3);
        self::assertNotNull($memberId);
        $this->store->setRole($this->siteScope(), $memberId, 'org_admin', $branch);

        $before = $this->resolver->require(Principal::user(3), 1);
        self::assertSame([$branch, $leaf], $before->unitIds());
        $beforeDigest = $before->digest();

        // Re-parenting the leaf away is a change to what the scope reaches.
        $units->save(Principal::user(1), 1, $leaf, [
            'expected_revision' => $this->revision(), 'name' => 'Leaf', 'parent_id' => $root,
        ]);

        $after = $this->resolver->require(Principal::user(3), 1);
        self::assertSame([$branch], $after->unitIds());
        self::assertNotSame($beforeDigest, $after->digest(), 'a move must invalidate the artefact, not serve it stale');

        // And the two mechanisms agree: a provider honouring ids() sees what
        // the closure join sees rather than only the anchor.
        $rootScoped = $this->resolver->require(Principal::user(3), 1);
        $this->store->setRole($this->siteScope(), $memberId, 'org_admin', $root);
        $rootScoped = $this->resolver->require(Principal::user(3), 1);
        self::assertSame([$root, $branch, $leaf], $rootScoped->unitIds());
        self::assertSame([$root, $branch, $leaf], VisibilityScope::forOrganisation($rootScoped)->ids());
    }

    /**
     * The acceptance criterion the whole area is measured by: zero core edits.
     *
     * The resolver, the store and the access policy are all constructed
     * before the role exists, exactly as they are on a booted site when a
     * package registers from a KernelReady listener. Consumers hold the
     * registry object and resolve providers when they run, so a registration
     * made after boot is seen by use cases already built. Before R1 this was
     * impossible: the registry was a local variable inside the kernel with no
     * property, no replace() and a LogicException where the duplicate
     * exception should have been.
     */
    public function testARoleRegisteredAfterTheUseCasesWereBuiltIsResolvedByThem(): void
    {
        // Nothing knows this key yet, so a holder of it resolves to nothing
        // and the fail-closed direction refuses them.
        $this->giveRole(3, 'acme.late_arrival');
        self::assertFalse($this->access->can(Principal::user(3), 1));
        self::assertFalse($this->registries->orgRoles->has('acme.late_arrival'));

        // One register() call on the seated registry, with no core edit and
        // no rebuild of anything already constructed.
        $this->registries->orgRoles->register(new CoreOrgRole('acme.late_arrival', ['people', 'read'], 95, true));

        self::assertTrue($this->access->can(Principal::user(3), 1));
        self::assertTrue($this->access->can(Principal::user(3), 1, 'people'));
        self::assertFalse($this->access->can(Principal::user(3), 1, 'settings'));
        self::assertSame(['acme.late_arrival'], $this->resolver->require(Principal::user(3), 1)->roles);
    }

    /**
     * A right a package introduced could be named in the console vocabulary,
     * be assigned, and guard a route, and could never reach a unit, because
     * the unit reach was a literal list of four the package cannot edit.
     */
    public function testARightAPackageIntroducedReachesAUnitWhenItsRoleSaysSo(): void
    {
        $roles = $this->registries->orgRoles;
        $regional = new class ('acme.regional_lead', ['audit', 'people', 'read'], 94, true) extends CoreOrgRole implements
            ReachesUnits
        {
            /** @return list<string> */
            public function unitScopedActions(): array
            {
                return ['audit', 'people', 'read'];
            }
        };
        $roles->register($regional);
        // The same right on a role that declares no reach stays at the
        // organisation, so one package's declaration never widens another's.
        $roles->register(new CoreOrgRole('acme.quiet_auditor', ['audit', 'read'], 94, true));

        $scope = $this->siteScope();
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('org_units', [
            'organisation_id' => 1, 'parent_id' => null, 'name' => 'North', 'path' => 'North',
            'path_digest' => hash('sha256', '1:North'), 'depth' => 1, 'state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->env->database->insert('org_unit_closure', [
            'organisation_id' => 1, 'ancestor_id' => 1, 'descendant_id' => 1, 'depth' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([[3, 'acme.regional_lead'], [4, 'acme.quiet_auditor']] as [$userId, $roleKey]) {
            $memberId = $this->store->activeMemberId($scope, $userId);
            self::assertNotNull($memberId);
            $this->store->setRole($scope, $memberId, $roleKey, 1);
        }

        self::assertTrue($this->access->can(Principal::user(3), 1, 'audit'));
        self::assertSame(1, $this->resolver->require(Principal::user(3), 1, 'audit')->unitId);
        self::assertTrue($this->access->can(Principal::user(3), 1, 'people'));

        // The role that declares nothing keeps the shipped four, so the right
        // it introduced is refused one level down exactly as before.
        self::assertTrue($this->access->can(Principal::user(4), 1, 'read'));
        self::assertFalse($this->access->can(Principal::user(4), 1, 'audit'));
    }

    public function testTheOrganisationSurfaceIsExactlyOneCapability(): void
    {
        // The length of this list is the invariant: a second capability on the
        // component asset would make an organisation administrator
        // indistinguishable from a site-wide holder of it.
        self::assertCount(1, Capability::organisationSurface());
        self::assertContains(Capability::organisationSurface()[0], Capability::all());
    }

    private function contextFor(int $userId): OrgRoleContext
    {
        return $this->access->roleContext(
            Principal::user($userId),
            $this->resolver->require(Principal::user($userId), 1),
        );
    }

    private function siteScope(): OrgScope
    {
        return $this->resolver->require(Principal::user(1), 1, 'people');
    }

    /** The organisation's current revision, which every write claims. */
    private function revision(): int
    {
        $row = $this->env->database->selectOne(
            \Shondalai\VidyaLMS\Core\Port\Database\Query::from('organisations')->where('id', '=', 1)
        );
        self::assertIsArray($row);

        return (int) $row['revision'];
    }

    /**
     * The same membership as the list read returns it.
     *
     * @return array<string, mixed>
     */
    private function listedMember(OrgScope $scope, int $memberId): array
    {
        foreach ($this->store->members($scope, 1, 50)['items'] as $item) {
            if ((int) $item['id'] === $memberId) {
                return $item;
            }
        }

        self::fail('The list read did not return membership ' . $memberId . '.');
    }

    /** Replaces the role a user's active membership holds, by user id. */
    private function giveRole(int $userId, string $roleKey): void
    {
        $scope = $this->siteScope();
        $memberId = $this->store->activeMemberId($scope, $userId);
        self::assertNotNull($memberId);
        $this->store->setRole($scope, $memberId, $roleKey);
    }
}
