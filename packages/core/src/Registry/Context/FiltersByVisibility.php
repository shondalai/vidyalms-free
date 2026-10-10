<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * The provider honours the VisibilityScope it is handed.
 *
 * Declared by an audience resolver, a report definition, an export writer or a
 * search provider. Default when absent: the provider is site-wide by
 * definition and a scoped run refuses it, because a provider that ignored the
 * scope would mail everybody on the site from a screen showing one group.
 *
 * It moved here from Registry\Notification\Audience because it is not a
 * notification concept: four kinds of provider declare it and it belongs
 * beside the scope it is about. The old fully qualified name is aliased, so
 * every shipped and paid implementation keeps **running** unchanged.
 *
 * The honest half of that promise, learned the hard way in the wave that made
 * the move: PHPStan does not follow a runtime class_alias through an implements
 * clause, so four core files and two pro-core files had to be repointed at the
 * new name or both packages analysed red. A package that declares the old name
 * therefore gets a working runtime and a red level-8 run, and the fix is to
 * repoint one import. That is a one-line change and not a coordinated release,
 * which is a smaller and true promise than the one this used to make.
 *
 * **It is an unversioned promise, and HonoursOrgScope beside it is why.** This
 * marker carries no method, so declaring it is a claim nothing can check, and
 * every provider that declares it today was written against four scope types
 * and will be handed three more. A provider written before organisations
 * existed must not inherit a promise about a scope kind that did not exist.
 */
interface FiltersByVisibility
{
}
