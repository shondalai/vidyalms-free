<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Privacy;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorInterface;
use Shondalai\VidyaLMS\Core\Registry\Privacy\SubjectPrivacyGroup;

/**
 * A contributor over rows an organisation owns, which serves the organisation
 * direction and not the host's own subject request.
 *
 * It is what the two shipped organisation contributors are: they hold a
 * relationship and a ledger, neither of which a subject request erases, so
 * neither declares the capability and the loop passes over both.
 */
final class OrganisationOnlyPrivacyDouble implements PrivacyContributorInterface
{
    // The key the registry expects by default, so a bare registry in a test is
    // not reported as missing a contributor the site actually ships.
    public const KEY = 'core-organisations';

    public function key(): string
    {
        return self::KEY;
    }

    public function describes(): array
    {
        return ['relationships' => 'Relationships'];
    }

    public function subjectGroups(int $userId, string $email): array
    {
        return [new SubjectPrivacyGroup(
            'vidyalms-org-relationships',
            'Relationships',
            ['id', 'organisation_id'],
            static fn (): Query => Query::from('org_memberships')->where('user_id', '=', $userId),
        )];
    }

    public function exportFor(OrgPrivacyContext $context, string $recordClass, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0];
    }

    public function eraseFor(OrgPrivacyContext $context): array
    {
        return ['erased' => [], 'pseudonymised' => [], 'retained' => [], 'warnings' => []];
    }
}
