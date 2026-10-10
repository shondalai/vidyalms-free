<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Users;

/**
 * What the core knows about a CMS user: an id, a display name and an email.
 * Profile fields stay in the CMS.
 */
final class UserAccount
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
    ) {
    }
}
