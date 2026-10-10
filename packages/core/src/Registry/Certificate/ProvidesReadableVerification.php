<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

/**
 * An element that can satisfy the template-level rule requiring the
 * verification URL as real selectable text.
 *
 * Default when absent: the element does not satisfy the rule, which is right
 * for the fifteen that draw something else and for the QR code, whose whole
 * point is that a scan is a convenience and never the only route (SC 1.1.1).
 *
 * It exists because the check reached inside two providers' private payloads
 * by literal key. That is core interpreting an element's own data, which the
 * layout/payload split exists to prevent: renaming a payload key would have
 * silently made every template pass a check written to refuse it, and the
 * paid rich text element, which emits the URL as selectable text under a
 * different key, was refused publication by the free build.
 */
interface ProvidesReadableVerification
{
    /**
     * @param array<string, mixed> $payload the element's own stored payload
     */
    public function emitsVerificationUrl(array $payload): bool;
}
