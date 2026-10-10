<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * How many organisations a set of recipients spans.
 *
 * It is the one read behind both delivery refusals, and it is deliberately
 * unanchored: the question is "does this audience cross a tenant boundary", and
 * a read narrowed to one tenant could never see the crossing it exists to
 * detect. That is the same shape the identity home's issuer collision read
 * takes, and it is enumerated in the gate's witnessed list for the same reason.
 *
 * **A recipient no membership places counts as their own span of one.** They are
 * somebody the run named rather than somebody a tenant contributed, so counting
 * them as zero would let an audience of twenty unplaced people read as one
 * organisation, which is the arithmetic that turns a refusal into a warning.
 */
final class OrganisationAudienceSpan
{
    public function __construct(private readonly DatabasePort $database)
    {
    }

    /**
     * @param list<int> $recipientUserIds
     */
    public function spanOf(array $recipientUserIds): int
    {
        $recipients = array_values(array_unique(array_filter(
            $recipientUserIds,
            static fn (int $id): bool => $id > 0,
        )));

        if ($recipients === []) {
            return 1;
        }

        $rows = $this->database->selectAll(
            Query::from('org_memberships')
                ->select(['organisation_id', 'user_id'])
                ->whereIn('user_id', $recipients)
                ->where('state', '=', 'active'),
        );

        $organisations = [];
        $placed = [];

        foreach ($rows as $row) {
            $organisations[(int) $row['organisation_id']] = true;
            $placed[(int) $row['user_id']] = true;
        }

        $unplaced = \count(array_diff($recipients, array_keys($placed)));

        return max(1, \count($organisations) + $unplaced);
    }
}
