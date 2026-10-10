<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingBound;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingDecision;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingRuleEvaluator;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingRuleSet;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedCompleteness;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedGroup;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedKeyKind;

/**
 * The prototype the design names as the one thing to build first.
 *
 * Four fixtures, and the decision on all four has to be right before anything
 * else in this area is worth building. The Entra overage case is the one that
 * matters most: a token that omitted its group claim because the person is in
 * too many groups must grant nothing and must remove nothing, and getting that
 * one wrong deletes a customer's roster on a day nothing else changed.
 */
final class MappingRuleEvaluatorTest extends TestCase
{
    private const ORGANISATION = 7;

    /** The units of organisation 7, resolved fresh at evaluation. */
    private const UNITS = [41, 42];

    private function bound(): MappingBound
    {
        return MappingBound::forConnection(self::ORGANISATION, self::UNITS, [
            'org_member' => [Capability::MANAGE_GROUPS],
            'org_admin' => [Capability::MANAGE_GROUPS],
            // Declared here so the refusal below is a refusal of a role that
            // exists and carries site administration, not of a role that is
            // simply absent. Those are two different defects and the test
            // asserts the dangerous one.
            'site_god' => [Capability::MANAGE_GROUPS, Capability::ADMIN],
        ]);
    }

    /** groups eq LMS-Sales, by name, granting membership of unit 41. */
    private function salesRule(bool $stop = true): MappingRuleSet
    {
        return MappingRuleSet::of([[
            'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => 'LMS-Sales', 'value_kind' => 'name'],
            'then' => ['org_ref' => 'northlight', 'org_id' => self::ORGANISATION,
                'org_unit_ref' => 'sales', 'org_unit_id' => 41, 'org_role' => 'org_member'],
            'stop' => $stop,
        ]]);
    }

    /**
     * Fixture one. An Entra token whose group claim was omitted because the
     * person is in more groups than the cap allows, so the assertion arrives
     * with no groups and a partial completeness.
     *
     * Two assertions and both are the point. Nothing is granted, because deny
     * by default means an absent group grants nothing. And nothing may be
     * removed, because an absent group claim from a truncating source is
     * silence and not a denial.
     */
    public function testAnOverageTokenGrantsNothingAndRemovesNothing(): void
    {
        $identity = AssertedIdentity::asserted(
            'https://login.example.test/tenant/v2.0',
            '9f2a1c7e-0000-4c11-b2ac-000000000001',
            [],
            // The overage marker the directory sends instead of the groups. It
            // is an ordinary attribute here: nothing past the mapper knows what
            // it means, and nothing may read it as "this person has no groups".
            ['groups_source' => 'link'],
            AssertedCompleteness::PARTIAL,
        );

        $decision = (new MappingRuleEvaluator())->evaluate(
            $this->salesRule(),
            $identity,
            $this->bound(),
            // Even on an authoritative connection.
            true,
        );

        self::assertSame([], $decision->grants, 'An absent group claim grants nothing.');
        self::assertFalse($decision->grantsAnything());
        self::assertFalse($decision->mayRemove, 'A partial source may never drive a removal.');
        self::assertSame(MappingDecision::REMOVAL_SOURCE_PARTIAL, $decision->removalBasis);
        self::assertSame([], $decision->fired);
        self::assertSame([], $decision->unmatchedKeys);
    }

    /**
     * The same overage case with an empty group list rather than an absent
     * one, which is the other shape a filter misconfiguration produces.
     */
    public function testAnEmptyGroupListFromAPartialSourceStillRemovesNothing(): void
    {
        $identity = AssertedIdentity::asserted(
            'https://idp.example.test',
            'subject-empty',
            [],
            [],
            AssertedCompleteness::PARTIAL,
        );

        $decision = (new MappingRuleEvaluator())->evaluate($this->salesRule(), $identity, $this->bound(), true);

        self::assertFalse($decision->mayRemove);
        self::assertSame(MappingDecision::REMOVAL_SOURCE_PARTIAL, $decision->removalBasis);
    }

