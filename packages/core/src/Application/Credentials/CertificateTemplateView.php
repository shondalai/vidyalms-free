<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * A template as an authoring surface reads it: the row, what the aggregate
 * holds, what the checks found, and which fields the criteria lock has
 * frozen.
 *
 * The locked field list is part of the read and not only part of the refusal.
 * A screen that has to discover the lock by attempting a save and reading a
 * 409 is a screen that offers an author a control which cannot work, and the
 * lock is a published fact of the credential rather than a server-side
 * accident.
 */
final class CertificateTemplateView
{
    /**
     * @param array<string, mixed>            $row      the certificate_templates row
     * @param list<CertificateTemplatePageView> $pages   empty on the list shape
     * @param list<PublicationFinding>        $findings empty on the list shape
     * @param list<string>                    $lockedFields
     */
    public function __construct(
        public readonly array $row,
        public readonly array $pages,
        public readonly int $pageCount,
        public readonly int $elementCount,
        public readonly array $findings,
        public readonly array $lockedFields,
    ) {
    }

    public function id(): int
    {
        return (int) ($this->row['id'] ?? 0);
    }

    public function revision(): int
    {
        $revision = $this->row['revision'] ?? 1;

        return is_numeric($revision) ? (int) $revision : 1;
    }

    public function isLocked(): bool
    {
        return $this->lockedFields !== [];
    }
}
