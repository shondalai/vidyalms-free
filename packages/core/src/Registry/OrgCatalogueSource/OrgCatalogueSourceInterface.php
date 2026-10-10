<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Catalogue\CourseGrant;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgCatalogueContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A rule that turns one catalogue entry into a set of course grants.
 *
 * The free build's two sources are a single course and a unit subtree; a paid
 * package adds a programme or a stored query, and Business adds a seat pool.
 * Every emitted course is validated against `CourseReleasePolicy` by the
 * caller before anything is granted, so an entry can only ever select from
 * what a site owner released.
 *
 * Miss direction: fail closed. The entry grants nothing, is listed as
 * unresolved and raises a visible admin alert. That is what the copy deck's
 * orphaned-source line renders: the organisation and its members are intact
 * and the list is the thing that is missing.
 *
 * ## The emit shape, and what a provider may not do
 *
 * A provider answers a list of {@see CourseGrant} records and performs no
 * write. The design's rule is that a source may call `GrantEntitlement` and
 * `RevokeEntitlementBySourceRef` and nothing else; a provider that holds
 * neither is stricter than that rule, and the one caller that grants is the
 * core's own catalogue service, which re-checks every course at grant time
 * because a release can be withdrawn after an entry was written.
 *
 * The context carries no `DatabasePort`. What it carries instead is the
 * organisation, the entry's scope, the resolved unit subtree, the entry's
 * versioned parameters, the clock and {@see OrgCatalogueReaderInterface},
 * whose three reads are already narrowed to this organisation.
 */
interface OrgCatalogueSourceInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * The JSON schema of this source's own `params` object, published rather
     * than left to an author to read out of the provider's source.
     *
     * The catalogue generator prints it and the write route validates against
     * it, so a source cannot accept a parameter it never documented.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;

    /**
     * The version stamped into `org_catalogue_entries.params_version` when this
     * source writes an entry.
     *
     * A stored object needs its own version or the first source that changes
     * shape has to guess what an old row meant.
     */
    public function paramsVersion(): int;

    /**
     * Every course this entry reaches, at the context's instant.
     *
     * An empty list is a valid answer and means the entry currently reaches
     * nothing: a unit with no courses under it is not an error. A source that
     * cannot resolve at all throws, and the caller records the entry as
     * unresolved rather than letting the exception reach a screen.
     *
     * @return list<CourseGrant>
     */
    public function resolve(OrgCatalogueContext $context): array;
}
