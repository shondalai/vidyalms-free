<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** Only allowlisted fields, never invitation proofs, hashes or arbitrary event payloads. */
final class SubjectPrivacyGroup
{
    /** @param list<string> $fields
     * @param \Closure(): Query $query
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly array $fields,
        public readonly \Closure $query,
    ) {
    }
}
