<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * The provider was written knowing the organisation scope types exist, and
 * reads the OrgScope on its context.
 *
 * Declared by the same four kinds of provider that declare FiltersByVisibility:
 * an audience resolver, a report definition, an export writer, a search
 * provider.
 *
 * **It exists because FiltersByVisibility is an unversioned promise.** That
 * marker carries no method, so declaring it is a claim nothing can check, and
 * every provider carrying it today was written against four scope types and is
 * about to be handed three more. The shipped CourseInstructorsAudience is the
 * proof: it declares the older marker and yields every instructor row for the
 * course. A provider written before organisations existed must not inherit a
 * promise about a scope kind that did not exist when it was written.
 *
 * Default when absent: an organisation-scoped run **refuses** the provider,
 * even where FiltersByVisibility is declared. A run under the older scope
 * types is unaffected, so nothing shipped stops working.
 */
interface HonoursOrgScope
{
}
