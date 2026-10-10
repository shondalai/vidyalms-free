<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

/**
 * The report may only be run inside one organisation.
 *
 * It is the other half of {@see \Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope}.
 * That marker says a provider knows how to read an `OrgScope` when it is given
 * one; this says the provider has no site-wide meaning at all and must never
 * be run without one. Two different questions, and collapsing them would make
 * the site-wide report route the second, unguarded way into an organisation's
 * rows.
 *
 * Default when absent: the report is site-scoped, reachable from
 * `GET /v1/manage/reports/{key}`, and an organisation-scoped run refuses it
 * unless it also declares `HonoursOrgScope`.
 *
 * A provider declaring this is absent from the site-wide route in the only way
 * the contract permits: `GET /v1/manage/reports/{key}` answers the published
 * registry-miss shape, 200 with `computed` false and a translated reason,
 * **after** authorisation has run. It is not a 404 and not a 403, because a
 * caller must not be able to learn which report keys a site has by reading the
 * reasons apart, and that rule does not stop being true because the key
 * happens to be organisation-only.
 */
interface OrgScopedReportInterface
{
}
