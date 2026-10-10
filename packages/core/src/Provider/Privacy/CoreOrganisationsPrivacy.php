<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Privacy;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRetentionPolicies;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\Middleware\OrgScopeMiddleware;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\LedgerRedactionStore;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Privacy\DescribesForArticle30;
use Shondalai\VidyaLMS\Core\Registry\Privacy\EnumeratesOrganisationUsers;
use Shondalai\VidyaLMS\Core\Registry\Privacy\EraseOutcome;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesWithOutcome;
use Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorInterface;
use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;
use Shondalai\VidyaLMS\Core\Registry\Privacy\SubjectPrivacyGroup;

/** Relationship records only. A shared learner/course history is never an employer-owned row. */
final class CoreOrganisationsPrivacy implements
    PrivacyContributorInterface,
    EnumeratesOrganisationUsers,
    DescribesForArticle30,
    ErasesWithOutcome
{
    /**
     * Why the two ledgers survive an erasure.
     *
     * A statutory training record is squarely GDPR Article 17(3)(b), and OSHA
     * 29 CFR 1910.1030(h)(2) is the concrete instance the design cites: three
     * years of bloodborne-pathogens training records, which a site deleting one
     * on a subject request has broken the employer's obligation over. The
     * reference is carried on the receipt so an auditor reads the reason rather
     * than a count.
     */
    public const LEDGER_STATUTE = 'GDPR Article 17(3)(b); e.g. OSHA 29 CFR 1910.1030(h)(2)';

    /**
     * The two record classes whose untouched rows are counted on the receipt.
     *
     * They are the two tables where a person legitimately holds rows under more
     * than one organisation and under none, which is what makes the count
     * evidence rather than decoration. Each is read by its literal name in
     * {@see eraseWithOutcome()}, so the tenancy scan can see every read: a table
     * named by a variable is a read the gate cannot check.
     */
    public const BOUNDED_ELSEWHERE = ['entitlements', 'seats'];

    public function __construct(
        private readonly DatabasePort $database,
        private readonly \Shondalai\VidyaLMS\Core\Port\Users\UsersPort $users,
        /**
         * How a ledger identity is pseudonymised without breaking the chain.
         *
         * Trailing and defaulted, so a caller built before the redaction receipt
         * existed keeps constructing this contributor; with none, the ledgers
         * keep their identities and the receipt says so in a warning rather than
         * claiming a pseudonymisation that did not happen.
         */
        private readonly ?LedgerRedactionStore $redactions = null,
        /**
         * The owning organisation own retention policy, or nothing.
         *
         * Trailing and defaulted, so a caller built before the policy had a
         * reader keeps constructing this contributor. With none, the receipt
         * carries this provider own statutory citation, which is the shipped
         * behaviour and the reason the finding existed: an organisation that
         * had named its own instrument was answered with somebody else's.
         */
        private readonly ?OrganisationRetentionPolicies $retention = null,
    ) {
    }

    public function key(): string
    {
        return 'core-organisations';
    }

    public function describes(): array
    {
        return ['memberships' => 'Organization memberships', 'roles' => 'Organization role assignments',
            'invitations' => 'Organization invitations', 'entitlements' => 'Organization course access',
            'seats' => 'Organization seat assignments', 'seat_events' => 'Organization seat ledger',
            'events' => 'Organization membership events',
            // The catalogue entry's author. The table was created with a
            // created_by naming a person and no contributor named it, so a
            // subject access request did not export what somebody had
            // published to an organisation and an erasure left their id on
            // every row of it.
            'catalogue_entries' => 'Organization catalogue entries'];
    }

    public function subjectGroups(int $userId, string $email): array
    {
        $groups = [];
        if ($userId > 0) {
            foreach ($this->describes() as $key => $label) {
                if ($key === 'invitations') {
                    continue;
                }
                [$query, $fields, $subjectColumn] = $this->definition($key);
                $query->select($fields)->where($subjectColumn, '=', $userId);
                if ($key === 'entitlements') {
                    $query->whereNotNull('organisation_id');
                }
                $groups[] = new SubjectPrivacyGroup('vidyalms-org-' . $key, $label, $fields, static fn (): Query => clone $query);
            }
        }
        if ($email !== '' || $userId > 0) {
            [$query, $fields] = $this->definition('invitations');
            $query->select($fields);
            if ($email !== '' && $userId > 0) {
                $query->whereAnyOf(Predicate::compare('email', '=', $email), Predicate::compare('accepted_by', '=', $userId));
            } elseif ($email !== '') {
                $query->where('email', '=', $email);
            } else {
                $query->where('accepted_by', '=', $userId);
            }
            $groups[] = new SubjectPrivacyGroup(
                'vidyalms-org-invitations',
                'Organization invitations',
                $fields,
                static fn (): Query => clone $query
            );
        }

        return $groups;
    }

    public function exportFor(OrgPrivacyContext $context, string $recordClass, int $page, int $perPage): array
    {
        [$query, $fields, $subjectColumn, $orgColumn, $order] = $this->definition($recordClass);
        $query->where($orgColumn, '=', $context->scope->organisationId);
        if ($context->userId !== null) {
            $query->where($subjectColumn, '=', $context->userId);
        }
        $total = $this->database->count(clone $query);
        $items = $this->database->selectAll($query->select($fields)->orderBy($order)->limit($perPage)->offset(($page - 1) * $perPage));

        return ['items' => $items, 'total' => $total];
    }

    /**
     * The shipped receipt shape, which is this contributor's typed outcome
     * flattened.
     *
     * There is one erasure routine and two shapes of its one answer, which is
     * the whole reason {@see ErasesWithOutcome} is a capability interface rather
     * than a narrowed return type: a caller written against the array keeps
     * reading it and gains the two things it could not carry.
     */
    public function eraseFor(OrgPrivacyContext $context): array
    {
        return $this->eraseWithOutcome($context)->toArray();
    }

    public function eraseWithOutcome(OrgPrivacyContext $context): EraseOutcome
    {
        $userId = $context->userId ?? throw new \LogicException('Erasure requires a subject.');
        $orgId = $context->scope->organisationId;
        $where = ['organisation_id' => $orgId, 'user_id' => $userId];
        if (
            $this->database->count(Query::from('entitlements')->where('organisation_id', '=', $orgId)
            ->where('user_id', '=', $userId)->where('status', '=', 'active')) > 0
            || $this->database->count(Query::from('seat_assignments')->where('organisation_id', '=', $orgId)
                ->where('user_id', '=', $userId)->whereIn('state', ['pending', 'assigned', 'started'])) > 0
        ) {
            throw new ConflictException('Finish organization access cleanup before erasure.');
        }
        $erased = ['roles' => 0, 'invitation_claims' => 0];
        $pseudonymised = [];
        // Delete roles first, while the original subject-to-membership link still exists.
        foreach (
            $this->database->selectAll(Query::from('org_memberships')->select(['id'])
            ->where('organisation_id', '=', $orgId)->where('user_id', '=', $userId)) as $membership
        ) {
            $erased['roles'] += $this->database->delete('org_role_assignments', [
                'organisation_id' => $orgId, 'membership_id' => (int) $membership['id']]);
        }
        $erased['invitation_claims'] = $this->database->delete('org_invitation_claims', $where);
        $pseudonymised['memberships'] = $this->database->update('org_memberships', [
            'user_id' => 0, 'state' => 'left', 'active_key' => null, 'source_ref' => null], $where);
        $pseudonymised['seats'] = $this->database->update('seat_assignments', ['user_id' => 0, 'holder_key' => null], $where);
        // The subject's id on OTHER people's rows, which erasure used to leave
        // behind entirely. org_memberships.manager_user_id is one of the seven
        // directory columns, it names this person on every row they manage, and
        // it was neither erased, pseudonymised, retained with a basis nor
        // counted as untouched: the receipt did not mention it at all. It is
        // nulled inside this organisation only, because the column names a
        // manager of this tenant's own membership and another organisation's
        // rows are outside the erase bound.
        $pseudonymised['managed_memberships'] = $this->database->update(
            'org_memberships',
            ['manager_user_id' => null],
            ['organisation_id' => $orgId, 'manager_user_id' => $userId],
        );
        // The catalogue entries this person published, kept and de-identified.
        // Deleting them would remove the organisation's own course list because
        // an administrator left, which is the organisation's configuration
        // rather than the subject's data; the personal datum is the author id
        // and that is what goes.
        $pseudonymised['catalogue_entries'] = $this->database->update(
            'org_catalogue_entries',
            ['created_by' => 0],
            ['organisation_id' => $orgId, 'created_by' => $userId],
        );
        $pseudonymised['invitations'] = 0;
        $account = $this->users->findById($userId);
        $email = $account === null ? '' : strtolower(trim($account->email));
        $invitations = Query::from('invitations')->select(['id'])->where('organisation_id', '=', $orgId);
        if ($email === '') {
            $invitations->where('accepted_by', '=', $userId);
        } else {
            $invitations->whereAnyOf(Predicate::compare('accepted_by', '=', $userId), Predicate::compare('email', '=', $email));
        }
        $cursor = 0;
        do {
            $rows = $this->database->selectAll((clone $invitations)->where('id', '>', $cursor)->orderBy('id')->limit(200));
            foreach ($rows as $invitation) {
                $cursor = (int) $invitation['id'];
                $target = ['organisation_id' => $orgId, 'invitation_id' => $cursor];
                $erased['invitation_claims'] += $this->database->delete('org_invitation_claims', $target);
                $erased['encrypted_invitation_delivery'] = ($erased['encrypted_invitation_delivery'] ?? 0)
                    + $this->database->delete('org_notification_secrets', $target);
                $pseudonymised['invitations'] += $this->database->update(
                    'invitations',
                    [
                    'accepted_by' => null, 'email' => '', 'state' => 'revoked', 'pending_key' => null, 'token_hash' => null],
                    ['organisation_id' => $orgId, 'id' => $cursor]
                );
            }
        } while (\count($rows) === 200);
        // The remembered active organisation. The tenancy middleware writes one
        // settings row per person who has ever opened an organisation console,
        // and nothing removed it when the membership ended, so a row naming
        // this organisation outlived the relationship it recorded. It is
        // deleted only when it names this organisation, because the preference
        // is one row per person across every tenant.
        $preference = OrgScopeMiddleware::PREFERENCE_PREFIX . $userId;
        $remembered = $this->database->selectOne(Query::from('settings')->where('name', '=', $preference));

        if ($remembered !== null && (int) json_decode((string) ($remembered['value'] ?? 'null'), true) === $orgId) {
            $erased['active_organisation_preference'] = $this->database->delete('settings', ['name' => $preference]);
        }

        // The two ledgers, pseudonymised rather than left naming the person.
        //
        // This is the design's resolution of the ledger-versus-erasure tension
        // and it is the opposite of what shipped: a seat_events row is RETAINED
        // under the legal_obligation basis with the member identity
        // PSEUDONYMISED, because deleting ledger rows breaks the append-only
        // guarantee finance depends on and the hash chain that makes it
        // tamper-evident. The shipped build kept the identities and disclaimed
        // any basis in a warning string, which records that the product knows
        // the rows are there and has no answer for them.
        //
        // The mechanism is chain-preserving and is described in full on
        // LedgerRedactionStore: hash and prev_hash are never rewritten, so the
        // link structure still proves nothing was inserted, deleted or
        // reordered, and the redaction receipt records the hash the row carried
        // so a verifier tells an erasure from an edit.
        $ledgerSeats = $this->redactions?->redact(
            $context->scope,
            LedgerRedactionStore::CLASS_SEAT_EVENTS,
            $userId,
            RetentionBasis::LEGAL_OBLIGATION,
            self::LEDGER_STATUTE,
        ) ?? 0;
        $ledgerEvents = $this->redactions?->redact(
            $context->scope,
            LedgerRedactionStore::CLASS_MEMBERSHIP_EVENTS,
            $userId,
            RetentionBasis::LEGAL_OBLIGATION,
            self::LEDGER_STATUTE,
        ) ?? 0;

        // The organisation own citation where it named one, and this provider's
        // otherwise. record_days rides beside it as the period, which is the
        // reader that column did not have.
        $statute = $this->retention?->statuteRefFor($context->scope) ?? self::LEDGER_STATUTE;
        $recordDays = $this->retention?->recordDaysFor($context->scope);
        $outcome = EraseOutcome::begin();

        if ($recordDays !== null) {
            $outcome = $outcome->warning(sprintf(
                'The organisation keeps the relationship records named below for %d days.',
                $recordDays,
            ));
        }

        foreach ($erased as $recordClass => $count) {
            $outcome = $outcome->erased($recordClass, $count);
        }

        foreach ($pseudonymised as $recordClass => $count) {
            // The relationship rows, kept for the contract they record. An
            // employer's own membership history is the evidence the seat was
            // legitimately spent, and Article 6(1)(b) is what keeps it.
            $outcome = $outcome->pseudonymised($recordClass, $count, RetentionBasis::CONTRACT);
        }

        $outcome = $outcome
            ->pseudonymised(
                LedgerRedactionStore::CLASS_SEAT_EVENTS,
                $ledgerSeats,
                RetentionBasis::LEGAL_OBLIGATION,
                $statute,
            )
            ->pseudonymised(
                LedgerRedactionStore::CLASS_MEMBERSHIP_EVENTS,
                $ledgerEvents,
                RetentionBasis::LEGAL_OBLIGATION,
                $statute,
            )
            // The domain requires a positive beneficiary and enrolments and
            // issuance reference these grants. Revocation is safe; corrupting
            // their identity is not.
            ->retained(
                'entitlements',
                $this->database->count(Query::from('entitlements')->where('organisation_id', '=', $orgId)
                    ->where('user_id', '=', $userId)),
                RetentionBasis::CONTRACT,
            );

        // What this erasure deliberately did not touch, counted rather than
        // asserted. It is the evidence the tenancy bound held: a learner active
        // in two organisations and erased by one keeps every row the other paid
        // for, and every row they bought themselves.
        $outcome = $outcome
            ->untouchedElsewhere('entitlements', $this->elsewhere(
                Query::from('entitlements'),
                Query::from('entitlements'),
                $orgId,
                $userId,
            ))
            ->untouchedElsewhere('seats', $this->elsewhere(
                Query::from('seat_assignments'),
                Query::from('seat_assignments'),
                $orgId,
                $userId,
            ));

        $outcome = $outcome->warning(
            'Personal and other-organization learning histories are not erased by an organization.',
        );

        if ($this->redactions === null) {
            // Said once, where it is true, rather than as a standing disclaimer
            // over rows that are in fact pseudonymised.
            $outcome = $outcome->warning(
                'No ledger redaction store is installed, so the append-only ledgers still name this person.',
            );
        }

        return $outcome;
    }

    /**
     * Everybody this contributor holds a relationship row about inside one
     * organisation.
     *
     * It follows Moodle's `core_userlist_provider`, which exists so data can be
     * removed for the users matching a criterion as a set operation over a
     * context rather than as a loop over ids assembled somewhere else. The
     * membership table alone would miss the case the method is for: somebody
     * whose membership has already closed and whose seat assignments and
     * invitations are still here.
     *
     * @return iterable<int>
     */
    public function usersInOrganisation(OrgPrivacyContext $context): iterable
    {
        $seen = [];

        $sources = [
            'org_memberships' => 'user_id',
            'seat_assignments' => 'user_id',
            // The case the membership query misses, and the reason this method
            // exists: somebody whose membership has closed while their
            // invitation claim is still here.
            'invitations' => 'accepted_by',
        ];

        foreach ($sources as $table => $column) {
            $query = match ($table) {
                'org_memberships' => Query::from('org_memberships'),
                'seat_assignments' => Query::from('seat_assignments'),
                default => Query::from('invitations'),
            };
            $rows = $this->database->selectAll(
                $query->select([$column])
                    ->where('organisation_id', '=', $context->scope->organisationId)
                    ->whereNotNull($column),
            );

            foreach ($rows as $row) {
                $userId = (int) $row[$column];

                if ($userId > 0 && !isset($seen[$userId])) {
                    $seen[$userId] = true;

                    yield $userId;
                }
            }
        }
    }

    /**
     * The Article 30 half of what this contributor holds.
     *
     * GDPR Article 30(1) names the purposes, the categories of subject and of
     * personal data, the categories of recipient, and the erasure time limits.
     * The last is deliberately absent from every entry: it is the
     * **organisation's** retention policy and not this contributor's, so the
     * generator reads it from `organisations.retention_policy` and a contributor
     * cannot answer for a controller it does not know.
     *
     * @return array<string, array<string, mixed>>
     */
    public function article30(): array
    {
        $employment = [
            DescribesForArticle30::SUBJECT_CATEGORIES => ['members of the organisation'],
            DescribesForArticle30::RECIPIENTS => ['organisation administrators', 'site administrators'],
            DescribesForArticle30::LAWFUL_BASIS => 'contract; legitimate interests',
        ];

        return [
            'memberships' => $employment + [
                DescribesForArticle30::PURPOSE => 'Recording who belongs to this organisation and under what terms.',
                DescribesForArticle30::DATA_CATEGORIES => ['identifiers', 'employment attributes'],
                DescribesForArticle30::TABLES => ['org_memberships'],
            ],
            'roles' => $employment + [
                DescribesForArticle30::PURPOSE => 'Recording what each member may do inside this organisation.',
                DescribesForArticle30::DATA_CATEGORIES => ['identifiers', 'authorisation'],
                DescribesForArticle30::TABLES => ['org_role_assignments'],
            ],
            'invitations' => $employment + [
                DescribesForArticle30::PURPOSE => 'Offering membership and recording whether it was taken up.',
                DescribesForArticle30::SUBJECT_CATEGORIES => ['invited people, who may hold no account'],
                DescribesForArticle30::DATA_CATEGORIES => ['email address', 'identifiers'],
                DescribesForArticle30::TABLES => ['invitations', 'org_invitation_claims'],
            ],
            'entitlements' => $employment + [
                DescribesForArticle30::PURPOSE => 'Recording which courses this organisation gave which member.',
                DescribesForArticle30::DATA_CATEGORIES => ['identifiers', 'course access'],
                DescribesForArticle30::TABLES => ['entitlements'],
            ],
            'seats' => $employment + [
                DescribesForArticle30::PURPOSE => 'Recording which purchased seat each member occupies.',
                DescribesForArticle30::DATA_CATEGORIES => ['identifiers', 'seat state'],
                DescribesForArticle30::TABLES => ['seat_assignments'],
            ],
            'seat_events' => $employment + [
                DescribesForArticle30::PURPOSE => 'The append-only seat ledger, which is the record a purchase is '
                    . 'reconciled against and an audit is answered from.',
                DescribesForArticle30::DATA_CATEGORIES => ['pseudonymised identifiers', 'seat movements'],
                DescribesForArticle30::LAWFUL_BASIS => 'legal obligation; legitimate interests',
                DescribesForArticle30::TABLES => ['seat_events', 'org_ledger_redactions'],
            ],
            'events' => $employment + [
                DescribesForArticle30::PURPOSE => 'The membership event stream, which is how a change of access on a '
                    . 'given date is answered afterwards.',
                DescribesForArticle30::DATA_CATEGORIES => ['pseudonymised identifiers', 'membership changes'],
                DescribesForArticle30::LAWFUL_BASIS => 'legal obligation; legitimate interests',
                DescribesForArticle30::TABLES => ['org_membership_events'],
            ],
            'catalogue_entries' => $employment + [
                DescribesForArticle30::PURPOSE => 'Recording which courses this organisation publishes to its people '
                    . 'and who published each one.',
                DescribesForArticle30::SUBJECT_CATEGORIES => ['administrators of the organisation'],
                DescribesForArticle30::DATA_CATEGORIES => ['identifiers'],
                DescribesForArticle30::TABLES => ['org_catalogue_entries'],
                // The word an erasure receipt would name for these rows, in the
                // closed RetentionBasis vocabulary the receipt writes. The rows
                // survive an erasure de-identified rather than deleted, because
                // they are the organisation's own configuration.
                'retention_basis' => RetentionBasis::CONTRACT,
            ],
        ];
    }

    /**
     * How many of this person's rows in one table this organisation was never
     * entitled to erase.
     *
     * Two queries rather than one because they ask two different questions that
     * happen to sum: rows another organisation owns, and rows nobody owns
     * because the learner bought the course themselves. Both are passed in
     * already built from a literal table name, so the tenancy scan can see every
     * read this class makes.
     */
    private function elsewhere(Query $otherOrganisation, Query $ownedByNobody, int $orgId, int $userId): int
    {
        return $this->database->count(
            $otherOrganisation->where('user_id', '=', $userId)->where('organisation_id', '!=', $orgId),
        ) + $this->database->count(
            $ownedByNobody->where('user_id', '=', $userId)->whereNull('organisation_id'),
        );
    }

    /** @return array{Query, list<string>, string, string, string} */
    private function definition(string $key): array
    {
        return match ($key) {
            // manager_user_id is in the export because it is personal data about
            // the subject on somebody else's row: a subject access request that
            // omitted it would not show who the product says manages them.
            'memberships' => [Query::from('org_memberships'), ['id', 'organisation_id', 'user_id', 'org_unit_id', 'state',
                'joined_at', 'left_at', 'provisioned_by', 'manager_user_id'], 'user_id', 'organisation_id', 'id'],
            'roles' => [Query::from('org_role_assignments', 'r')->join('org_memberships', 'm', 'r.membership_id', 'm.id',
                [['r.organisation_id', 'm.organisation_id']]), ['r.id AS id', 'r.organisation_id', 'r.membership_id', 'm.user_id',
                'r.role_key', 'r.scope_type', 'r.scope_id', 'r.created_at'], 'm.user_id', 'r.organisation_id', 'r.id'],
            'invitations' => [Query::from('invitations'), ['id', 'organisation_id', 'email', 'role_key', 'state', 'expires_at',
                'accepted_by', 'accepted_at', 'created_at'], 'accepted_by', 'organisation_id', 'id'],
            'entitlements' => [Query::from('entitlements'), ['id', 'organisation_id', 'org_membership_id', 'user_id', 'course_id',
                'source', 'status', 'starts_at', 'expires_at', 'revoked_reason', 'created_at'], 'user_id', 'organisation_id', 'id'],
            'seats' => [Query::from('seat_assignments'), ['id', 'organisation_id', 'pool_id', 'membership_id', 'user_id',
                'state', 'assigned_at', 'closed_at'], 'user_id', 'organisation_id', 'id'],
            'seat_events' => [Query::from('seat_events'), ['id', 'organisation_id', 'pool_id', 'assignment_id', 'event_key',
                'seat_delta', 'balance_after', 'subject_user_id', 'reason_key', 'occurred_at'], 'subject_user_id', 'organisation_id', 'id'],
            'events' => [Query::from('org_membership_events'), ['id', 'organisation_id', 'subject_id', 'event_key',
                'organisation_revision', 'created_at'], 'subject_id', 'organisation_id', 'id'],
            // Anchored on the author rather than on a learner, because that is
            // the only person this table names. A catalogue entry is the
            // organisation's own configuration and the personal datum in it is
            // who published it.
            'catalogue_entries' => [Query::from('org_catalogue_entries'), ['id', 'organisation_id', 'scope_type',
                'scope_id', 'source_key', 'course_id', 'grant_mode', 'state', 'starts_at', 'ends_at', 'created_by',
                'created_at'], 'created_by', 'organisation_id', 'id'],
            default => throw ValidationException::forField('record_class', 'Choose an available privacy record class.'),
        };
    }
}
