<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationConnections;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\HostGroupIssuer;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IssuerStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Connection delegation: a subsidiary using a parent's verified connection
 * instead of claiming the issuer itself.
 *
 * R5 shipped the column, the index, the domain member and the store's reader
 * and no writer at all, so no delegated connection could ever exist. This is
 * the writer, and every case here is one of the four refusals that make it
 * safe: the corporate group is the boundary, an unproved claim cannot be
 * borrowed, a chain has no verified end, and a borrowed proof is a proof about
 * one issuer.
 */
final class OrganisationConnectionDelegationTest extends TestCase
{
    /**
     * The corporate group's own identity tenant.
     *
     * Deliberately not a reserved local host-group issuer: that namespace is
     * minted per organisation and a second organisation naming one is refused
     * at save, which is the rule that stops one tenant claiming another's
     * logins. Delegation is about a real shared issuer, so the test uses one.
     */
    private const GROUP_ISSUER = 'https://idp.example.test/acme-group';

    private TestPorts $env;
    private OrganisationStore $store;
    private OrgIdentityStore $identity;
    private ManageOrganisationConnections $connections;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);
        $this->identity = new OrgIdentityStore($this->env->database, $this->env->clock);

        foreach ([1, 2] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);

        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $mappers = new IdentityMapperRegistry();

        foreach (CoreOrganisationProviders::identityMappers() as $mapper) {
            $mappers->register($mapper);
        }

        $this->connections = new ManageOrganisationConnections(
            new OrganisationAccess(new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles)),
            $this->identity,
            $this->store,
            $roles,
            $mappers,
            $this->env->users,
        );
    }

    /**
     * The whole feature: one issuer, one verified holder, and a subsidiary
     * that uses it without a second organisation ever verifying it.
     */
    public function testASubsidiaryUnderTheSameRootDelegatesToTheParentsVerifiedConnection(): void
    {
        [$parentOrg, $childOrg] = $this->group();
        $issuer = self::GROUP_ISSUER;
        $parent = $this->verifiedConnection($parentOrg, $issuer);
        $child = $this->connections->create($this->owner(), $childOrg, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);

        $delegated = $this->connections->delegate($this->owner(), $childOrg, $child['id'], [
            'delegated_from_connection_id' => $parent['id'],
        ]);

        self::assertSame($parent['id'], $delegated['delegated_from_connection_id']);
        self::assertSame(
            IssuerStatus::PENDING,
            $delegated['issuer_status'],
            'the child borrows a proof rather than acquiring one of its own',
        );
        self::assertSame(
            1,
            $this->env->database->count(
                Query::from('org_connections')->where('issuer_status', '=', IssuerStatus::VERIFIED),
            ),
            'exactly one organisation holds the issuer verified, which is the index the feature exists to respect',
        );
    }

    /** The transition is recorded, with the parent named, on the membership event stream. */
    public function testTheDelegationIsRecordedOnTheMembershipEventStream(): void
    {
        [$parentOrg, $childOrg] = $this->group();
        $issuer = self::GROUP_ISSUER;
        $parent = $this->verifiedConnection($parentOrg, $issuer);
        $child = $this->connections->create($this->owner(), $childOrg, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);
        $this->connections->delegate($this->owner(), $childOrg, $child['id'], [
            'delegated_from_connection_id' => $parent['id'],
        ]);

        $events = $this->env->database->selectAll(
            Query::from('org_membership_events')
                ->where('organisation_id', '=', $childOrg)
                ->where('event_key', '=', MembershipEvent::GOVERNANCE_CHANGED)
                ->orderBy('id'),
        );
        $last = end($events);
        self::assertIsArray($last);
        $details = json_decode((string) $last['details'], true);

        self::assertSame($parent['id'], $details['delegated_from_connection_id']);
        self::assertSame($parentOrg, $details['delegated_from_organisation_id']);
        self::assertSame(1, (int) $last['actor_id'], 'the site manager who decided it is on the row');
    }

    /** Only a site-scoped principal delegates: an organisation may not grant itself one. */
    public function testAnOrganisationAdministratorCannotDelegateItsOwnConnection(): void
    {
        [$parentOrg, $childOrg] = $this->group();
        $issuer = self::GROUP_ISSUER;
        $parent = $this->verifiedConnection($parentOrg, $issuer);
        $child = $this->connections->create($this->owner(), $childOrg, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);
        $this->store->addMember($this->scope($childOrg), 2, 'org_owner', 'manual', null);
        $this->env->authorization->grant(2, Capability::MANAGE_GROUPS);

        $this->expectException(NotAuthorizedException::class);
        $this->connections->delegate(Principal::user(2), $childOrg, $child['id'], [
            'delegated_from_connection_id' => $parent['id'],
        ]);
    }

    /** A connection under another root is another customer entirely, and reads as absent. */
    public function testAParentOutsideTheCorporateGroupIsNotFound(): void
    {
        [, $childOrg] = $this->group();
        $stranger = $this->organisation('globex');
        $strangerIssuer = 'https://idp.example.test/globex';
        $foreign = $this->verifiedConnection($stranger, $strangerIssuer);
        $child = $this->connections->create($this->owner(), $childOrg, [
            'issuer' => $strangerIssuer,
            'dialect' => 'host-group',
        ]);

        $this->expectException(EntityNotFoundException::class);
        $this->connections->delegate($this->owner(), $childOrg, $child['id'], [
            'delegated_from_connection_id' => $foreign['id'],
        ]);
    }

    /** Delegating to an unproved claim would launder it into a proved one. */
    public function testAParentWhoseIssuerIsNotVerifiedIsRefused(): void
    {
        [$parentOrg, $childOrg] = $this->group();
        $issuer = self::GROUP_ISSUER;
        $parent = $this->connections->create($this->owner(), $parentOrg, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);
        $child = $this->connections->create($this->owner(), $childOrg, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessageMatches('/verified/');
        $this->connections->delegate($this->owner(), $childOrg, $child['id'], [
            'delegated_from_connection_id' => $parent['id'],
        ]);
    }

    /** A chain has no verified end a reader can find in one hop. */
    public function testADelegatedConnectionCannotItselfBeDelegatedFrom(): void
    {
        [$parentOrg, $childOrg] = $this->group();
        $grandchildOrg = $this->child($parentOrg, 'acme-south');
        $issuer = self::GROUP_ISSUER;
        $parent = $this->verifiedConnection($parentOrg, $issuer);
        $child = $this->connections->create($this->owner(), $childOrg, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);
        $this->connections->delegate($this->owner(), $childOrg, $child['id'], [
            'delegated_from_connection_id' => $parent['id'],
        ]);
        $grandchild = $this->connections->create($this->owner(), $grandchildOrg, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);

        $this->expectException(ConflictException::class);
        $this->connections->delegate($this->owner(), $grandchildOrg, $grandchild['id'], [
            'delegated_from_connection_id' => $child['id'],
        ]);
    }

    /** A borrowed proof is a proof about one issuer and not about any other. */
    public function testADelegationCarryingADifferentIssuerIsRefused(): void
    {
        [$parentOrg, $childOrg] = $this->group();
        $parent = $this->verifiedConnection($parentOrg, self::GROUP_ISSUER);
        $child = $this->connections->create($this->owner(), $childOrg, [
            'issuer' => 'https://idp.example.test/acme-north',
            'dialect' => 'host-group',
        ]);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessageMatches('/issuer/');
        $this->connections->delegate($this->owner(), $childOrg, $child['id'], [
            'delegated_from_connection_id' => $parent['id'],
        ]);
    }

    /** Passing null detaches, and the row is recorded as detached. */
    public function testPassingNullDetachesTheConnection(): void
    {
        [$parentOrg, $childOrg] = $this->group();
        $issuer = self::GROUP_ISSUER;
        $parent = $this->verifiedConnection($parentOrg, $issuer);
        $child = $this->connections->create($this->owner(), $childOrg, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);
        $this->connections->delegate($this->owner(), $childOrg, $child['id'], [
            'delegated_from_connection_id' => $parent['id'],
        ]);

        $detached = $this->connections->delegate($this->owner(), $childOrg, $child['id'], [
            'delegated_from_connection_id' => null,
        ]);

        self::assertNull($detached['delegated_from_connection_id']);
    }

    /** A connection cannot delegate to itself, which would be a cycle of one. */
    public function testAConnectionCannotDelegateToItself(): void
    {
        [$parentOrg] = $this->group();
        $parent = $this->verifiedConnection($parentOrg, self::GROUP_ISSUER);

        $this->expectException(ConflictException::class);
        $this->connections->delegate($this->owner(), $parentOrg, $parent['id'], [
            'delegated_from_connection_id' => $parent['id'],
        ]);
    }

    // ── Harness ──────────────────────────────────────────────────────────

    /** @return array{int, int} a root organisation and a subsidiary under it */
    private function group(): array
    {
        $parent = $this->organisation('acme');

        return [$parent, $this->child($parent, 'acme-north')];
    }

    private function child(int $rootId, string $slug): int
    {
        $id = $this->organisation($slug);
        $this->env->database->update(
            'organisations',
            ['parent_id' => $rootId, 'root_id' => $rootId, 'depth' => 1],
            ['id' => $id],
        );

        return $id;
    }

    /** @return array<string, mixed> */
    private function verifiedConnection(int $organisationId, string $issuer): array
    {
        $connection = $this->connections->create($this->owner(), $organisationId, [
            'issuer' => $issuer,
            'dialect' => 'host-group',
        ]);

        return $this->connections->decide($this->owner(), $organisationId, $connection['id'], [
            'issuer_status' => IssuerStatus::VERIFIED,
        ]);
    }

    private function scope(int $organisationId): \Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope
    {
        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        return (new OrganisationAccess(
            new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles),
        ))->require($this->owner(), $organisationId, 'read');
    }

    private function owner(): Principal
    {
        return Principal::user(1);
    }

    private function organisation(string $slug): int
    {
        return $this->store->create(['name' => ucfirst($slug), 'slug' => $slug, 'created_by' => 1]);
    }
}
