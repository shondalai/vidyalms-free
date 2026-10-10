<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;

/**
 * Pseudonymising an identity out of an append-only ledger, without breaking the
 * chain that makes the ledger evidence.
 *
 * The design resolves the ledger-versus-erasure tension one way and the shipped
 * code resolved it the other: a `seat_events` row is **retained** under the
 * `legal_obligation` basis with the member identity **pseudonymised**, because
 * deleting ledger rows breaks the append-only guarantee finance depends on, and
 * the shipped build instead kept the identities intact and disclaimed any basis
 * in a warning string. Doing what the design says needs a mechanism, because
 * `subject_user_id` is inside `seat_events`' hashed row: setting it to zero
 * changes what {@see OrganisationLearningStore::digest()} recomputes, and a
 * verifier that recomputes digests would then report an erasure as tampering.
 *
 * **The mechanism, stated plainly, because it is the one judgement call in this
 * feature:**
 *
 * 1. The `hash` and `prev_hash` columns are **never rewritten**. Row N's
 *    `prev_hash` still equals row N-1's `hash`, so the link structure of the
 *    chain verifies exactly as it did before the redaction, and an insert, a
 *    deletion or a reorder is still detected.
 * 2. The identity column is set to zero, and a row here records that it happened:
 *    which ledger, which row, under which basis, when, and the row's `hash` as
 *    it stood at that moment.
 * 3. A verifier recomputing content digests finds a mismatch on a redacted row.
 *    That is not a break, and it is not silently forgiven either: the recorded
 *    `pre_hash` is compared to the row's own `hash` column, and the row is
 *    reported as **redacted** rather than as **broken**.
 *
 * **Why the recorded `pre_hash` cannot simply be forged.** It is bound into the
 * chain by the following row's `prev_hash`, which was written long before the
 * redaction and cannot be changed without breaking every link after it. So a
 * redaction that claims a hash the chain does not carry is caught by the same
 * walk that catches everything else, and the circularity of attesting a row by
 * the hash it already holds is closed from outside the row.
 *
 * **What it deliberately does not deliver.** The redacted row's *content* can no
 * longer be recomputed from the data that survives, and that is the property
 * erasure is supposed to have: a mechanism that could reconstruct the original
 * subject would not be an erasure. `subject_token` is a keyed pseudonym so two
 * ledger rows about one person still correlate inside one organisation, which is
 * what a reconciliation needs, and it is an HMAC under the host secret so it
 * cannot be reversed by trying every user id. With no host secret it is a plain
 * digest over the same input, and {@see tokenIsKeyed()} says which one a site
 * holds rather than letting anybody assume the stronger answer.
 */
final class LedgerRedactionStore
{
    public const TABLE = 'org_ledger_redactions';

    /** The two ledgers this product holds an identity in. */
    public const CLASS_SEAT_EVENTS = 'seat_events';
    public const CLASS_MEMBERSHIP_EVENTS = 'org_membership_events';

    /** Which column each ledger keeps the subject in. */
    private const SUBJECT_COLUMNS = [
        self::CLASS_SEAT_EVENTS => 'subject_user_id',
        self::CLASS_MEMBERSHIP_EVENTS => 'subject_id',
    ];

    /** Which ledgers carry a hash chain, and therefore a pre-redaction hash. */
    private const CHAINED = [self::CLASS_SEAT_EVENTS => true];

    /** Domain separation, so one host secret keys many things. */
    private const TOKEN_KEY_INFO = 'vidyalms:ledger-redaction:v1';

