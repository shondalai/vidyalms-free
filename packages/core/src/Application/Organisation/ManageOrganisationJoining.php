<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\DomainStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgDomain;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgJoinRuleRow;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Identity\DomainVerificationPort;
use Shondalai\VidyaLMS\Core\Port\Identity\UnverifiedDomains;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Registry\OrgJoinRule\OrgJoinRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Domain claims and join rules: the two halves of "how does somebody get in".
 *
 * They are one use case because they are one decision seen twice. A domain
 * claim says which organisation an address belongs to; a join rule says what
 * that entitles anybody to. Splitting them would let a screen offer a verified
 * domain with nothing attached to it, which is the configuration that looks
 * like a feature and does nothing.
 *
 * **The free build records domains and shows them unverified.** That is the
 * registry-miss contract in force and not a padlock: the row exists, the proof
 * record is composed and shown, and the day a verifier is installed the same
 * row is provable with no migration and no second table. What the free build
 * refuses to do is pretend: `verifiable` is false on every row and the screen
 * says so rather than offering a button that always fails.
 *
 * **Auto-join is a row and never a flag.** The rule carries an owner, an
 * effective window, the role it grants, its state and a trail, and every join
 * it causes writes an event naming it. The free build registers exactly one
 * rule, invitation only, and this row is what makes that a selection rather
 * than a constant three call sites happened to agree on.
 */
final class ManageOrganisationJoining
{
    /** The right that claims a domain or writes a join rule. */
    public const ACTION = 'settings';

    /** The right that reads either. */
    public const READ_ACTION = 'read';

    /** How many claims one organisation may hold at once. */
    public const MAX_DOMAINS = 50;

    public function __construct(
        private readonly OrganisationAccess $access,
        private readonly OrgIdentityStore $store,
        private readonly OrganisationStore $organisations,
        private readonly OrgJoinRuleRegistry $joinRules,
        private readonly OrgRoleRegistry $roles,
        private readonly DomainVerificationPort $verification,
        private readonly Settings $settings,
        private readonly RandomPort $random,
        private readonly ClockPort $clock,
    ) {
    }

    // ── Domains ──────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function domains(Principal $actor, int $orgId): array
    {
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        $canVerify = $this->canVerify();
        // The proof token travels only to a reader who could claim a domain
        // themselves. It is the whole proof, so handing it to every holder of
        // the organisation read right gave a reporter and a subtree manager the
        // means to prove this domain from any zone they control.
        $mayClaim = $scope->siteManager
            || \in_array(self::ACTION, $this->roles->actionsOf($scope->roles, $scope->organisationId), true);

        return array_map(
            static fn (OrgDomain $domain): array => $domain->toArray($canVerify, $mayClaim),
            $this->store->domains($scope)
        );
    }

