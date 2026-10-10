<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * A type that lets the marking rule be chosen rather than welding it into
 * grade().
 *
 * Precedence is stated once and asserted at boot: the policy-side shape
 * binding is authoritative for what may be offered, and supportedPolicies()
 * may only narrow that set, never widen it. A decorator policy that declares
 * itself applicable to any shape is therefore applicable wherever its base
 * policy is, without every shipped catalogue entry being edited to list it.
 * Requiring that edit is precisely the fork this design forbids.
 */
interface ScoringPolicyAwareInterface
{
    /**
     * @return list<string> policy keys this type narrows to, empty for "any
     *                      policy the shape binding allows"
     */
    public function supportedPolicies(): array;

    /**
     * The policy a new item of this type gets when the author names none.
     */
    public function defaultPolicy(): string;

    /**
     * The policy a definition authored before the policy field existed is
     * graded under, so no historic percentage moves. Null when the type has
     * no legacy behaviour to pin.
     */
    public function legacyPolicy(): ?string;
}