    /**
     * Fixture two. An assertion carrying group names, which is what a
     * name-emitting directory sends.
     *
     * The named group grants the role the rule names, inside the connection's
     * own organisation and its own unit. The other group matched nothing and is
     * recorded as unmatched, which is the record that tells an administrator
     * their rule set is incomplete rather than working.
     */
    public function testAnAssertionCarryingGroupNamesGrantsWhatTheRuleNames(): void
    {
        $identity = AssertedIdentity::asserted(
            'http://www.example.test/exk1fcia',
            'subject-okta-1',
            [
                AssertedGroup::of('Everyone', AssertedKeyKind::NAME),
                AssertedGroup::of('LMS-Sales', AssertedKeyKind::NAME, 'Sales team'),
            ],
            [],
            AssertedCompleteness::COMPLETE,
        );

        $decision = (new MappingRuleEvaluator())->evaluate($this->salesRule(), $identity, $this->bound(), false);

        self::assertCount(1, $decision->grants);
        self::assertSame(self::ORGANISATION, $decision->grants[0]->organisationId);
        self::assertSame(41, $decision->grants[0]->orgUnitId);
        self::assertSame('org_member', $decision->grants[0]->roleKey);
        self::assertSame('LMS-Sales', $decision->grants[0]->matchedKey);
        self::assertSame([['rule' => 0, 'condition' => 'groups eq LMS-Sales [name]']], $decision->fired);
        self::assertSame(['Everyone'], $decision->unmatchedKeys, 'A key matching no rule is recorded.');

        // A complete source on a connection nobody vouched for still may not
        // remove: the tenant would otherwise be asserting authority over its
        // own removals.
        self::assertFalse($decision->mayRemove);
        self::assertSame(MappingDecision::REMOVAL_CONNECTION_NOT_AUTHORITATIVE, $decision->removalBasis);
    }

    /**
     * Fixture three. A directory push removing one member by a path filter:
     * the same person asserted again, complete, from an authoritative
     * connection, no longer carrying the group that granted them anything.
     *
     * This is the only shape allowed to drive a removal, and the decision says
     * so explicitly rather than by producing an empty grant list that a caller
     * has to interpret.
     */
    public function testACompleteSourceOnAnAuthoritativeConnectionMayRemove(): void
    {
        $identity = AssertedIdentity::asserted(
            'https://scim.example.test/v2',
            'subject-scim-1',
            [AssertedGroup::of('Everyone', AssertedKeyKind::NAME)],
            [],
            AssertedCompleteness::COMPLETE,
        );

        $decision = (new MappingRuleEvaluator())->evaluate($this->salesRule(), $identity, $this->bound(), true);

        self::assertSame([], $decision->grants, 'The group that granted the role is gone.');
        self::assertTrue($decision->mayRemove);
        self::assertSame(MappingDecision::REMOVAL_PERMITTED, $decision->removalBasis);
        self::assertSame(['Everyone'], $decision->unmatchedKeys);
    }

    /**
     * Fixture four. A host group read, which is what the free build runs on
     * both hosts: identifiers, complete, and a local issuer minted per
     * organisation.
     */
    public function testAHostGroupReadGrantsOnAnIdentifier(): void
    {
        $rules = MappingRuleSet::of([[
            'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => '8', 'value_kind' => 'id'],
            'then' => ['org_id' => self::ORGANISATION, 'org_role' => 'org_admin'],
            'stop' => false,
        ]]);

        $identity = AssertedIdentity::asserted(
            'local:host:' . self::ORGANISATION,
            '254559',
            [AssertedGroup::hostGroup('8', 'Manager'), AssertedGroup::hostGroup('2', 'Registered')],
            [],
            AssertedCompleteness::COMPLETE,
        );

        $decision = (new MappingRuleEvaluator())->evaluate($rules, $identity, $this->bound(), false);

        self::assertCount(1, $decision->grants);
        self::assertSame('org_admin', $decision->grants[0]->roleKey);
        self::assertNull($decision->grants[0]->orgUnitId, 'A rule naming no unit grants at the organisation.');
        self::assertSame(['2'], $decision->unmatchedKeys);
    }

