<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\DomainStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityLinkState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IssuerStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingRuleSet;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgConnection;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgDomain;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgIdentityLink;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgJoinRuleRow;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityKey;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The persistence home for the four identity tables.
 *
 * One store rather than four, on the same grounds OrganisationLearningStore is
 * one for the seat set: the four tables are read together on one screen, three
 * of them are keyed on the organisation and the fourth is the lookup the other
 * three are resolved through, and four classes would be four places to get the
 * tenant predicate subtly different.
 *
 * Every organisation-anchored read starts at OrgScopedReader, which injects
 * the tenant predicate from a scope an authorisation produced. The two
 * exceptions are enumerated here and on the gate's own witness list, and both
 * are the same kind of read: a lookup that **mints** a tenant rather than
 * consuming one.
 *
 * - {@see linkFor()} finds a person by the pair, and the pair is the identity.
 *   Narrowing it on an organisation the caller supplied would be asking the
 *   answer to supply the question, and would also let a caller aim a valid
 *   assertion at the wrong tenant.
 * - {@see verifiedHolderOf()} asks which organisation, if any, holds an issuer
 *   verified. It is a global question by construction: the whole purpose of
 *   the global unique index is that the answer is at most one organisation
 *   across the entire site, and a read narrowed to one organisation could
 *   never see the collision it exists to detect.
 */
final class OrgIdentityStore
{
    public const CONNECTIONS = 'org_connections';
    public const LINKS = 'org_identity_links';
    public const DOMAINS = 'org_domains';
    public const JOIN_RULES = 'org_join_rules';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    // ── Connections ──────────────────────────────────────────────────────

    /**
     * This organisation's connections, oldest first, which is the order they
     * were claimed in and the order the screen lists them in.
     *
     * @return list<OrgConnection>
     */
    public function connections(OrgScope $scope): array
    {
        $rows = $this->database->selectAll(
            OrgScopedReader::from($scope, self::CONNECTIONS)->orderBy('id')
        );

        return array_map($this->mapConnection(...), $rows);
    }

    /** One of this organisation's connections, or null for a foreign or missing one. */
    public function connection(OrgScope $scope, int $id): ?OrgConnection
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->database->selectOne(
            OrgScopedReader::from($scope, self::CONNECTIONS)->where('id', '=', $id)
        );

