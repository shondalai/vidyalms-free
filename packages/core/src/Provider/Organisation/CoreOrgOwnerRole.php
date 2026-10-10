<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Registry\OrgRole\DeclaresOwnership;

/**
 * The organisation's owner: every right inside it, including deleting it and
 * lengthening its retention policy.
 *
 * It declares DeclaresOwnership, which is what the last-owner invariant, the
 * erasure refusal and the owner count all read instead of the string that used
 * to be spelled into four files. It is not grantable by a mapping rule: a
 * directory push may make somebody an administrator and may never make them
 * the owner of the tenant.
 */
final class CoreOrgOwnerRole extends CoreOrgRole implements DeclaresOwnership
{
    public function __construct()
    {
        parent::__construct(
            'org_owner',
            ['events', 'learning', 'people', 'read', 'roles', 'seats', 'settings'],
            10,
            false,
        );
    }
}
