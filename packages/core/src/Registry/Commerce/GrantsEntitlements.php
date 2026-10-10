<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Commerce;

/**
 * The commerce source may write entitlements, and says under which source
 * words its callbacks arrive.
 *
 * **Default when absent: it grants nothing.** A source that only answers
 * price and buy-link questions is a storefront, not a grantor, and a
 * storefront that has never written an entitlement should not be able to
 * start doing so by accident.
 *
 * This is what a fixed list in the grant use case used to decide. The list
 * meant that a package registering its own checkout could not grant without
 * a core edit, which ADR 0002 forbids: every paid feature must be expressible
 * as a provider behind a point the free core defines.
 *
 * The words are entitlement sources (Entitlement::SOURCES), not provider
 * keys. `entitlements.source` says why a person may take a course, and which
 * checkout produced it is `source_ref`'s business. A word this core version
 * does not store is refused at the grant, so widening that vocabulary stays
 * a core decision with a migration behind it.
 */
interface GrantsEntitlements
{
    /**
     * @return list<string> entitlement source words this provider may grant
     */
    public function grantableEntitlementSources(): array;
}
