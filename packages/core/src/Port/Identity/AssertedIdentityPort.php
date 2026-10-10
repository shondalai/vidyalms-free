<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * What the host can tell the core about how this person signed in.
 *
 * It is a port and not a method on an existing one, and it is optional and
 * defaulted on Ports, for the reason LocalePort records: a required new member
 * turns a core upgrade into a fatal on every site whose adapter has not been
 * rebuilt in the same minute, at class load, before any version guard can run.
 *
 * The free implementation on both hosts reads host groups, which is the only
 * thing either CMS actually knows: Joomla's authentication response carries no
 * attributes and no groups at all, so a mapper keyed on raw claims would be
 * unreachable on Joomla in every edition. Raw attribute dialects are a paid
 * package's business, registered into the identity mapper seam.
 *
 * Default when absent: null, which means the host is not asserting anything
 * about this person. It is not an assertion that they are nobody, and no
 * membership is ever removed because of it.
 */
interface AssertedIdentityPort
{
    public function assertedIdentityFor(Principal $principal): ?AssertedIdentity;
}
