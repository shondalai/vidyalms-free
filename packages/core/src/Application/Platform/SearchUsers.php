<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Users\UserAccount;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * The global host account directory is restricted to site administrators
 * and managers. Course enrolment authority grants no access here: even a
 * prefix search permits enumeration of names and email addresses.
 */
final class SearchUsers
{
    private const MIN_TERM_LENGTH = 2;
    private const MAX_LIMIT = 50;
    private const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly UsersPort $users,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return list<UserAccount>
     */
    public function execute(Principal $principal, string $term, int $limit = self::DEFAULT_LIMIT): array
    {
        $this->guard->requireAuthenticated($principal);

        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);

        $term = trim($term);

        if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('q', sprintf('At least %d characters are required.', self::MIN_TERM_LENGTH));
        }

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('limit', sprintf('Must be between 1 and %d.', self::MAX_LIMIT));
        }

        return $this->users->search($term, $limit);
    }
}
