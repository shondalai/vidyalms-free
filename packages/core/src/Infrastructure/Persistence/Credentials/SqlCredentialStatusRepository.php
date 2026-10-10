<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusEntry;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusList;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

final class SqlCredentialStatusRepository implements CredentialStatusRepository
{
    private const ENTRIES = 'credential_status_entries';
    private const LISTS = 'credential_status_lists';
    private const DATETIME = 'Y-m-d H:i:s';

    /**
     * How many times the compare-and-swap on the counter is retried before
     * the allocation gives up. Every retry means another writer took the
     * index this one read, so a handful is generous: the contention window is
     * a single update.
     */
    private const MAX_ATTEMPTS = 8;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly RandomPort $random,
    ) {
    }

    /**
     * The counter is taken by a compare-and-swap on the value that was read,
     * never by a read followed by a blind write.
     *
     * The design states this as one conditional increment. DatabasePort takes
     * literal values and column equality and has no expression form, and
     * adding one would widen a port every adapter implements for this single
     * caller. A swap conditioned on the previous value gives the identical
     * guarantee on MySQL, MariaDB and SQLite alike: exactly one writer sees
     * one affected row, everybody else sees zero and reads again.
     */
    public function allocate(string $subjectType, int $subjectId, string $purpose): CredentialStatusEntry
    {
        $existing = $this->findEntry($subjectType, $subjectId, $purpose);

        if ($existing !== null) {
            // Allocation is idempotent, because a replayed issuance must not
            // burn a second bit: an index is a promise printed into a
            // credential and there is no way to give one back.
            return $existing;
        }

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $list = $this->openListFor($purpose);

            if ($list->isFull()) {
                $this->createList($purpose);
                continue;
            }

            $taken = $this->database->update(
                self::LISTS,
                [
                    'next_index' => $list->nextIndex + 1,
                    'updated_at' => $this->clock->now()->format(self::DATETIME),
                ],
                ['id' => $list->id, 'next_index' => $list->nextIndex],
            );

            if ($taken !== 1) {
                continue;
            }

            $now = $this->clock->now()->format(self::DATETIME);

            $this->database->insert(self::ENTRIES, [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'purpose' => $purpose,
                'list_id' => $list->id,
                'list_index' => $list->nextIndex,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return new CredentialStatusEntry(
                $subjectType,
                $subjectId,
                $purpose,
                $list->id,
                $list->listUuid,
                $list->nextIndex,
            );
        }

        throw new \RuntimeException(sprintf(
            'Could not allocate a %s status list index after %d attempts.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $purpose,
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            self::MAX_ATTEMPTS,
        ));
    }

    public function entriesFor(string $subjectType, int $subjectId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::ENTRIES)
                ->where('subject_type', '=', $subjectType)
                ->where('subject_id', '=', $subjectId)
                ->orderBy('id', Query::SORT_ASC),
        );

        $entries = [];

        foreach ($rows as $row) {
            $list = $this->findList((int) $row['list_id']);

            if ($list === null) {
                continue;
            }

            $entries[] = new CredentialStatusEntry(
                (string) $row['subject_type'],
                (int) $row['subject_id'],
                (string) $row['purpose'],
                $list->id,
                $list->listUuid,
                (int) $row['list_index'],
            );
        }

        return $entries;
    }

    public function markDirty(string $subjectType, int $subjectId, \DateTimeImmutable $now): array
    {
        $dirty = [];

        foreach ($this->entriesFor($subjectType, $subjectId) as $entry) {
            $list = $this->findList($entry->listId);

            if ($list === null) {
                continue;
            }

            $dirty[] = $list->id;

            if ($list->dirtySince !== null) {
                // Left alone on purpose. dirty_since is what gives the
                // republication debounce its ceiling: moving it forward on
                // every revocation is exactly the trap that lets two hundred
                // revocations over an hour publish five minutes after the
                // last one.
                continue;
            }

            $this->database->update(self::LISTS, [
                'dirty_since' => $now->format(self::DATETIME),
                'updated_at' => $now->format(self::DATETIME),
            ], ['id' => $list->id]);
        }

        return $dirty;
    }

    public function retire(string $subjectType, int $subjectId, \DateTimeImmutable $now): array
    {
        return self::retireSubject($this->database, $subjectType, $subjectId, $now);
    }

    /** @return list<int> */
    public static function retireSubject(DatabasePort $database, string $subjectType, int $subjectId, \DateTimeImmutable $now): array
    {
        $entries = $database->selectAll(Query::from(self::ENTRIES)->where('subject_type', '=', $subjectType)->where('subject_id', '=', $subjectId));

        if ($entries === []) {
            return [];
        }

        // Dirtied first, while the entries still name the subject, because
        // markDirty finds the lists through them.
        $dirty = array_values(array_unique(array_map(static fn (array $entry): int => (int) $entry['list_id'], $entries)));
        $stamp = $now->format(self::DATETIME);
        foreach ($dirty as $listId) {
            $database->update(self::LISTS, ['dirty_since' => $stamp, 'updated_at' => $stamp], ['id' => $listId, 'dirty_since' => null]);
        }

        foreach ($entries as $entry) {
            // The slot keeps its list and its index and loses its subject.
            // Deleting the row would let the subject unique index hand the
            // same purpose to a later credential while the slot unique index
            // still holds this one, and freeing the index is impossible in
            // principle: it is printed inside a document somebody holds.
            $database->update(self::ENTRIES, [
                'subject_type' => CredentialStatusRepository::SUBJECT_RETIRED,
                'updated_at' => $stamp,
            ], [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'purpose' => $entry['purpose'],
            ]);
        }

        return $dirty;
    }

    public function findList(int $listId): ?CredentialStatusList
    {
        $row = $this->database->selectOne(Query::from(self::LISTS)->where('id', '=', $listId));

        return $row === null ? null : $this->mapList($row);
    }

    public function findListByUuid(string $listUuid): ?CredentialStatusList
    {
        $row = $this->database->selectOne(Query::from(self::LISTS)->where('list_uuid', '=', $listUuid));

        return $row === null ? null : $this->mapList($row);
    }

    public function clearDirty(int $listId, string $encodedList, \DateTimeImmutable $publishedAt): void
    {
        $this->database->update(self::LISTS, [
            'encoded_list' => $encodedList,
            'dirty_since' => null,
            'published_at' => $publishedAt->format(self::DATETIME),
            'updated_at' => $publishedAt->format(self::DATETIME),
        ], ['id' => $listId]);
    }

    private function findEntry(string $subjectType, int $subjectId, string $purpose): ?CredentialStatusEntry
    {
        $row = $this->database->selectOne(
            Query::from(self::ENTRIES)
                ->where('subject_type', '=', $subjectType)
                ->where('subject_id', '=', $subjectId)
                ->where('purpose', '=', $purpose),
        );

        if ($row === null) {
            return null;
        }

        $list = $this->findList((int) $row['list_id']);

        if ($list === null) {
            return null;
        }

        return new CredentialStatusEntry(
            $subjectType,
            $subjectId,
            $purpose,
            $list->id,
            $list->listUuid,
            (int) $row['list_index'],
        );
    }

    /**
     * The newest list for this purpose, created on first use. A full list
     * rolls to a new row rather than overflowing, so credential 131,073 lands
     * in a second list instead of writing an index past the end of a bitstring
     * a verifier would read as zero.
     */
    private function openListFor(string $purpose): CredentialStatusList
    {
        $row = $this->database->selectOne(
            Query::from(self::LISTS)
                ->where('purpose', '=', $purpose)
                ->orderBy('id', Query::SORT_DESC),
        );

        return $row === null ? $this->createList($purpose) : $this->mapList($row);
    }

    private function createList(string $purpose): CredentialStatusList
    {
        $now = $this->clock->now()->format(self::DATETIME);
        $uuid = $this->random->uuid();

        $id = $this->database->insert(self::LISTS, [
            'purpose' => $purpose,
            'list_uuid' => $uuid,
            'size' => CredentialStatusList::MINIMUM_SIZE,
            'next_index' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new CredentialStatusList($id, $purpose, $uuid);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapList(array $row): CredentialStatusList
    {
        $dirty = $row['dirty_since'] ?? null;
        $published = $row['published_at'] ?? null;

        return new CredentialStatusList(
            (int) $row['id'],
            (string) $row['purpose'],
            (string) $row['list_uuid'],
            (int) ($row['size'] ?? CredentialStatusList::MINIMUM_SIZE),
            (int) ($row['next_index'] ?? 0),
            \is_string($row['encoded_list'] ?? null) && $row['encoded_list'] !== '' ? $row['encoded_list'] : null,
            (int) ($row['ttl_ms'] ?? 300000),
            \is_string($dirty) && $dirty !== '' ? new \DateTimeImmutable($dirty, new \DateTimeZone('UTC')) : null,
            \is_string($published) && $published !== ''
                ? new \DateTimeImmutable($published, new \DateTimeZone('UTC'))
                : null,
        );
    }
}
