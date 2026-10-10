<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * Does this organisation exist, and may this caller name it.
 *
 * It is the narrow read model core prerequisite 22 says the core owes Pro. The
 * prerequisite's own wording is exact about the division: `ManageCohorts`
 * validates `cohorts.organisation_id` in the use case rather than by a database
 * constraint, because the neutral schema carries no foreign keys, and **what the
 * core owes it is a read model exposing an organisation's existence and the
 * caller's scope**. Pro therefore never queries `organisations` and never
 * re-derives a tenancy rule of its own.
 *
 * **It answers existence and nothing else.** No name, no contact address, no
 * member count. A cohort form validating a tenant id needs to know that the id
 * is real and reachable, and a read model that answered more would be a second,
 * quieter way to enumerate a site's organisations from a package that has no
 * organisation console.
 *
 * **The scope is the answer's other half.** `requireVisible()` resolves through
 * {@see OrganisationAccess}, which means every refusal on the path is the same
 * indistinguishable one: an unknown id, a suspended organisation, one the caller
 * has no role in and one that simply is not theirs read identically. A caller
 * cannot use a cohort form to learn which organisation ids a site has.
 */
final class OrganisationExistence
{
    /**
     * The right an existence check is made under.
     *
     * `read` and not `settings`: naming an organisation on a cohort is not
     * administering it, and requiring the settings right would mean an
     * instructor who legitimately runs an organisation's cohort could not save
     * it. It is still a right, so somebody with no relationship to the
     * organisation at all still gets the same refusal everything else gives.
     */
    public const ACTION = 'read';

    public function __construct(
        private readonly OrganisationAccess $access,
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * The scope, or the same refusal every organisation route gives.
     *
     * A positive id that names nothing and a positive id that names somebody
     * else's organisation are one answer with one message, which is the OWASP
     * API1 rule this product asserts route by route.
     */
    public function requireVisible(Principal $actor, int $organisationId): OrgScope
    {
        if ($organisationId < 1) {
            throw ValidationException::forField(
                'organisation_id',
                'An organisation id is a positive number.',
            );
        }

        $scope = $this->access->require($actor, $organisationId, self::ACTION);

        if (!$this->existsWithin($scope, $organisationId)) {
            // Unreachable through the shipped resolver, which reads the row
            // itself. It is here because this class is what another package
            // holds, and a read model whose two halves could disagree is a read
            // model that will one day answer yes about a row that is gone.
            throw ValidationException::forField(
                'organisation_id',
                'That organisation is not available here.',
            );
        }

        return $scope;
    }

    /** Whether the scope's own organisation is a live row, asked without throwing. */
    public function existsWithin(OrgScope $scope, int $organisationId): bool
    {
        if ($organisationId !== $scope->organisationId) {
            // A scope answers for its own organisation and for no other. Letting
            // it answer for a second id is exactly how a tenancy check becomes a
            // tenancy hole.
            return false;
        }

        return $this->database->count(
            OrgScopedReader::from($scope, 'organisations')->where('state', '!=', 'archived'),
        ) > 0;
    }

    /** The same question without a throw, for a caller offering a picker. */
    public function isVisibleTo(Principal $actor, int $organisationId): bool
    {
        if ($organisationId < 1 || !$this->access->can($actor, $organisationId, self::ACTION)) {
            return false;
        }

        return $this->existsWithin($this->access->scopeFor($actor, $organisationId, self::ACTION), $organisationId);
    }
}