    /**
     * The subtree bound, enforced at evaluation and not only at save, because
     * a stored row outlives the authorisation that wrote it.
     */
    public function testARuleNamingAForeignOrganisationGrantsNothing(): void
    {
        $rules = MappingRuleSet::of([[
            'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => 'LMS-Sales', 'value_kind' => 'name'],
            'then' => ['org_ref' => 'acme', 'org_id' => 999, 'org_role' => 'org_admin'],
            'stop' => false,
        ]]);

        $identity = AssertedIdentity::asserted(
            'https://idp.example.test',
            'subject-cross',
            [AssertedGroup::of('LMS-Sales', AssertedKeyKind::NAME)],
        );

        $decision = (new MappingRuleEvaluator())->evaluate($rules, $identity, $this->bound(), true);

        self::assertSame([], $decision->grants);
        self::assertSame([['rule' => 0, 'reason' => MappingDecision::REFUSED_FOREIGN_ORG]], $decision->refusals);
    }

    public function testARuleNamingAForeignUnitGrantsNothing(): void
    {
        $rules = MappingRuleSet::of([[
            'when' => ['attribute' => 'groups', 'op' => 'pr', 'value_kind' => 'name'],
            'then' => ['org_id' => self::ORGANISATION, 'org_unit_id' => 6000, 'org_role' => 'org_member'],
            'stop' => false,
        ]]);

        $identity = AssertedIdentity::asserted(
            'https://idp.example.test',
            'subject-unit',
            [AssertedGroup::of('Anything', AssertedKeyKind::NAME)],
        );

        $decision = (new MappingRuleEvaluator())->evaluate($rules, $identity, $this->bound(), true);

        self::assertSame([], $decision->grants);
        self::assertSame([['rule' => 0, 'reason' => MappingDecision::REFUSED_FOREIGN_UNIT]], $decision->refusals);
    }

    /** No mapping may ever produce site administration. */
    public function testARuleNamingARoleCarryingSiteAdministrationGrantsNothing(): void
    {
        $rules = MappingRuleSet::of([[
            'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => 'LMS-Sales', 'value_kind' => 'name'],
            'then' => ['org_id' => self::ORGANISATION, 'org_role' => 'site_god'],
            'stop' => false,
        ]]);

        $identity = AssertedIdentity::asserted(
            'https://idp.example.test',
            'subject-escalation',
            [AssertedGroup::of('LMS-Sales', AssertedKeyKind::NAME)],
        );

        $decision = (new MappingRuleEvaluator())->evaluate($rules, $identity, $this->bound(), true);

        self::assertSame([], $decision->grants);
        self::assertSame([['rule' => 0, 'reason' => MappingDecision::REFUSED_ROLE]], $decision->refusals);
    }

    /** An id asserted against a rule written for names is a comparison that never applied. */
    public function testAKeyKindMismatchIsRecordedRatherThanMatched(): void
    {
        $identity = AssertedIdentity::asserted(
            'local:host:' . self::ORGANISATION,
            'subject-kind',
            [AssertedGroup::hostGroup('LMS-Sales')],
        );

        $decision = (new MappingRuleEvaluator())->evaluate($this->salesRule(), $identity, $this->bound(), true);

        self::assertSame([], $decision->grants);
        self::assertSame([['rule' => 0, 'reason' => MappingDecision::REFUSED_KEY_KIND]], $decision->refusals);
    }

    /** The first rule carrying stop ends the pass, which is what makes the order semantic. */
    public function testStopEndsThePass(): void
    {
        $rules = MappingRuleSet::of([
            [
                'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => 'LMS-Sales', 'value_kind' => 'name'],
                'then' => ['org_id' => self::ORGANISATION, 'org_role' => 'org_member'],
                'stop' => true,
            ],
            [
                'when' => ['attribute' => 'groups', 'op' => 'pr', 'value_kind' => 'name'],
                'then' => ['org_id' => self::ORGANISATION, 'org_role' => 'org_admin'],
                'stop' => false,
            ],
        ]);

        $identity = AssertedIdentity::asserted(
            'https://idp.example.test',
            'subject-stop',
            [AssertedGroup::of('LMS-Sales', AssertedKeyKind::NAME)],
        );

        $decision = (new MappingRuleEvaluator())->evaluate($rules, $identity, $this->bound(), true);

        self::assertCount(1, $decision->grants);
        self::assertSame('org_member', $decision->grants[0]->roleKey);
    }