        return $row === null ? null : $this->mapConnection($row);
    }

    /**
     * One connection belonging to any organisation under a given root.
     *
     * The second read in this file that is not narrowed by the caller's own
     * tenant, and for a stated reason: delegation is the one identity fact
     * whose subject is deliberately another organisation's row. A subsidiary
     * attaches to its parent's verified connection instead of claiming the
     * issuer, so the question is "is this connection inside my own corporate
     * group", and a predicate on the caller's organisation would answer no by
     * construction and make the feature unreachable.
     *
     * The root is the boundary and it is in the predicate, not checked
     * afterwards: a connection belonging to an organisation under a different
     * root is not found here at all, which is the same shape as a foreign
     * organisation's row being invisible to `connection()` above.
     */
    public function connectionUnderRoot(int $rootId, int $connectionId): ?OrgConnection
    {
        if ($rootId < 1 || $connectionId < 1) {
            return null;
        }

        $row = $this->database->selectOne(
            Query::from(self::CONNECTIONS, 'c')
                ->select(['c.*'])
                ->join('organisations', 'o', 'c.organisation_id', 'o.id')
                ->where('c.id', '=', $connectionId)
                ->where('o.root_id', '=', $rootId)
        );

        return $row === null ? null : $this->mapConnection($row);
    }

    /**
     * Which organisation holds this issuer verified, if any.
     *
     * The one read in this file that cannot be narrowed by a tenant, because
     * "at most one organisation across the whole site" is the question. It
     * returns the connection rather than a boolean, so a caller can name the
     * holder in a refusal without a second read, and it reads only the
     * verified key, which is null on every row that has not proved the claim.
     */
    public function verifiedHolderOf(string $issuer): ?OrgConnection
    {
        $row = $this->database->selectOne(
            Query::from(self::CONNECTIONS)->where('verified_issuer_key', '=', $issuer)
        );

        return $row === null ? null : $this->mapConnection($row);
    }

    /**
     * The organisations a provisioning sweep has any reason to visit.
     *
     * An organisation with no verified connection has nothing for a directory
     * to assert through, so sweeping it would be a read per source per tenant
     * for a tenant that cannot be provisioned. The sweep is anchored on the
     * connection rather than on the organisation for that reason, and it means
     * this read is the same shape the four shipped sweeps have: it asks across
     * every organisation and the caller recovers the tenant per row.
     *
     * @return list<int>
     */
    public function organisationsWithVerifiedConnections(int $limit): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::CONNECTIONS)
                ->select(['organisation_id'])
                ->where('issuer_status', '=', IssuerStatus::VERIFIED)
                ->where('state', '=', 'active')
                ->orderBy('organisation_id')
                ->limit(max(1, $limit))
        );

        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row['organisation_id'] ?? 0);

            if ($id > 0 && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insertConnection(array $values): int
    {
        return $this->database->insert(self::CONNECTIONS, $values + $this->stamps());
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateConnection(OrgScope $scope, int $id, array $values): int
    {
        return $this->database->update(
            self::CONNECTIONS,
            $values + ['updated_at' => $this->now()],
            ['id' => $id, 'organisation_id' => $scope->organisationId],
        );
    }

    /**
     * Every unit this organisation has, as ids.
     *
     * It is the subtree bound a mapping rule is checked against, at save and
     * again at evaluation, and it is read here rather than derived from the
     * closure table's anchor because "every unit of this organisation" has no
     * anchor: a rule may name any unit in the tenant and none outside it.
     *
     * @return list<int>
     */
    public function unitIds(OrgScope $scope): array
    {
        $rows = $this->database->selectAll(
            OrgScopedReader::from($scope, 'org_units')->select(['id'])
        );

        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        sort($ids);

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    // ── Identity links ───────────────────────────────────────────────────

    /**
     * The link for one asserted identity, found by the pair and nothing else.
     *
     * The comparison is on the digest rather than on the subject string, so it
     * is byte exact whatever collation the host installed the table with, and
     * so a subject that differs only in bytes a text collation folds together
     * is a different identity here.
     */
    public function linkFor(string $issuer, string $subject): ?OrgIdentityLink
    {
        $row = $this->database->selectOne(
            Query::from(self::LINKS)
                ->where('issuer', '=', $issuer)
                ->where('identity_key', '=', IdentityKey::of($issuer, $subject))
        );

        return $row === null ? null : $this->mapLink($row);
    }

    /**
     * The link one connection's own external id already names, or null.
     *
     * Rule two of the published match order, and it is bounded to one
     * connection on purpose: an external id is the issuing directory's word
     * for one of its own objects, so the same string from two directories is
     * two different people and matching across them would be the duplicate
     * this whole area is designed against, inverted.
     */
    public function linkByExternalId(int $connectionId, string $externalId): ?OrgIdentityLink
    {
        if ($connectionId < 1 || trim($externalId) === '') {
            return null;
        }

        $row = $this->database->selectOne(
            Query::from(self::LINKS)
                ->where('connection_id', '=', $connectionId)
                ->where('external_id', '=', $externalId)
                ->orderBy('id')
        );

        return $row === null ? null : $this->mapLink($row);
    }

    /**
     * This organisation's links, newest first.
     *
     * @return list<OrgIdentityLink>
     */
    public function links(OrgScope $scope, ?string $state = null, int $limit = 50): array
    {
        $query = OrgScopedReader::from($scope, self::LINKS);

        if ($state !== null) {
            $query = $query->where('state', '=', $state);
        }

        $rows = $this->database->selectAll($query->orderBy('id', Query::SORT_DESC)->limit(max(1, $limit)));

        return array_map($this->mapLink(...), $rows);
    }

    /** One of this organisation's links, or null. */
    public function link(OrgScope $scope, int $id): ?OrgIdentityLink
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->database->selectOne(
            OrgScopedReader::from($scope, self::LINKS)->where('id', '=', $id)
        );

        return $row === null ? null : $this->mapLink($row);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insertLink(array $values): int
    {
        return $this->database->insert(self::LINKS, $values + $this->stamps());
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateLink(OrgScope $scope, int $id, array $values): int
    {
        return $this->database->update(
            self::LINKS,
            $values + ['updated_at' => $this->now()],
            ['id' => $id, 'organisation_id' => $scope->organisationId],
        );
    }

    // ── Domains ──────────────────────────────────────────────────────────

    /**
     * @return list<OrgDomain>
     */
    public function domains(OrgScope $scope): array
    {
        $rows = $this->database->selectAll(
            OrgScopedReader::from($scope, self::DOMAINS)->orderBy('domain')
        );

        return array_map($this->mapDomain(...), $rows);
    }

    public function domain(OrgScope $scope, int $id): ?OrgDomain
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->database->selectOne(
            OrgScopedReader::from($scope, self::DOMAINS)->where('id', '=', $id)
        );

        return $row === null ? null : $this->mapDomain($row);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insertDomain(array $values): int
    {
        return $this->database->insert(self::DOMAINS, $values + $this->stamps());
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateDomain(OrgScope $scope, int $id, array $values): int
    {
        return $this->database->update(
            self::DOMAINS,
            $values + ['updated_at' => $this->now()],
            ['id' => $id, 'organisation_id' => $scope->organisationId],
        );
    }

    /**
     * The domain claims due a check, oldest check first, across every
     * organisation.
     *
     * The sweep asks across all of them and recovers the tenant per row, which
     * is the shape the four shipped organisation sweeps already have, so it
     * reads through the swept-record path rather than through a scope it does
     * not have.
     *
     * **Pending and failed rows are due as well as verified ones**, and reading
     * only the verified ones was the reason no claim could ever reach verified
     * in any edition: this was the only sweep, the task was the only writer of
     * the status, and `ManageOrganisationJoining` exposes claim and revoke and
     * no verify. Three of the eight published transitions had no writer at all,
     * and all three of them were promotions. A revoked claim stays out, because
     * revoked is terminal in the published machine.
     *
     * @return list<array<string, mixed>>
     */
    public function domainsDueRecheck(\DateTimeImmutable $before, int $limit): array
    {
        return $this->database->selectAll(
            Query::from(self::DOMAINS)
                ->whereAnyOf(
                    Predicate::compare('status', '=', DomainStatus::VERIFIED),
                    Predicate::compare('status', '=', DomainStatus::PENDING),
                    Predicate::compare('status', '=', DomainStatus::FAILED),
                )
                ->whereAnyOf(
                    // Inclusive, because the sweep that reads this runs on a day
                    // bucket: a daily run checks a row exactly RECHECK_HOURS
                    // after the last one, and a strict comparison made every
                    // second day report checked=0. A record deleted from a zone
                    // then took six days to demote on a three-failure threshold
                    // rather than three.
                    Predicate::compare('last_checked_at', '<=', $before->format('Y-m-d H:i:s')),
                    Predicate::isNull('last_checked_at'),
                )
                ->orderBy('last_checked_at', Query::SORT_ASC, true)
                ->limit(max(1, $limit))
        );
    }

    /**
     * The re-check's own write, which is the only writer that demotes a
     * verified domain.
     *
     * It takes the organisation from the row it swept rather than from a
     * caller, which is what makes a sweep across every organisation still a
     * per-tenant write.
     *
     * @param array<string, mixed> $values
     */
    public function recordRecheck(int $organisationId, int $domainId, array $values): int
    {
        return $this->database->update(
            self::DOMAINS,
            $values + ['updated_at' => $this->now()],
            ['id' => $domainId, 'organisation_id' => $organisationId],
        );
    }

    // ── Join rules ───────────────────────────────────────────────────────

    /**
     * @return list<OrgJoinRuleRow>
     */
    public function joinRules(OrgScope $scope): array
    {
        $rows = $this->database->selectAll(
            OrgScopedReader::from($scope, self::JOIN_RULES)->orderBy('id')
        );

        return array_map($this->mapJoinRule(...), $rows);
    }

    /**
     * The rule in force for this organisation right now, or null.
     *
     * Null is not "anyone may join": the acceptance path reads the registry
     * for the key this returns, and a null falls back to the registry's own
     * default key, which is invitation only. A row that has not started or has
     * finished is not in force, which is what the two dates are for.
     */
    public function activeJoinRule(OrgScope $scope, \DateTimeImmutable $now): ?OrgJoinRuleRow
    {
        $rows = $this->database->selectAll(
            OrgScopedReader::from($scope, self::JOIN_RULES)
                ->where('state', '=', 'active')
                ->orderBy('id', Query::SORT_DESC)
                ->limit(20)
        );

        foreach ($rows as $row) {
            $rule = $this->mapJoinRule($row);

            if ($rule->isInForce($now)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insertJoinRule(array $values): int
    {
        return $this->database->insert(self::JOIN_RULES, $values + $this->stamps());
    }

    /**
     * @param array<string, mixed> $values
     */
    public function updateJoinRule(OrgScope $scope, int $id, array $values): int
    {
        return $this->database->update(
            self::JOIN_RULES,
            $values + ['updated_at' => $this->now()],
            ['id' => $id, 'organisation_id' => $scope->organisationId],
        );
    }

    // ── Mapping ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function mapConnection(array $row): OrgConnection
    {
        return OrgConnection::fromRow(
            (int) ($row['id'] ?? 0),
            (int) ($row['organisation_id'] ?? 0),
            (string) ($row['protocol'] ?? ''),
            (string) ($row['dialect'] ?? ''),
            (string) ($row['issuer'] ?? ''),
            (string) ($row['issuer_status'] ?? IssuerStatus::PENDING),
            $this->nullableInt($row['delegated_from_connection_id'] ?? null),
            (bool) ($row['authoritative'] ?? false),
            (bool) ($row['jit_enabled'] ?? false),
            (string) ($row['default_completeness'] ?? 'partial'),
            $this->nullableString($row['secret_ref'] ?? null),
            MappingRuleSet::fromStored($this->json($row['mapping_rules'] ?? null)),
            $this->body($row['attribute_overrides'] ?? null, $row['attribute_overrides_version'] ?? null),
            (string) ($row['state'] ?? 'active'),
            $this->nullableString($row['issuer_verified_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapLink(array $row): OrgIdentityLink
    {
        return OrgIdentityLink::fromRow(
            (int) ($row['id'] ?? 0),
            $this->nullableInt($row['organisation_id'] ?? null),
            $this->nullableInt($row['connection_id'] ?? null),
            (string) ($row['issuer'] ?? ''),
            (string) ($row['subject'] ?? ''),
            (int) ($row['user_id'] ?? 0),
            $this->nullableString($row['external_id'] ?? null),
            (string) ($row['match_basis'] ?? ''),
            $this->nullableString($row['match_ref'] ?? null),
            (string) ($row['state'] ?? IdentityLinkState::ACTIVE),
            $this->nullableString($row['first_seen_at'] ?? null),
            $this->nullableString($row['last_asserted_at'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapDomain(array $row): OrgDomain
    {
        return OrgDomain::fromRow(
            (int) ($row['id'] ?? 0),
            (int) ($row['organisation_id'] ?? 0),
            (string) ($row['domain'] ?? ''),
            (string) ($row['method'] ?? 'dns-txt'),
            (string) ($row['token'] ?? ''),
            (string) ($row['status'] ?? DomainStatus::PENDING),
            $this->nullableString($row['verified_at'] ?? null),
            $this->nullableString($row['last_checked_at'] ?? null),
            (int) ($row['failure_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapJoinRule(array $row): OrgJoinRuleRow
    {
        return OrgJoinRuleRow::fromRow(
            (int) ($row['id'] ?? 0),
            (int) ($row['organisation_id'] ?? 0),
            (string) ($row['rule_key'] ?? 'invitation_only'),
            $this->body($row['params'] ?? null, $row['params_version'] ?? null),
            (string) ($row['role_key'] ?? 'org_member'),
            (string) ($row['state'] ?? 'active'),
            $this->nullableString($row['effective_from'] ?? null),
            $this->nullableString($row['effective_to'] ?? null),
        );
    }

    /**
     * A stored JSON column, envelope and all.
     *
     * It stays the envelope because {@see MappingRuleSet::fromStored()} reads
     * the version out of it and refuses a shape this build cannot read. Every
     * other consumer wants the body, which is {@see body()}.
     *
     * @return array<string, mixed>
     */
    private function json(mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * A stored JSON column, unwrapped, checked against the version beside it.
     *
     * `org_join_rules.params` and `org_connections.attribute_overrides` are
     * both written through a StoredShape envelope with the version mirrored in
     * a column, and both used to be handed to their consumer as the envelope:
     * a provider reading a join rule's params got a bag carrying the reserved
     * `_v` key, and nothing anywhere consulted the version column.
     * ProvisioningWatermarks, ComplianceWindowTask and MappingRuleSet all
     * unwrap on the way out; these two were the readers that did not.
     *
     * A body whose envelope version disagrees with the mirrored column is not
     * this shape, and it is answered empty rather than handed on: an override
     * bag from a shape nobody can read must configure nothing, which is the
     * same fail-closed direction the rule set already takes.
     *
     * @return array<string, mixed>
     */
    private function body(mixed $value, mixed $version): array
    {
        $stored = $this->json($value);

        if ($stored === []) {
            return [];
        }

        $declared = $version === null || $version === '' ? null : (int) $version;

        if ($declared !== null && StoredShape::versionOf($stored) !== $declared) {
            return [];
        }

        return StoredShape::bodyOf($stored);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, string>
     */
    private function stamps(): array
    {
        $now = $this->now();

        return ['created_at' => $now, 'updated_at' => $now];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
