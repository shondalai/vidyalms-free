<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

use Shondalai\VidyaLMS\Core\Port\Identity\AssertedCompleteness;

/**
 * One organisation's claim on an issuer, as a read model.
 *
 * It carries no certificate, no secret and no token, which is not an omission:
 * the table has no such column, and this class could not carry one if it
 * wanted to. secret_ref is a reference in the host's own secret grammar and is
 * exposed only so a screen can say which reference a connection uses.
 *
 * The three questions everything asks of a connection are methods here rather
 * than comparisons at call sites, because each of them is a rule and a rule
 * written twice is a rule with two behaviours: whether it drives anything,
 * whether it may remove anything, and whether it may create an account.
 */
final class OrgConnection
{
    /**
     * @param array<string, mixed> $attributeOverrides
     */
    private function __construct(
        public readonly int $id,
        public readonly int $organisationId,
        public readonly string $protocol,
        public readonly string $dialect,
        public readonly string $issuer,
        public readonly string $issuerStatus,
        public readonly ?int $delegatedFromConnectionId,
        public readonly bool $authoritative,
        public readonly bool $jitEnabled,
        public readonly string $defaultCompleteness,
        public readonly ?string $secretRef,
        public readonly MappingRuleSet $rules,
        public readonly array $attributeOverrides,
        public readonly string $state,
        public readonly ?string $issuerVerifiedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $attributeOverrides
     */
    public static function fromRow(
        int $id,
        int $organisationId,
        string $protocol,
        string $dialect,
        string $issuer,
        string $issuerStatus,
        ?int $delegatedFromConnectionId,
        bool $authoritative,
        bool $jitEnabled,
        string $defaultCompleteness,
        ?string $secretRef,
        MappingRuleSet $rules,
        array $attributeOverrides,
        string $state,
        ?string $issuerVerifiedAt,
    ): self {
        return new self(
            $id,
            $organisationId,
            $protocol,
            $dialect,
            $issuer,
            IssuerStatus::isKnown($issuerStatus) ? $issuerStatus : IssuerStatus::PENDING,
            $delegatedFromConnectionId,
            $authoritative,
            $jitEnabled,
            AssertedCompleteness::isKnown($defaultCompleteness)
                ? $defaultCompleteness
                : AssertedCompleteness::PARTIAL,
            $secretRef,
            $rules,
            $attributeOverrides,
            $state,
            $issuerVerifiedAt,
        );
    }

    /**
     * Whether this connection drives anything at all.
     *
     * Mapping rule evaluation, account creation and every directory write are
     * refused against a connection whose issuer is not verified, so an
     * unverified row is inert rather than dangerous. An archived connection is
     * equally inert: an administrator who turned one off did not mean "keep
     * applying its rules".
     */
    public function drivesProvisioning(): bool
    {
        return $this->state === 'active' && IssuerStatus::drivesProvisioning($this->issuerStatus);
    }

    /**
     * Whether this connection may drive a removal.
     *
     * Both halves, and the second is why authoritative is written only by a
     * site-scoped principal: an organisation that could mark its own
     * connection authoritative could remove people it never proved anything
     * about.
     */
    public function mayRemove(): bool
    {
        return $this->drivesProvisioning() && $this->authoritative;
    }

    /** Whether this connection may create an account for somebody it has never seen. */
    public function mayCreateAccounts(): bool
    {
        return $this->drivesProvisioning() && $this->jitEnabled;
    }

    /** Whether this row is claiming an issuer at all, or borrowing a parent's verified claim. */
    public function isDelegated(): bool
    {
        return $this->delegatedFromConnectionId !== null;
    }

    /**
     * What the console shows, with nothing secret in it.
     *
     * secret_ref is a reference and is shown, because an operator needs to see
     * which one a connection uses; the value it resolves to never reaches the
     * core at all.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organisation_id' => $this->organisationId,
            'protocol' => $this->protocol,
            'dialect' => $this->dialect,
            'issuer' => $this->issuer,
            'issuer_status' => $this->issuerStatus,
            'issuer_verified_at' => $this->issuerVerifiedAt,
            'delegated_from_connection_id' => $this->delegatedFromConnectionId,
            'authoritative' => $this->authoritative,
            'jit_enabled' => $this->jitEnabled,
            'default_completeness' => $this->defaultCompleteness,
            'secret_ref' => $this->secretRef,
            'mapping_rule_count' => $this->rules->count(),
            'mapping_rules_readable' => $this->rules->isReadable(),
            'state' => $this->state,
            'drives_provisioning' => $this->drivesProvisioning(),
            'may_remove' => $this->mayRemove(),
        ];
    }
}
