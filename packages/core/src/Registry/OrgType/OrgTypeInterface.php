<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgType;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What kind of thing an organisation is, as a label.
 *
 * It is deliberately not a gate and never a tenancy predicate. The one-table
 * model this design rejects put the boundary between two customers behind a
 * mutable type value on a row an administrator can edit; here the boundary is
 * a table identity and the type is what the console prints beside the name.
 *
 * The free build registers the five general shapes plus the six OneRoster
 * values, so a district's own export loads without a paid package.
 *
 * Miss direction: normal path. An unresolved type renders as its own key and
 * the read says so, because a type is a label and losing the stored value
 * would be worse than showing it unlabelled.
 */
interface OrgTypeInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;
}
