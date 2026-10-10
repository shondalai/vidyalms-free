<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Token;

/**
 * A token family that names placeholders which must never be frozen onto a
 * credential.
 *
 * Default when absent: the family freezes everything it can answer, which is
 * right for a code, a title or a date.
 *
 * It exists because the frozen bag is the union of the registry rather than
 * the placeholders a template names, so a package registering a resolver that
 * can answer a plaintext identifier (an SSO subject, a payroll number, a
 * certifying board id) froze it onto every certificate for the retention life
 * of the credential, copied verbatim into every reissue, and the only way to
 * exclude it was to edit a core constant. That inverts the extensibility rule
 * in the one area whose privacy table is strictest.
 */
interface DeclaresUnfrozenTokens
{
    /**
     * @return list<string> fully qualified token names, e.g. "user.email"
     */
    public function neverFrozen(): array;
}
