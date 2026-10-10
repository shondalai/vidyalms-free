<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Authorization;

/**
 * Capability checks against the host CMS: Joomla ACL actions from access.xml,
 * WordPress capabilities on roles. Capability keys are the constants in
 * Application\Shared\Capability.
 *
 * The optional resource reference scopes a check to one course
 * ("course:42") for per-course permissions. Unknown resource namespaces or
 * malformed identifiers must fail closed; they never fall back to site ACL.
 * Organisation authority is resolved by Core OrgScope, not host capabilities.
 *
 * That last rule is the exception to the general permission this port would
 * otherwise give, and it is worth stating plainly: **a scoped resource kind an
 * adapter does not understand is a denial, never a site answer.** An adapter
 * that cannot scope may ignore a resource it does understand and answer for
 * the site; it may not do that for a prefix it has never heard of, because
 * answering the site-wide question to an "org:5" would promote every holder of
 * the organisation surface capability to an administrator of every
 * organisation on the site.
 *
 * An implementation that genuinely creates a per-organisation asset declares
 * the optional ScopesOrgResources marker beside this interface and is probed
 * with instanceof. Only then is an "org:" question asked at all, and only its
 * negative answer narrows: the core-owned org_role_assignments predicate
 * remains the boundary in every edition.
 */
interface AuthorizationPort
{
    public function can(int $userId, string $capability, ?string $resource = null): bool;
}
