<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Domain\Platform\SettingsRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\SupportsLockingReads;

/**
 * The append-only admin access log: one writer, one reader, one sweep.
 *
 * There is deliberately no update method and no delete method on this class
 * beyond {@see prune()}, which is the retention sweep. ISO/IEC 27001:2022
 * A.8.15 requires that a privileged user cannot interact with the log of the
 * system they control, and 21 CFR 11.10(e) forbids a change that obscures
 * previously recorded information, so the absence is the control rather than
 * a convention: a route that wanted to edit a row would have to add a method
 * here, which is a visible edit in review.
 *
 * **The chain is the one seat_events already carries.** Every row's hash is
 * computed by {@see OrganisationLearningStore::digest()}, the shipped and
 * tested implementation, rather than by a second one written beside it: two
 * hash functions over two ledgers is two things to keep in step, and the
 * whole value of a chain is that nobody has to trust the second copy. What
 * this class adds is the normalisation for its own columns, because a digest
 * is only reproducible if a row read back from the database has the types it
 * had when it was written.
 *
 * **The chain is site-wide and not per organisation.** A row may have no
 * owning organisation at all (a site-wide read that named none), so a
 * per-organisation chain would leave those rows unchained, and an
 * organisation-anchored chain is also a chain an organisation administrator
 * could reason about and fill. One chain over the whole table, ordered by id,
 * covers every row exactly once.
 *
 * The retention sweep breaks the chain by design, because pruning is a
 * deletion the chain was never meant to hide. {@see verify()} therefore
 * distinguishes a pruned prefix, which is expected, from a broken link inside
 * the surviving rows, which is not.
 *
 * **A chain alone leaves both of its ends open, so this one is anchored.** A
 * row-to-row chain detects an edit in the middle and detects nothing at either
 * end: deleting the newest rows leaves a chain that verifies clean, deleting an
 * arbitrary prefix looks exactly like the retention sweep, and an editor who
 * knows the recipe can rewrite a row and recompute forward. The anchor closes
 * all three. {@see ANCHOR_SETTING} holds the head hash, the number of anchored
 * rows still expected and where the sweep last cut, under a MAC keyed from the
 * host secret, so recomputing the chain no longer produces an anchor that
 * agrees with it and a shortfall at either end is a break rather than silence.
 *
 * What that does and does not deliver is stated here rather than implied. The
 * row hashes stay a plain sha256, because rehashing rows already written is
 * precisely the rewrite ISO/IEC 27001:2022 A.8.15 and 21 CFR 11.10(e) forbid;
 * tamper evidence for the row contents therefore rests on the anchor rather
 * than on the row hash. When no host secret reaches this class the anchor is
 * still written and still catches an editor who does not update it, and
 * {@see verify()} reports the anchor as "unkeyed" so nobody reads it as more
 * than that.
 */
final class AdminAccessLogStore
{
    private bool $writingChain = false;

    public const TABLE = 'admin_access_log';

    /** The prev_hash of the first row ever written, as seat_events spells it. */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * The chain's anchor, held outside the table it protects.
     *
     * A settings row rather than a column, because an anchor stored in the
     * audited table is an anchor the same editor rewrites in the same
     * statement, and because the alternative is a second table with one row.
     */
    public const ANCHOR_SETTING = 'admin_access_log.anchor';

    /** The anchor's own stored shape, so a later field is a version and not a guess. */
    public const ANCHOR_SHAPE = 1;

    /** Domain separation for the anchor MAC, so one host secret keys many things. */
    public const ANCHOR_KEY_INFO = 'vidyalms.admin-access-log.anchor.v1';

    /** The anchor agrees and its MAC is keyed by a secret the database does not hold. */
    public const ANCHOR_OK = 'ok';

    /** No anchor has been written yet, which is every log that predates it. */
    public const ANCHOR_ABSENT = 'absent';

    /** The anchor agrees, but nothing keys it, so it is evidence and not proof. */
    public const ANCHOR_UNKEYED = 'unkeyed';

    /** The anchor disagrees with the table, which is always a break. */
    public const ANCHOR_MISMATCH = 'mismatch';

    /**
     * How many recent row hashes {@see verify()} keeps while walking.
     *
     * It is what lets a fork be told apart from a deletion without holding
     * every hash in the table in memory. Two concurrent appends are adjacent
     * by id, so the window is generous by orders of magnitude; a fork older
     * than the window is reported as a link break, which is the safe way to
     * be wrong.
     */
    public const FORK_WINDOW = 50000;

