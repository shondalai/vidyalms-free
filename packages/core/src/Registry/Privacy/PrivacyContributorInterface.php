<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

interface PrivacyContributorInterface extends ProviderInterface
{
    /** @return array<string, string> Record-class key => translatable label. */
    public function describes(): array;

    /** @return list<SubjectPrivacyGroup> */
    public function subjectGroups(int $userId, string $email): array;

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function exportFor(OrgPrivacyContext $context, string $recordClass, int $page, int $perPage): array;

    /** Revocation/seat reconciliation must have completed before contributor erasure.
     * @return array{erased: array<string, int>, pseudonymised: array<string, int>, retained: array<string, int>, warnings: list<string>}
     */
    public function eraseFor(OrgPrivacyContext $context): array;
}
