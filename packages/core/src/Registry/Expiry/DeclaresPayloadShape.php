<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Expiry;

/**
 * The version this expiry policy writes its own settings in.
 *
 * **It exists because `course_certificate_policies.expiry` held a provider's
 * own settings with no version beside it.** Every newer seam in this product
 * stamps one from the provider's own answer; this bag was stored bare, so a
 * policy that moved its shape could not tell its old rows apart.
 *
 * A capability interface probed with `instanceof` rather than a method added
 * to {@see ExpiryPolicyInterface}, because that interface has shipped.
 *
 * The version travels inside the stored JSON through
 * {@see \Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape}, so no column is
 * added to a table that already carries rows and the read strips it before
 * anything downstream sees it. A policy that does not declare this writes no
 * version key, which reads back as unversioned.
 */
interface DeclaresPayloadShape
{
    /** The shape number this provider writes today; one for a first release. */
    public function currentShape(): int;
}
