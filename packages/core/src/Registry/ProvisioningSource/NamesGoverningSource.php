<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ProvisioningSource;

/**
 * A provisioning source that governs the rows it wrote.
 *
 * Declaring it does two things and they are the same thing said to two
 * audiences. It tells the console to render this source's memberships in the
 * third row shape, with no action button, and it tells the person looking at
 * that row **where** the fact is governed, as a muted provenance cell written
 * as a statement of fact with no forward action, exactly like the
 * informational needs-attention row that carries no link.
 *
 * The alternative shipped everywhere else in this category is worse and is
 * what this exists to avoid: a row that looks editable, an edit that appears
 * to succeed, and a sync that silently reverts it that night. A person who
 * cannot change something should be told so at the moment they look at it, not
 * the next morning.
 *
 * It is a capability probed with instanceof and never a method on the shipped
 * ProvisioningSourceInterface, because a provenance that merely records how
 * somebody joined governs nothing and has nothing to say here. The three free
 * sources declare nothing: an administrator who added a person by hand, an
 * invitation somebody accepted and a CSV an operator uploaded are all facts
 * about the past, and none of them owns the row afterwards.
 */
interface NamesGoverningSource
{
    /**
     * Where this row is governed, as a language key or a short label a screen
     * shows in the provenance cell.
     *
     * It takes the organisation because one source may govern several
     * tenants' rows through different connections, and an operator of one
     * tenant must be told about theirs.
     */
    public function governingSourceLabel(int $organisationId): string;

    /**
     * The fields this source owns on a row it governs.
     *
     * A list rather than a boolean, because governance is field by field in
     * practice: a directory owns the department and the manager and has no
     * opinion at all about which courses somebody was released. An empty list
     * means the source governs the membership's existence and none of its
     * fields.
     *
     * @return list<string> canonical attribute names
     */
    public function governedFields(): array;
}
