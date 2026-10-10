<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgRoleContext;

/**
 * The per-organisation authorisation rule, asked by every org-scoped use case.
 *
 * It is a sibling of CourseAccess and never a widened one. CourseAccess owns
 * one rule with one collaborator: the surface capability first, then the
 * lookup, then the membership predicate, ordered so a caller who could not use
 * the surface at all never learns from a refusal whether the thing exists.
 * This is that shape for organisations, and it owns nothing else.
 *
 * It is a thin front over OrgScopeResolver rather than a rename of it, and the
 * split is the refusal enforced rather than restated. The resolver owns the
 * two-tree walk, the unit reach arithmetic and the sole minting of a scope;
 * this owns the rule and the two questions a caller asks about it. A first
 * draft that merged them gave one class seven jobs, which is an
 * OrganisationService with a policy object's name on it.
 *
 * The acceptance criterion is measurable rather than tonal: it stays under two
 * hundred lines and takes no collaborator beyond the resolver. If it grows a
 * second, the eighth organisation feature has become a method on it rather
 * than a use case beside it.
 */
final class OrganisationAccess
{
    public function __construct(private readonly OrgScopeResolver $resolver)
    {
    }

    /**
     * The resource string for an organisation, in the same grammar as
     * "course:{id}".
     *
     * It is what the access log's resource column is written from, which is
     * the point of naming it: the record declared its own copy of the same
     * grammar and built the string a second time, so one fact had two
     * implementations in the wave that introduced the owner for it.
     *
     * It is deliberately not asked of either shipped adapter: both answer an
     * unrecognised prefixed resource with a refusal, and a host that can
     * really scope would declare ScopesOrgResources and be probed with
     * instanceof before being asked at all. **That probe is declared and
     * unimplemented**, in the same words the empty registries use: nothing
     * anywhere asks an authorization port an "org:" question yet, so
     * ScopesOrgResources is a marker with no caller until a use case needs a
     * host to narrow one. Only a negative answer from such a host would
     * narrow; nothing widens.
     */
    public static function resource(int $organisationId): string
    {
        return 'org:' . $organisationId;
    }

    /**
     * The rule, thrown. Every refusal is the same object with the same
     * message, so a foreign organisation and a missing one read identically.
     */
    public function require(Principal $actor, int $organisationId, string $action = 'read'): OrgScope
    {
        return $this->resolver->require($actor, $organisationId, $action);
    }

    public function requireOrganisationWide(Principal $actor, int $organisationId, string $action = 'read'): OrgScope
    {
        return $this->resolver->requireOrganisationWide($actor, $organisationId, $action);
    }

    /**
     * The same rule answered instead of thrown, for a read view deciding what
     * to offer rather than whether to serve.
     *
     * It catches only the two refusals the rule itself raises. A conflict, a
     * validation failure or a database error is a different question and is
     * left to travel.
     */
    public function can(Principal $actor, int $organisationId, string $action = 'read'): bool
    {
        try {
            $this->resolver->require($actor, $organisationId, $action);

            return true;
        } catch (EntityNotFoundException | NotAuthorizedException) {
            return false;
        }
    }

    /**
     * The scope for a caller who has already been authorised for this action.
     *
     * It delegates rather than minting, because the scope has exactly three
     * construction sites and this is not one of them.
     */
    public function scopeFor(Principal $actor, int $organisationId, string $action = 'read'): OrgScope
    {
        return $this->resolver->require($actor, $organisationId, $action);
    }

    /** The site-wide guard, for a route that names no organisation to scope to. */
    public function requireSiteManager(Principal $actor): void
    {
        $this->resolver->requireSiteManager($actor);
    }

    /**
     * What a role decision is answered against for this caller: the scope plus
     * what the host actually says about them.
     *
     * The host answer is asked here rather than inside the registry, because a
     * registry has no port and must not acquire one.
     */
    public function roleContext(Principal $actor, OrgScope $scope): OrgRoleContext
    {
        return OrgRoleContext::forCaller($scope, $this->resolver->hostCapabilities($actor));
    }
}