    /** A shape this build cannot read grants nothing and says why. */
    public function testAnUnreadableRuleSetGrantsNothing(): void
    {
        $rules = MappingRuleSet::fromStored(['_v' => 99, 'rules' => [['when' => [], 'then' => []]]]);

        self::assertFalse($rules->isReadable());

        $identity = AssertedIdentity::asserted(
            'https://idp.example.test',
            'subject-unreadable',
            [AssertedGroup::of('LMS-Sales', AssertedKeyKind::NAME)],
        );

        $decision = (new MappingRuleEvaluator())->evaluate($rules, $identity, $this->bound(), true);

        self::assertSame([], $decision->grants);
        self::assertSame([['rule' => -1, 'reason' => MappingDecision::REFUSED_UNREADABLE]], $decision->refusals);
    }

    /** value_kind is mandatory on a group-shaped attribute and refused on any other. */
    public function testAGroupRuleWithoutAKeyKindIsRefusedAtSave(): void
    {
        $this->expectException(ValidationException::class);

        MappingRuleSet::of([[
            'when' => ['attribute' => 'groups', 'op' => 'eq', 'value' => 'LMS-Sales'],
            'then' => ['org_id' => self::ORGANISATION, 'org_role' => 'org_member'],
        ]]);
    }

    public function testAKeyKindOnANonGroupAttributeIsRefusedAtSave(): void
    {
        $this->expectException(ValidationException::class);

        MappingRuleSet::of([[
            'when' => ['attribute' => 'department', 'op' => 'eq', 'value' => 'Sales', 'value_kind' => 'name'],
            'then' => ['org_id' => self::ORGANISATION, 'org_role' => 'org_member'],
        ]]);
    }

    /** The stored shape round trips, envelope and all. */
    public function testTheStoredShapeRoundTrips(): void
    {
        $stored = $this->salesRule()->toStored();

        self::assertSame(MappingRuleSet::SHAPE_VERSION, $stored['_v']);

        $back = MappingRuleSet::fromStored($stored);

        self::assertTrue($back->isReadable());
        self::assertSame(1, $back->count());
        self::assertSame('groups eq LMS-Sales [name]', $back->rules[0]->describe());
        self::assertSame(41, $back->rules[0]->orgUnitId);
    }

    /** A bounded pattern is bounded at save, and a back reference is not stored at all. */
    public function testABackReferencePatternIsRefusedAtSave(): void
    {
        $this->expectException(ValidationException::class);

        MappingRuleSet::of([[
            'when' => ['attribute' => 'groups', 'op' => 're', 'value' => '(a+)\\1b', 'value_kind' => 'name'],
            'then' => ['org_id' => self::ORGANISATION, 'org_role' => 'org_member'],
        ]]);
    }

    /** The decision is what the explainer stores, so it carries its own version. */
    public function testTheDecisionStoresItsOwnShapeVersion(): void
    {
        $identity = AssertedIdentity::asserted(
            'https://idp.example.test',
            'subject-store',
            [AssertedGroup::of('LMS-Sales', AssertedKeyKind::NAME)],
        );

        $stored = (new MappingRuleEvaluator())
            ->evaluate($this->salesRule(), $identity, $this->bound(), true)
            ->toStored();

        self::assertSame(MappingDecision::SHAPE_VERSION, $stored['_v']);
        self::assertSame('subject-store', $stored['subject']);
        self::assertTrue($stored['may_remove']);
        self::assertSame([[
            'organisation_id' => self::ORGANISATION,
            'org_unit_id' => 41,
            'role_key' => 'org_member',
            'rule' => 0,
            'matched' => 'LMS-Sales',
        ]], $stored['grants']);
    }
}
