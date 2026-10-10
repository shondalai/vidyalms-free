<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorInterface;
use Shondalai\VidyaLMS\Core\Registry\Privacy\SubjectPrivacyGroup;

/**
 * A contributor a package outside the core registers, holding a table the core
 * has never heard of.
 *
 * It is the package that arrives one day, and it is the whole test of the claim
 * the two host const arrays falsified: adding a table must never mean editing an
 * adapter routine and adding a `PLG_PRIVACY_VIDYALMS_DOMAIN_*` language key. It
 * registers through the same public method the free providers use and reaches
 * both hosts through the registry alone.
 */
final class ExtraTablePrivacyDouble implements PrivacyContributorInterface
{
    public const KEY = 'test-widgets';

    public function key(): string
    {
        return self::KEY;
    }

    public function describes(): array
    {
        return ['widgets' => 'Widgets this package holds'];
    }

    public function subjectGroups(int $userId, string $email): array
    {
        return [new SubjectPrivacyGroup(
            'vidyalms-widgets',
            'Widgets this package holds',
            ['id'],
            static fn (): Query => Query::from('settings')->select(['id'])->where('name', '=', 'never-matches'),
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
