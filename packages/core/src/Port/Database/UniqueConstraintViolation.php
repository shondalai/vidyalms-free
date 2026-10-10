<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database;

/**
 * A write refused by a unique index.
 *
 * It exists because the catch that turns a unique index into an idempotency
 * guarantee had nothing portable to catch. Each adapter raises its host's own
 * exception (Joomla's driver exception, a bare RuntimeException built from
 * $wpdb->last_error, a PDOException in the test harness), and a
 * `catch (\Throwable)` would swallow a connection failure or a column-length
 * failure and return whatever a re-read found, which on the money path is a
 * paid order reported as already granted when nothing was granted.
 *
 * **Every DatabasePort implementation must map its driver's duplicate-key
 * error onto this type**, and nothing else onto it: SQLSTATE 23000 with
 * MySQL error 1062, and SQLITE_CONSTRAINT_UNIQUE (SQLSTATE 23000 with the
 * driver message naming UNIQUE). tooling/contract/DatabasePortContract.php
 * holds every implementation to it, so "it works on SQLite" cannot be the
 * whole story.
 *
 * The index name is carried where the driver reports one, because a table
 * with two unique indexes needs the caller to know which one refused before
 * it decides what to re-read. It is null where the driver does not say, and a
 * caller that needs certainty re-reads on the key it wrote rather than
 * trusting the name.
 */
final class UniqueConstraintViolation extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $table,
        public readonly ?string $indexName,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function on(string $table, ?string $indexName = null, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'A unique index refused a write to "%s"%s.',
                $table,
                $indexName === null ? '' : ' (' . $indexName . ')',
            ),
            $table,
            $indexName,
            $previous,
        );
    }

    /**
     * Whether a driver error is a duplicate key, from the two facts every
     * supported engine reports.
     *
     * Published here so three adapters answer the question identically rather
     * than each inventing a substring match. SQLSTATE 23000 is integrity
     * constraint violation on every one of them; MySQL narrows a duplicate
     * key to 1062, and SQLite names UNIQUE in the driver message.
     */
    public static function looksLikeOne(?string $sqlState, ?int $driverCode, string $driverMessage): bool
    {
        if ($driverCode === 1062) {
            return true;
        }

        if ($sqlState !== '23000') {
            return false;
        }

        return stripos($driverMessage, 'unique') !== false || stripos($driverMessage, 'duplicate') !== false;
    }
}