    /**
     * Records a claim, pending.
     *
     * Three refusals before anything is written, and each is a different
     * failure: a shape nobody could ever prove, a public mailbox domain whose
     * claim would let one organisation admit half the site, and a claim this
     * organisation already holds.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function claimDomain(Principal $actor, int $orgId, array $input): array
    {
        OrganisationInput::only($input, ['domain', 'method']);
        $scope = $this->access->require($actor, $orgId, self::ACTION);
        $domain = DomainStatus::normalise(\is_string($input['domain'] ?? null) ? $input['domain'] : '');

        if (!DomainStatus::isClaimable($domain)) {
            throw ValidationException::forField('domain', 'That is not a domain a claim can be proved on.');
        }

        if ($this->isPublicMailbox($domain)) {
            throw ValidationException::forField(
                'domain',
                'A public mailbox domain cannot be claimed by an organisation.'
            );
        }

        $method = \is_string($input['method'] ?? null) ? $input['method'] : 'dns-txt';

        if (!DomainStatus::isMethod($method)) {
            throw ValidationException::forField('method', 'Unknown verification method.');
        }

        $existing = $this->store->domains($scope);

        if (\count($existing) >= self::MAX_DOMAINS) {
            throw new ConflictException('This organisation already holds the maximum number of domain claims.');
        }

        foreach ($existing as $claim) {
            if ($claim->domain === $domain && $claim->status !== DomainStatus::REVOKED) {
                throw new ConflictException('This organisation already claims that domain.');
            }
        }

        $id = $this->store->insertDomain([
            'organisation_id' => $scope->organisationId,
            'domain' => $domain,
            'verified_key' => null,
            'method' => $method,
            // An opaque value from the host's own randomness, minted once. It
            // is published in the proof record and proves possession of the
            // zone, so it is never derived from anything guessable and never
            // from the domain itself.
            'token' => $this->random->verificationCode(32),
            'status' => DomainStatus::PENDING,
            'failure_count' => 0,
            'created_by' => $scope->actorId,
        ]);

        $this->record($scope, MembershipEvent::GOVERNANCE_CHANGED, [
            'domain_id' => $id,
            'domain' => $domain,
            'status' => DomainStatus::PENDING,
        ]);

        $claim = $this->store->domain($scope, $id) ?? throw new EntityNotFoundException('Domain claim not found.');

        return $claim->toArray($this->canVerify());
    }

    /**
     * Withdraws a claim.
     *
     * Revoking is the only transition an organisation makes on its own claim.
     * Verifying one is the verifier's business and demoting one is the
     * re-check's, because a claim an organisation could mark verified is a
     * claim nobody proved.
     *
     * @return array<string, mixed>
     */
    public function revokeDomain(Principal $actor, int $orgId, int $domainId): array
    {
        $scope = $this->access->require($actor, $orgId, self::ACTION);
        $claim = $this->store->domain($scope, $domainId)
            ?? throw new EntityNotFoundException('Domain claim not found.');

        if (!DomainStatus::permits($claim->status, DomainStatus::REVOKED)) {
            throw new ConflictException('That claim has already been withdrawn.');
        }

        $this->store->updateDomain($scope, $claim->id, [
            'status' => DomainStatus::REVOKED,
            // The verified key is released in the same statement that revokes
            // the claim, so the next organisation to prove the domain is not
            // blocked by a claim that has been withdrawn.
            'verified_key' => null,
        ]);

        $this->record($scope, MembershipEvent::GOVERNANCE_CHANGED, [
            'domain_id' => $claim->id,
            'domain' => $claim->domain,
            'status' => DomainStatus::REVOKED,
        ]);

        $revoked = $this->store->domain($scope, $claim->id);

        return ($revoked ?? $claim)->toArray($this->canVerify());
    }

    // ── Join rules ───────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function joinRules(Principal $actor, int $orgId): array
    {
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);

