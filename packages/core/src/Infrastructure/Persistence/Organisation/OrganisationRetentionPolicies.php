<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\RetentionPolicy;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Every organisation that has written a retention policy, read once per sweep.
 *
 * It is a separate reader rather than a method on the organisation store
 * because the one caller is a scheduled task that runs outside any
 * organisation's scope: the sweep prunes a site-wide table and needs every
 * organisation's answer at once, which is the one organisation read in this
 * product that is legitimately unanchored.
 *
 * **It reads only what a retention decision needs**, which is a policy and an
 * id. It never reads a name, a contact address or a member, so a task that runs
 * unattended at three in the morning cannot become a second, quieter way to
 * enumerate a site's tenants.
 */
final class OrganisationRetentionPolicies
{
    public function __construct(private readonly DatabasePort $database)
    {
    }

    /**
     * Organisation id to the access log retention it asked for, for the
     * organisations that asked for one.
     *
     * An organisation with no policy, an unreadable policy or a policy naming no
     * access log duration is simply absent, and an absent organisation keeps the
     * site floor. That is the safe direction: a policy this build cannot read
     * must never shorten a retention, and the floor is the longer answer.
     *
     * @return array<int, int>
     */
    public function accessLogRetentionDays(): array
    {
        $rows = $this->database->selectAll(
            Query::from('organisations')
                ->select(['id', 'retention_policy', 'retention_policy_version'])
                ->whereNotNull('retention_policy')
                ->orderBy('id'),
        );

        $out = [];

        foreach ($rows as $row) {
            $stored = $row['retention_policy'] ?? null;
            $decoded = \is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;
            $policy = RetentionPolicy::fromStored(
                \is_array($decoded) ? $decoded : null,
                (int) ($row['retention_policy_version'] ?? RetentionPolicy::SHAPE),
            );

            if ($policy->accessLogDays !== null) {
                $out[(int) $row['id']] = $policy->accessLogDays;
            }
        }

        return $out;
    }
}
