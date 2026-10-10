<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Reporting;

use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;

/**
 * The digest `report_runs.scope_digest` stores.
 *
 * There is exactly one rule and it is worth stating plainly: **an
 * organisation-resolved scope keeps `OrgScope::digest()` verbatim.** That hash
 * already covers the organisations, the resolved unit ids, the basis, the role
 * keys and the disclosure policy, and it carries its own `v1` shape prefix
 * inside the hashed material rather than printed in front of it. Recomputing it
 * here under a second recipe would mean a log row and a report artefact
 * recorded under different digests for one scope, which is two answers to the
 * question the digest exists to settle.
 *
 * Everything else is hashed here, under the same `v1` prefix, over the scope
 * type and its sorted ids. The prefix is what invalidates every stored digest
 * on the day the recipe changes, which is the only safe way to change it.
 *
 * **This identifies the reach and not the reader**, exactly as `OrgScope` does
 * and for the same reason: two administrators with the same reach may share an
 * artefact they are both entitled to every row of. The composition that adds
 * the actor is {@see ReportRun::artefactKey()}, and any artefact whose rows
 * depend on who is asking uses that and never this alone.
 */
final class ReportScopeDigest
{
    /** This class's own recipe marker, hashed into the value rather than printed. */
    public const SHAPE = 'v1';

    /**
     * The marker recorded in `report_runs.scope_digest_shape` when the digest
     * came from `OrgScope::digest()` rather than from here.
     *
     * Two recipes write one column, and each carries its own private version
     * marker inside the hash. Recording which one wrote a row is what turns a
     * shape bump from a silent orphaning of every artefact manifest into a
     * filterable migration: without it there is nothing to tell a v1 row from
     * a v2 one, and nothing to say which of the two recipes produced either.
     */
    public const SHAPE_ORGANISATION = 'org:v1';

    private function __construct()
    {
    }

    /** Which recipe {@see self::of()} would use for that scope. */
    public static function shapeOf(?VisibilityScope $scope): string
    {
        return $scope?->digest === null ? self::SHAPE : self::SHAPE_ORGANISATION;
    }

    public static function of(?VisibilityScope $scope): string
    {
        if ($scope === null) {
            return hash('sha256', self::SHAPE . '|scope:none');
        }

        if ($scope->digest !== null) {
            return $scope->digest;
        }

        $ids = $scope->ids();
        sort($ids);

        return hash('sha256', implode('', [
            self::SHAPE,
            '|type:', $scope->type,
            '|ids:', implode(',', $ids),
        ]));
    }
}