        return array_map(
            fn (OrgJoinRuleRow $rule): array => $rule->toArray($this->joinRules->has($rule->ruleKey)),
            $this->store->joinRules($scope)
        );
    }

    /**
     * Writes a join rule.
     *
     * The key must resolve now: a rule naming a provider nothing on this site
     * registers admits nobody, and writing one silently would leave an
     * operator believing they had opened a door they had not. That is the
     * fail-closed direction stated as a refusal at save rather than as a
     * surprise at acceptance.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function addJoinRule(Principal $actor, int $orgId, array $input): array
    {
        OrganisationInput::only($input, [
            'rule_key',
            'role_key',
            'params',
            'effective_from',
            'effective_to',
        ]);
        $scope = $this->access->require($actor, $orgId, self::ACTION);
        $key = \is_string($input['rule_key'] ?? null) ? trim($input['rule_key']) : '';

        if ($key === '' || !$this->joinRules->has($key)) {
            throw ValidationException::forField('rule_key', 'No installed provider answers that join rule.');
        }

        $roleKey = \is_string($input['role_key'] ?? null) ? trim($input['role_key']) : 'org_member';

        if (!$this->roles->availableFor($roleKey, $scope->organisationId)) {
            throw ValidationException::forField('role_key', 'No such organisation role.');
        }

        if (!$this->roles->get($roleKey)->grantableByMappingRule()) {
            // The same rule the mapping evaluator enforces, for the same
            // reason: a rule that admits somebody may never make them the
            // owner of the tenant it admits them to.
            throw ValidationException::forField('role_key', 'forbidden_scope');
        }

        $params = \is_array($input['params'] ?? null) ? $input['params'] : [];

        $id = $this->store->insertJoinRule([
            'organisation_id' => $scope->organisationId,
            'rule_key' => $key,
            'params' => json_encode(StoredShape::wrap($params, 1), JSON_THROW_ON_ERROR),
            'params_version' => 1,
            'role_key' => $roleKey,
            'state' => 'active',
            'effective_from' => $this->stamp($input['effective_from'] ?? null, 'effective_from'),
            'effective_to' => $this->stamp($input['effective_to'] ?? null, 'effective_to'),
            'created_by' => $scope->actorId,
        ]);

        $this->record($scope, MembershipEvent::GOVERNANCE_CHANGED, [
            'join_rule_id' => $id,
            'rule_key' => $key,
            'role_key' => $roleKey,
        ]);

        return $this->joinRule($scope, $id);
    }

    /**
     * Retires a rule.
     *
     * A rule is retired and never deleted, because the events it caused name
     * it and an event pointing at a row nobody can read is an audit trail with
     * a hole in it.
     *
     * @return array<string, mixed>
     */
    public function retireJoinRule(Principal $actor, int $orgId, int $ruleId): array
    {
        $scope = $this->access->require($actor, $orgId, self::ACTION);
        $rules = $this->store->joinRules($scope);

        foreach ($rules as $rule) {
            if ($rule->id !== $ruleId) {
                continue;
            }

            if ($rule->state !== 'active') {
                throw new ConflictException('That rule is already retired.');
            }

            $this->store->updateJoinRule($scope, $rule->id, ['state' => 'retired']);
            $this->record($scope, MembershipEvent::GOVERNANCE_CHANGED, [
                'join_rule_id' => $rule->id,
                'rule_key' => $rule->ruleKey,
                'state' => 'retired',
            ]);

            return $this->joinRule($scope, $rule->id);
        }

        throw new EntityNotFoundException('Join rule not found.');
    }

    /**
     * The rule key in force for this organisation, for the acceptance path.
     *
     * It falls back to the registry's own default rather than to null, because
     * an organisation with no row is an organisation nobody has configured,
     * and the free default is the strict one. A key nothing resolves is
     * returned as it is and refused by the registry, which is the published
     * fail-closed direction: a gate nobody can answer answers no.
     */
    public function ruleKeyInForce(OrgScope $scope): string
    {
        $rule = $this->store->activeJoinRule($scope, $this->clock->now());

        return $rule === null ? OrgJoinRuleRegistry::DEFAULT_KEY : $rule->ruleKey;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function joinRule(OrgScope $scope, int $id): array
    {
        foreach ($this->store->joinRules($scope) as $rule) {
            if ($rule->id === $id) {
                return $rule->toArray($this->joinRules->has($rule->ruleKey));
            }
        }

        throw new EntityNotFoundException('Join rule not found.');
    }

    /**
     * Whether anything installed can actually prove a domain claim.
     *
     * The probe is against the shipped refusing default rather than against a
     * capability interface, because "can this be verified at all" is a
     * question about the port that is wired and not about a provider's
     * declared abilities. It is asked in one place so a screen and a claim
     * cannot disagree about it.
     */
    private function canVerify(): bool
    {
        return !($this->verification instanceof UnverifiedDomains);
    }

    /**
     * Whether this domain is one no organisation may claim.
     *
     * The list is a settings row over a shipped default rather than a compiled
     * constant, because consumer mail providers come and go and an operator
     * should be able to add one without waiting for a release. A stored row
     * that is not a list of strings is ignored in favour of the shipped list,
     * which is the safe direction: a misconfigured exclusion list must not
     * become an empty one.
     */
    private function isPublicMailbox(string $domain): bool
    {
        $stored = $this->settings->getArray(DomainStatus::EXCLUSION_SETTING, []);
        $list = [];

        foreach ($stored as $entry) {
            if (\is_string($entry) && trim($entry) !== '') {
                $list[] = DomainStatus::normalise($entry);
            }
        }

        if ($list === []) {
            $list = DomainStatus::DEFAULT_EXCLUSIONS;
        }

        return \in_array($domain, $list, true);
    }

    private function stamp(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_string($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'A date is a string.');
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($parsed === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'Use "Y-m-d" or "Y-m-d H:i:s".');
        }

        return $parsed->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(OrgScope $scope, string $key, array $details): void
    {
        $organisation = $this->organisations->organisation($scope);
        $this->organisations->event(
            $scope,
            (int) ($organisation['revision'] ?? 1),
            $key,
            null,
            $details,
            MembershipEventContext::manual(),
        );
    }
}
