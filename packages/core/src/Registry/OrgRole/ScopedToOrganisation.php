<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgRole;

/** A role authored by one organisation may only grant rights inside it. */
interface ScopedToOrganisation
{
    public function organisationId(): int;
}
