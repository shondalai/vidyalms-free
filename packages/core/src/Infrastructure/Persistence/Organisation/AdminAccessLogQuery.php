<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The anchor an audit query is required to carry, following Canvas.
 *
 * Canvas requires at least one of course_id, assignment_id, student_id or
 * grader_id on its grade-change audit endpoint, requires account
 * administrator rights for a query with no course, and limits teachers to the
 * courses they teach. The same rule holds here: every access-log query is
 * anchored on something the caller has a claim on, and only a site
 * administrator may ask the unanchored form.
 *
 * It is never implemented as query-then-filter. The total of a filtered wide
 * query leaks how much exists outside the caller's scope, which is a
 * disclosure even when not one row crosses.
 *
 * The two organisation-anchored factories take an OrgScope rather than an
 * integer, so the claim is a value an authorisation produced rather than a
 * number a caller supplied. {@see siteWide()} takes nothing and is the one
 * form a delegated administrator can never reach: its own docblock names the
 * capability its caller must have checked first, because a value object
 * cannot check one.
 */
final class AdminAccessLogQuery
{
    private function __construct(
        public readonly ?int $organisationId,
        public readonly ?int $subjectUserId,
        public readonly bool $unanchored,
    ) {
    }

    /** Everything recorded about one organisation, actor side or subject side. */
    public static function forOrganisation(OrgScope $scope): self
    {
        return new self($scope->organisationId, null, false);
    }

    /**
     * One person's rows inside one organisation.
     *
     * The organisation anchor is kept alongside the person, because a
     * delegated administrator asking about a person may not learn what that
     * person did anywhere else on the site.
     */
    public static function forSubject(OrgScope $scope, int $subjectUserId): self
    {
        return new self($scope->organisationId, $subjectUserId, false);
    }

    /**
     * The whole log, including rows with no owning organisation.
     *
     * It is the form the design reserves for a site administrator, and it
     * exists so that rows with neither anchor (a site-wide read that named no
     * organisation) are reachable by somebody.
     *
     * **The capability is required here rather than asked of the caller.**
     * The rule used to live in this docblock, as a sentence beginning "callers
     * must", which is the shape of rule that holds until the second caller. It
     * takes the guard and the person now, so the unanchored form cannot be
     * minted by anybody who has not been checked, on the same terms as
     * OrgScope's three witnessed factories: the refusal is the type, not the
     * discipline.
     */
    public static function siteWide(AccessGuard $guard, Principal $actor): self
    {
        $guard->requireAuthenticated($actor);
        $guard->require($actor, Capability::ADMIN);

        return new self(null, null, true);
    }
}
