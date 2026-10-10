<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\MembershipEventContext;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipState;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Closes a membership whose agreed end date has passed.
 *
 * `org_memberships.ends_at` is written by the console and by every roster load
 * carrying a OneRoster `endDate`, and until this task existed nothing read it:
 * no sweep, no filter and no access check consulted the column, so a role whose
 * term ran out in 2024 was still an active membership, and every
 * organisation-sourced entitlement kept following it. A column that records a
 * promise and has nothing that keeps it is worse than no column, because the
 * console shows the end date and the operator believes it.
 *
 * **It is a term expiry and never a removal inferred from absence.** The date is
 * a statement the source made about this membership, which is the same class of
 * evidence as a tombstone and the opposite of a person missing from a file. The
 * design's rule is untouched: nothing here reads what a file did not say.
 *
 * The write goes through {@see ManageOrganisationMembers::update()}, so a
 * membership closed by the clock obeys the same rules one closed by a person
 * does, including the last-owner invariant, the optimistic revision and the
 * access log; and it carries a system provenance, so an auditor reading the
 * event stream sees a sweep rather than a human principal doing it by hand.
 *
 * The tenant comes from the organisation the row belongs to and the actor from
 * that organisation's own creator, through the one witness that mints a swept
 * scope. A row whose organisation is gone, or whose recorded actor no longer
 * holds the right, is skipped and counted rather than forced through.
 */
final class MembershipTermSweepTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    /**
     * The key follows the vocabulary every registered task on both hosts uses.
     * A host job row stores this string and both drains resolve a handler by
     * it, so it is spelled the way the others are.
     */
    public const KEY = 'vidyalms.organisation.membership_term_sweep';

    /** How many memberships one run closes, so a large site drains over several runs. */
    public const BATCH = 100;

    public function __construct(
        private readonly SweptRecordStore $records,
        private readonly OrganisationSweepAuthority $authority,
        private readonly OrganisationStore $organisations,
        private readonly ManageOrganisationMembers $members,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $closed = 0;
        $skipped = 0;

        foreach ($this->records->dueTermMemberships(MembershipState::ACTIVE, self::BATCH) as $row) {
            $scope = $this->authority->forOrganisation((int) $row['organisation_id']);

            if ($scope === null) {
                $skipped++;

                continue;
            }

            try {
                $this->members->update(
                    Principal::user($scope->actorId),
                    $scope->organisationId,
                    (int) $row['id'],
                    [
                        'expected_revision' => (int) $this->organisations->organisation($scope)['revision'],
                        'state' => MembershipState::LEFT,
                    ],
                    MembershipEventContext::system((string) $row['id']),
                );
                $closed++;
            } catch (\Throwable) {
                // A row this sweep cannot close is a row a person has to look
                // at, and it is counted rather than retried in a loop: the
                // usual cause is the last owner of an organisation, whose
                // membership the use case refuses to close whoever asks.
                $skipped++;
            }
        }

        return sprintf('closed=%d skipped=%d', $closed, $skipped);
    }

    public function binCount(): int
    {
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }
}
