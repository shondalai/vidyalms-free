<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft;

/**
 * One learner's relationship with the course being imported: the access they
 * hold, and what the product says they finished.
 *
 * Access and history are one object because they come from one place in every
 * one of the three products (LearnDash's activity table, Tutor's enrolment
 * post plus its completion meta, LifterLMS's user postmeta table), and because
 * the writer has to grant the entitlement before it can record a single
 * completed lesson against the enrolment that grant creates.
 *
 * `completedItems` names item source ids rather than local lesson ids: the
 * reader knows the foreign identity and only the writer knows what it became.
 */
final class MembershipDraft
{
    /**
     * @param string       $sourceId       the product's own identity for this enrolment
     * @param int          $userId         the WordPress user id, which is also the host user id
     * @param string|null  $expiresAt      "Y-m-d H:i:s" or null for open ended access
     * @param list<string> $completedItems item source ids the product records as finished
     */
    private function __construct(
        public readonly string $sourceId,
        public readonly int $userId,
        public readonly ?string $expiresAt,
        public readonly array $completedItems,
    ) {
    }

    /**
     * @param list<string> $completedItems
     */
    public static function of(string $sourceId, int $userId, ?string $expiresAt, array $completedItems = []): self
    {
        return new self($sourceId, $userId, $expiresAt, $completedItems);
    }
}