    /**
     * The columns that are integers in the database and have to be integers
     * again before a row read back is hashed. Without this a verification pass
     * hashes "7" where the writer hashed 7 and reports every row as tampered.
     */
    private const INTEGER_COLUMNS = ['id', 'actor_id', 'actor_org_id', 'subject_user_id', 'subject_org_id', 'record_count'];

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        /**
         * Where the anchor lives. Defaulted, so a caller built before the
         * anchor existed still writes a chain; the anchor is then absent
         * rather than wrong, which {@see verify()} says out loud.
         */
        private readonly ?SettingsRepository $anchors = null,
        /**
         * The host secret the anchor MAC is derived from, domain separated by
         * {@see ANCHOR_KEY_INFO}. Empty means the anchor is unkeyed and says so.
         */
        private readonly string $chainKey = '',
    ) {
    }

    /**
     * Appends one row, chained to the row before it.
     *
     * A transaction and a shared write lock serialize the preceding-row read,
     * append and anchor update. Concurrent requests cannot fork the chain.
     */
    public function record(AdminAccessRecord $record): int
    {
        return $this->database->transactional(function () use ($record): int {
            $this->lockChain();
            try {
                return $this->append($record);
            } finally {
                $this->writingChain = false;
            }
        });
    }

    private function lockChain(): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        // An upsert of the key itself holds the same InnoDB row on both hosts,
        // including an empty log. No process-local lock can protect web workers.
        $this->database->upsert('settings', [
            'name' => 'admin_access_log.write_lock', 'value' => 'true',
            'created_at' => $now, 'updated_at' => $now,
        ], ['name'], ['name']);
        $this->writingChain = true;
    }

    private function append(AdminAccessRecord $record): int
    {
        $row = $record->columns($this->clock->now()->format('Y-m-d H:i:s'));
        $query = Query::from(self::TABLE)->select(['hash'])->orderBy('id', Query::SORT_DESC);
        $last = $this->database instanceof SupportsLockingReads
            ? $this->database->selectOneForUpdate($query) : $this->database->selectOne($query);
        $row['prev_hash'] = $last === null ? self::GENESIS : (string) $last['hash'];
        $row['hash'] = self::digest($row);
        $id = $this->database->insert(self::TABLE, $row);
        $this->advance($id, (string) $row['hash']);

        return $id;
    }

    /**
     * One anchored page of the log, newest first.
     *
     * An organisation anchor matches on either side, because "what did my
     * administrators read" and "what did anyone read about my organisation"
     * are the same question asked from two ends and an auditor asking one
     * would be misled by an answer to the other.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function page(AdminAccessLogQuery $anchor, int $page = 1, int $perPage = 25): array
    {
        $query = Query::from(self::TABLE);

        if ($anchor->organisationId !== null) {
            $query->whereAnyOf(
                Predicate::compare('actor_org_id', '=', $anchor->organisationId),
                Predicate::compare('subject_org_id', '=', $anchor->organisationId),
            );
        }

        if ($anchor->subjectUserId !== null) {
            $query->where('subject_user_id', '=', $anchor->subjectUserId);
        }

        $total = $this->database->count(clone $query);
        $rows = $this->database->selectAll(
            $query->orderBy('id', Query::SORT_DESC)->limit(max(1, $perPage))->offset((max(1, $page) - 1) * max(1, $perPage))
        );

        return [
            'items' => array_map(self::numbers(...), $rows),
            'total' => $total,
            'page' => max(1, $page),
            'per_page' => max(1, $perPage),
        ];
    }

    /** How many rows are anchored by one query, without reading any of them. */
    public function countFor(AdminAccessLogQuery $anchor): int
    {
        return $this->database->count($this->anchored($anchor));
    }

    /**
     * The same anchored read, walked by keyset instead of paged by offset.
     *
     * {@see page()} stays as it is: it serves a screen whose contract publishes
     * `page` and `total`. An export is a different operation with a different
     * failure. Offset makes the database build and discard every skipped row
     * and, worse, lets rows shift between pages while the walk runs, and this
     * is an append-only table under live traffic, so an offset-paged export of
     * it **will** duplicate and skip rows. The sort key is the primary key,
     * which is stable, indexed and unique.
     *
     * Oldest first, unlike the screen, because a chain is read forwards and an
     * auditor reading an export alongside {@see verify()} is following the same
     * order the hashes were computed in.
     *
     * @param int $limit zero for everything the anchor reaches
     *
     * @return iterable<array<string, mixed>>
     */
    public function walk(AdminAccessLogQuery $anchor, int $pageSize = 500, int $limit = 0): iterable
    {
        $take = max(1, $pageSize);
        $after = 0;
        $yielded = 0;

        while (true) {
            $rows = $this->database->selectAll(
                $this->anchored($anchor)->where('id', '>', $after)->orderBy('id')->limit($take)
            );

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                $after = (int) $row['id'];

                yield self::numbers($row);

                $yielded++;

                if ($limit > 0 && $yielded >= $limit) {
                    return;
                }
            }

            if (\count($rows) < $take) {
                return;
            }
        }
    }

    /**
     * The anchored predicate, in one place, so the page, the count and the walk
     * cannot drift apart.
     *
     * An organisation anchor matches on either side for the reason
     * {@see page()} gives, and the unanchored form carries no predicate because
     * the only caller that can mint one has already been checked for
     * site administration.
     */
    private function anchored(AdminAccessLogQuery $anchor): Query
    {
        $query = Query::from(self::TABLE);

        if ($anchor->organisationId !== null) {
            $query->whereAnyOf(
                Predicate::compare('actor_org_id', '=', $anchor->organisationId),
                Predicate::compare('subject_org_id', '=', $anchor->organisationId),
            );
        }

        if ($anchor->subjectUserId !== null) {
            $query->where('subject_user_id', '=', $anchor->subjectUserId);
        }

        return $query;
    }

    /**
     * Walks the whole chain and names every row that does not verify.
     *
     * **It pages rather than truncating.** The first draft selected the oldest
     * thousand rows and returned breaks:[] with no signal that it had stopped,
     * so a production log, which passes a thousand rows in days, answered
     * "intact" about a slice that excluded every recent row. The parameter is
     * now the page size and the walk continues to the end of the table;
     * `complete` says whether it got there, and only a cap the caller asked
     * for can make it false.
     *
     * Four failures are reported apart, because they mean different things to
     * an auditor. A row whose recomputed hash differs from its stored one was
     * edited after it was written ("content"). A row whose prev_hash names a
     * row that is not there is a deletion ("link"). A row whose prev_hash
     * names a row that IS there but is not its predecessor is a fork from two
     * concurrent appends ("fork"), which is routine and must never share a
     * reason with a deletion. And the two open ends of the chain are answered
     * by the anchor: a missing tail or a short table is "head" and "missing".
     *
     * A prefix removed by the retention sweep is none of those: the earliest
     * surviving row's prev_hash naming a row that is no longer there is
     * exactly what pruning looks like, so it is reported as "pruned". The
     * anchor is what tells a real sweep from a prefix somebody deleted, since
     * the sweep advances the anchor and a deletion does not.
     *
     * @param int      $pageSize how many rows are read at a time
     * @param int|null $maxRows  a deliberate cap; null walks the whole table
     *
     * @return array{checked: int, breaks: list<array{id: int, reason: string}>, pruned: bool,
     *     complete: bool, total: int, anchor: string}
     */
    public function verify(int $pageSize = 1000, ?int $maxRows = null): array
    {
        $page = max(1, $pageSize);
        $total = $this->database->count(Query::from(self::TABLE));
        $breaks = [];
        $pruned = false;
        $previous = null;
        $checked = 0;
        $lastId = 0;
        $newestHash = '';
        $anchoredSeen = 0;
        $window = [];
        $anchor = $this->anchor();
        $from = $anchor === null ? 0 : (int) $anchor['from_id'];

        while ($maxRows === null || $checked < $maxRows) {
            $take = $maxRows === null ? $page : min($page, $maxRows - $checked);
            $rows = $this->database->selectAll(
                Query::from(self::TABLE)->where('id', '>', $lastId)->orderBy('id')->limit($take)
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $stored) {
                $row = self::numbers($stored);
                $id = (int) $row['id'];
                $hash = (string) $row['hash'];
                $lastId = $id;
                $checked++;

                if ($from > 0 && $id >= $from) {
                    $anchoredSeen++;
                }

                if (self::digest($row) !== $hash) {
                    $breaks[] = ['id' => $id, 'reason' => 'content'];
                }

                $link = (string) ($row['prev_hash'] ?? '');

                if ($previous === null) {
                    $pruned = $link !== self::GENESIS;
                } elseif ($link !== $previous) {
                    $breaks[] = ['id' => $id, 'reason' => isset($window[$link]) ? 'fork' : 'link'];
                }

                $window[$hash] = $id;

                if (\count($window) > self::FORK_WINDOW) {
                    // Halved rather than shifted one at a time, so trimming is
                    // amortised constant instead of quadratic over a long log.
                    $window = \array_slice($window, intdiv(self::FORK_WINDOW, 2), null, true);
                }

                $previous = $hash;
                $newestHash = $hash;
            }
        }

        $state = self::ANCHOR_ABSENT;

        if ($anchor !== null) {
            $state = $this->anchorMac($anchor) === (string) $anchor['mac']
                ? ($this->chainKey === '' ? self::ANCHOR_UNKEYED : self::ANCHOR_OK)
                : self::ANCHOR_MISMATCH;
            $expected = (int) $anchor['count'];

            if ($checked >= $total && $anchoredSeen !== $expected) {
                // Rows the anchor counted are no longer here. This is the end
                // of the chain nothing else watches, and the reading a plain
                // walk gives it is "clean".
                $breaks[] = ['id' => 0, 'reason' => 'missing'];
                $state = self::ANCHOR_MISMATCH;
            }

            if ($checked >= $total && $expected > 0 && $newestHash !== (string) $anchor['head']) {
                $breaks[] = ['id' => $lastId, 'reason' => 'head'];
                $state = self::ANCHOR_MISMATCH;
            }
        }

        return [
            'checked' => $checked,
            'breaks' => $breaks,
            'pruned' => $pruned,
            'complete' => $checked >= $total,
            'total' => $total,
            'anchor' => $state,
        ];
    }

    /**
     * The retention sweep: the only writer that removes a row.
     *
     * It deletes by instant rather than by id so that the floor is a duration
     * and not a row count, and it takes the cut-off already computed, because
     * the rule about what a floor is and who may lengthen it belongs to the
     * task that owns the policy rather than to the table.
     *
     * `$keepUntilPerOrganisation` is the per-organisation lengthening, as the
     * instant each organisation's own cut-off falls on. It only ever **keeps** a
     * row: the batch is already selected on the site floor, so an organisation's
     * entry can hold a row back and can never bring one forward. A row anchored
     * to two organisations, which is every row a delegated administrator wrote
     * about another tenant's member, is kept if **either** anchor keeps it,
     * because a row one organisation is still entitled to read is not a row the
     * other may have deleted.
     *
     * @param array<int, string> $keepUntilPerOrganisation
     */
    public function prune(string $olderThan, int $batch = 500, array $keepUntilPerOrganisation = []): int
    {
        return $this->database->transactional(function () use ($olderThan, $batch, $keepUntilPerOrganisation): int {
            $this->lockChain();
            try {
                return $this->pruneLocked($olderThan, $batch, $keepUntilPerOrganisation);
            } finally {
                $this->writingChain = false;
            }
        });
    }

    /** @param array<int, string> $keepUntilPerOrganisation */
    private function pruneLocked(string $olderThan, int $batch, array $keepUntilPerOrganisation): int
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)->select(['id', 'occurred_at', 'actor_org_id', 'subject_org_id'])
                ->where('occurred_at', '<', $olderThan)
                ->orderBy('id')->limit(max(1, $batch))
        );
        $removed = 0;
        $highest = 0;

        foreach ($rows as $row) {
            if (self::keptByAnOrganisation($row, $keepUntilPerOrganisation)) {
                continue;
            }

            $id = (int) $row['id'];
            $gone = $this->database->delete(self::TABLE, ['id' => $id]);

            if ($gone > 0) {
                $removed += $gone;
                $highest = max($highest, $id);
            }
        }

        if ($highest > 0) {
            $this->recordSweep($highest, $removed, $olderThan);
        }

        return $removed;
    }

    /**
     * The anchor as it stands, or null when none has ever been written.
     *
     * It is public because an auditor asking whether the log was swept, and
     * how far, is asking this and nothing else, and because a report over the
     * log has to be able to say "the anchor is unkeyed on this site".
     *
     * @return array{shape: int, from_id: int, count: int, head: string, pruned: int,
     *     pruned_to_id: int, pruned_at: string, mac: string}|null
     */
    public function anchor(): ?array
    {
        if ($this->writingChain && $this->database instanceof SupportsLockingReads
            && $this->anchors instanceof \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository) {
            $row = $this->database->selectOneForUpdate(Query::from('settings')->where('name', '=', self::ANCHOR_SETTING));
            $stored = $row === null ? null : json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
        } else {
            $stored = $this->anchors?->find(self::ANCHOR_SETTING)?->value;
        }

        if (!\is_array($stored) || (int) ($stored['shape'] ?? 0) !== self::ANCHOR_SHAPE) {
            return null;
        }

        return [
            'shape' => self::ANCHOR_SHAPE,
            'from_id' => (int) ($stored['from_id'] ?? 0),
            'count' => (int) ($stored['count'] ?? 0),
            'head' => (string) ($stored['head'] ?? ''),
            'pruned' => (int) ($stored['pruned'] ?? 0),
            'pruned_to_id' => (int) ($stored['pruned_to_id'] ?? 0),
            'pruned_at' => (string) ($stored['pruned_at'] ?? ''),
            'mac' => (string) ($stored['mac'] ?? ''),
        ];
    }

    /**
     * Moves the anchor on by one appended row.
     *
     * A failure here never takes the response with it, on the same terms the
     * middleware's own write does not: an anchor that could 500 an audited
     * read would be an availability defect wearing a compliance badge. The
     * next verification reports the shortfall, which is the honest outcome.
     */
    private function advance(int $id, string $hash): void
    {
        if ($this->anchors === null) {
            return;
        }

        try {
            $anchor = $this->anchor();
        } catch (\Throwable) {
            return;
        }

        $next = [
            'shape' => self::ANCHOR_SHAPE,
            'from_id' => $anchor === null || (int) $anchor['from_id'] < 1 ? $id : (int) $anchor['from_id'],
            'count' => ($anchor === null ? 0 : (int) $anchor['count']) + 1,
            'head' => $hash,
            'pruned' => $anchor === null ? 0 : (int) $anchor['pruned'],
            'pruned_to_id' => $anchor === null ? 0 : (int) $anchor['pruned_to_id'],
            'pruned_at' => $anchor === null ? '' : (string) $anchor['pruned_at'],
        ];

        $this->store($next);
    }

    /**
     * Records what the retention sweep removed, so a pruned prefix is
     * corroborated rather than assumed.
     *
     * Without it, deleting an arbitrary prefix and the sweep doing its job are
     * the same observation, and the reading a bare chain gives that is
     * "expected".
     */
    private function recordSweep(int $highestId, int $removed, string $cutOff): void
    {
        $anchor = $this->anchor();

        if ($this->anchors === null || $anchor === null) {
            return;
        }

        // The sweep is the one deleter the chain permits, so it re-establishes
        // the invariant by counting what it left rather than by arithmetic
        // over what it took: pruning crosses the anchor's own start, and a
        // subtraction that guessed how many of the removed rows were anchored
        // would be a second answer to a question one COUNT settles.
        $from = max((int) $anchor['from_id'], $highestId + 1);
        $surviving = $this->database->count(Query::from(self::TABLE)->where('id', '>=', $from));

        $this->store([
            'shape' => self::ANCHOR_SHAPE,
            'from_id' => $from,
            'count' => $surviving,
            'head' => (string) $anchor['head'],
            'pruned' => (int) $anchor['pruned'] + $removed,
            'pruned_to_id' => max((int) $anchor['pruned_to_id'], $highestId),
            'pruned_at' => $cutOff,
        ]);
    }

    /**
     * @param array{shape: int, from_id: int, count: int, head: string, pruned: int,
     *     pruned_to_id: int, pruned_at: string} $anchor
     */
    private function store(array $anchor): void
    {
        try {
            $this->anchors?->set(self::ANCHOR_SETTING, $anchor + ['mac' => $this->anchorMac($anchor)]);
        } catch (\Throwable) {
            // Deliberately swallowed: see advance().
        }
    }

    /**
     * The anchor's own authenticator.
     *
     * HMAC under a key derived from the host secret when there is one, so an
     * editor holding the database cannot recompute an anchor that agrees with
     * a chain they rewrote. With no secret it is a plain digest, which still
     * catches an editor who forgets the anchor exists, and verify() reports
     * that weaker state as "unkeyed" rather than as "ok".
     *
     * @param array{shape: int, from_id: int, count: int, head: string, pruned: int,
     *     pruned_to_id: int, pruned_at: string, mac?: string} $anchor
     */
    private function anchorMac(array $anchor): string
    {
        $material = implode('|', [
            'v' . self::ANCHOR_SHAPE,
            (string) $anchor['from_id'],
            (string) $anchor['count'],
            $anchor['head'],
            (string) $anchor['pruned'],
            (string) $anchor['pruned_to_id'],
            $anchor['pruned_at'],
        ]);

        if ($this->chainKey === '') {
            return hash('sha256', $material);
        }

        return hash_hmac('sha256', $material, hash_hkdf('sha256', $this->chainKey, 32, self::ANCHOR_KEY_INFO));
    }

    /**
     * Whether an anonymous attempt on one organisation is already recorded
     * for the current day.
     *
     * It is the read behind the collapse rule the middleware applies to guest
     * traffic. The window is the calendar day rather than a rolling one
     * because the row it protects carries a date and no address, so a rolling
     * window would be a precision the record cannot support.
     */
    public function hasAnonymousAttempt(?int $organisationId, string $eventType): bool
    {
        $query = Query::from(self::TABLE)
            ->where('actor_id', '=', 0)
            ->where('event_type', '=', $eventType)
            ->where('occurred_at', '>=', $this->clock->now()->format('Y-m-d') . ' 00:00:00');

        if ($organisationId === null) {
            $query->whereNull('subject_org_id');
        } else {
            $query->where('subject_org_id', '=', $organisationId);
        }

        return $this->database->selectOne($query->select(['id'])) !== null;
    }

    public function organisationExists(int $organisationId): bool
    {
        return $this->database->selectOne(Query::from('organisations')->select(['id'])
            ->where('id', '=', $organisationId)) !== null;
    }

    public function hasRefusedAttemptToday(int $actorId, ?int $organisationId): bool
    {
        return $this->database->selectOne(Query::from(self::TABLE)->select(['id'])
            ->where('actor_id', '=', $actorId)
            ->where('subject_org_id', '=', $organisationId)
            ->where('outcome', '=', 'refused')
            ->where('occurred_at', '>=', $this->clock->now()->format('Y-m-d') . ' 00:00:00')) !== null;
    }

    /** The oldest surviving row's instant, or null when the log is empty. */
    public function earliestInstant(): ?string
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->select(['occurred_at'])->orderBy('id'));

        return $row === null ? null : (string) $row['occurred_at'];
    }

    /**
     * The digest of the scope a read ran under.
     *
     * OrgScope owns the formula, which carries its own version inside the
     * hashed material, so this is a delegation and not a second recipe: a log
     * row and a report artefact recorded under different digests for one
     * scope would be two answers to the question the digest exists to settle,
     * which is whether an artefact was computed under the scope now asking
     * for it.
     *
     * The indirection stays because the log's own column is a stored value
     * with a lifetime, and this is the one line that would have to change if
     * the log ever had to keep writing an older shape.
     */
    public static function scopeDigest(OrgScope $scope): string
    {
        return $scope->digest();
    }

    /**
     * The chain hash of one row, computed by the seat ledger's own function.
     *
     * @param array<string, mixed> $row
     */
    public static function digest(array $row): string
    {
        return OrganisationLearningStore::digest(self::numbers($row));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function numbers(array $row): array
    {
        foreach (self::INTEGER_COLUMNS as $column) {
            if (isset($row[$column])) {
                $row[$column] = (int) $row[$column];
            }
        }

        return $row;
    }

    /**
     * The retention cut-off for a whole number of days before now.
     *
     * It is here rather than in the task because the sweep and any later
     * per-organisation policy have to compute it identically, and a duration
     * arithmetic written twice is a duration arithmetic that disagrees.
     */
    /**
     * Whether either of a row's organisation anchors is keeping it past the
     * site floor.
     *
     * A row with no owning organisation at all is pruned on the floor, which is
     * the design's own sentence: nobody has claimed it, so nobody has lengthened
     * it.
     *
     * @param array<string, mixed> $row
     * @param array<int, string>   $keepUntilPerOrganisation
     */
    private static function keptByAnOrganisation(array $row, array $keepUntilPerOrganisation): bool
    {
        if ($keepUntilPerOrganisation === []) {
            return false;
        }

        $occurred = (string) ($row['occurred_at'] ?? '');

        foreach (['actor_org_id', 'subject_org_id'] as $anchor) {
            $organisationId = $row[$anchor] === null ? 0 : (int) $row[$anchor];
            $keepUntil = $keepUntilPerOrganisation[$organisationId] ?? null;

            if ($organisationId > 0 && $keepUntil !== null && $occurred >= $keepUntil) {
                return true;
            }
        }

        return false;
    }

    public function cutOff(int $days): string
    {
        if ($days < 1) {
            throw ValidationException::forField('days', 'A retention floor is a whole number of days.');
        }

        return $this->clock->now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    }
}