    /** What a pseudonymised identity column reads as afterwards. */
    public const PSEUDONYM = 0;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        /** The host secret. Empty means the token is unkeyed and says so. */
        private readonly string $tokenKey = '',
    ) {
    }

    /** Whether this site's pseudonyms are keyed, which an auditor is entitled to know. */
    public function tokenIsKeyed(): bool
    {
        return $this->tokenKey !== '';
    }

    /**
     * Pseudonymises one person out of one ledger, inside one organisation.
     *
     * Returns how many rows were changed, so the erasure receipt counts what
     * actually happened rather than what was asked for. A row already redacted
     * is skipped rather than redacted twice, which is what makes a re-run of a
     * failed erasure safe.
     */
    public function redact(
        OrgScope $scope,
        string $recordClass,
        int $userId,
        string $basis = RetentionBasis::LEGAL_OBLIGATION,
        ?string $statuteRef = null,
    ): int {
        $column = self::SUBJECT_COLUMNS[$recordClass]
            ?? throw new \InvalidArgumentException('That ledger holds no redactable subject column.');

        if ($userId < 1) {
            return 0;
        }

        $token = $this->tokenFor($scope->organisationId, $userId);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $redacted = 0;

        foreach ($this->rows($scope, $recordClass, $column, $userId) as $row) {
            $rowId = (int) $row['id'];

            if ($this->isRedacted($scope->organisationId, $recordClass, $rowId)) {
                continue;
            }

            $this->database->insert(self::TABLE, [
                'organisation_id' => $scope->organisationId,
                'record_class' => $recordClass,
                'row_id' => $rowId,
                'subject_token' => $token,
                'basis' => RetentionBasis::of($basis),
                'statute_ref' => $statuteRef === '' ? null : $statuteRef,
                // Null for an unchained ledger rather than an empty string: the
                // absence is the fact, and org_membership_events genuinely has
                // no hash to attest.
                'pre_hash' => isset(self::CHAINED[$recordClass]) ? (string) ($row['hash'] ?? '') : null,
                'redacted_at' => $now,
            ]);

            // The identity column and nothing else. Widening this update to
            // tidy anything nearby would change a hashed column the redaction
            // record does not account for, and the next verification would
            // report it as a break with no explanation.
            $redacted += $this->database->update(
                $recordClass,
                [$column => self::PSEUDONYM],
                ['organisation_id' => $scope->organisationId, 'id' => $rowId],
            );
        }

        return $redacted;
    }

    /**
     * Every redaction recorded against one ledger inside one organisation,
     * keyed by row id.
     *
     * The organisation is not optional. This table is on the tenancy gate's own
     * list, and a receipt index that could be read across tenants would be the
     * one place a redacted person's rows could be counted from outside the
     * organisation that redacted them.
     *
     * @return array<int, array{basis: string, pre_hash: ?string, redacted_at: string}>
     */
    public function recordedFor(string $recordClass, int $organisationId): array
    {
        $query = Query::from(self::TABLE)
            ->select(['row_id', 'basis', 'pre_hash', 'redacted_at'])
            ->where('organisation_id', '=', $organisationId)
            ->where('record_class', '=', $recordClass);

        $out = [];

        foreach ($this->database->selectAll($query->orderBy('row_id')) as $row) {
            $out[(int) $row['row_id']] = [
                'basis' => (string) $row['basis'],
                'pre_hash' => $row['pre_hash'] === null ? null : (string) $row['pre_hash'],
                'redacted_at' => (string) $row['redacted_at'],
            ];
        }

        return $out;
    }

    /**
     * The seat ledger chain, walked with redactions accounted for.
     *
     * `breaks` are rows whose link or whose content cannot be explained.
     * `redacted` are rows whose content digest no longer reproduces **and** whose
     * recorded `pre_hash` matches the hash the chain carries for them, which is
     * an erasure rather than an edit. A redaction whose `pre_hash` disagrees with
     * the row is a break, not a redaction, because that is exactly what somebody
     * rewriting a row and writing a receipt for it would look like.
     *
     * @return array{checked: int, breaks: list<int>, redacted: list<int>, complete: bool, token_keyed: bool}
     */
    public function verifySeatLedger(int $organisationId, ?int $poolId = null): array
    {
        $recorded = $this->recordedFor(self::CLASS_SEAT_EVENTS, $organisationId);
        $query = Query::from(self::CLASS_SEAT_EVENTS)->where('organisation_id', '=', $organisationId);

        if ($poolId !== null) {
            $query->where('pool_id', '=', $poolId);
        }

        $rows = $this->database->selectAll($query->orderBy('id'));
        $breaks = [];
        $redactions = [];
        $previous = null;
        $checked = 0;

        foreach ($rows as $row) {
            $checked++;
            $id = (int) $row['id'];
            $hash = (string) ($row['hash'] ?? '');

            // The link half. It is checked first and separately, because it is
            // the half a redaction never touches and therefore the half that
            // still proves nothing was inserted, deleted or reordered.
            if ($previous !== null && (string) ($row['prev_hash'] ?? '') !== $previous) {
                $breaks[] = $id;
                $previous = $hash;

                continue;
            }

            $previous = $hash;

            if (OrganisationLearningStore::digest($row) === $hash) {
                continue;
            }

            $note = $recorded[$id] ?? null;

            if ($note !== null && $note['pre_hash'] === $hash && $hash !== '') {
                $redactions[] = $id;

                continue;
            }

            $breaks[] = $id;
        }

        return [
            'checked' => $checked,
            'breaks' => $breaks,
            'redacted' => $redactions,
            'complete' => $breaks === [],
            'token_keyed' => $this->tokenIsKeyed(),
        ];
    }

    /**
     * The keyed pseudonym for one person inside one organisation.
     *
     * The organisation is in the input, so the same person in two organisations
     * gets two tokens: a shared token would let one tenant correlate its own
     * redacted rows with another's, which is the cross-tenant leak this whole
     * feature exists to avoid, wearing a hash.
     */
    public function tokenFor(int $organisationId, int $userId): string
    {
        $material = self::TOKEN_KEY_INFO . '|' . $organisationId . '|' . $userId;

        return $this->tokenKey === ''
            ? hash('sha256', $material)
            : hash_hmac('sha256', $material, $this->tokenKey);
    }

    private function isRedacted(int $organisationId, string $recordClass, int $rowId): bool
    {
        return $this->database->count(
            Query::from(self::TABLE)
                ->where('organisation_id', '=', $organisationId)
                ->where('record_class', '=', $recordClass)
                ->where('row_id', '=', $rowId),
        ) > 0;
    }

    /**
     * The ledger rows about one person, carrying the hash only where the ledger
     * has one. Selecting a `hash` column from `org_membership_events` would be a
     * query against a column that table does not have.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(OrgScope $scope, string $recordClass, string $column, int $userId): array
    {
        // Named by a literal and narrowed in the same statement, because a table
        // named by a variable is a read the tenancy scan cannot check and a
        // predicate added afterwards is a predicate a later edit can drop. This
        // is one of the two tables in the product where getting either wrong
        // would hand one tenant another's ledger.
        $query = $recordClass === self::CLASS_SEAT_EVENTS
            ? Query::from('seat_events')->select(['id', 'hash'])
                ->where('organisation_id', '=', $scope->organisationId)
            : Query::from('org_membership_events')->select(['id'])
                ->where('organisation_id', '=', $scope->organisationId);

        return $this->database->selectAll(
            $query->where($column, '=', $userId)->orderBy('id'),
        );
    }
}
