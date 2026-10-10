<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;

/**
 * A subject is optional for export; the tenant scope never is.
 *
 * It is the context object handed to every third-party privacy provider, and it
 * was the one context in the core with a public constructor. Seventeen of the
 * eighteen carry a private constructor and named factories, and this is the one
 * where the pattern earns most: any caller could mint a pairing of one
 * organisation's scope with an unrelated person's id and hand it to another
 * contributor's `eraseFor()`, which is an operation with no undo.
 *
 * The two factories say which of the two shapes is being built, so a caller
 * cannot reach the subject shape by leaving an argument off.
 */
final class OrgPrivacyContext
{
    private function __construct(public readonly OrgScope $scope, public readonly ?int $userId = null)
    {
        if ($userId !== null && $userId < 1) {
            throw new \InvalidArgumentException('A privacy subject must be a positive user id.');
        }
    }

    /** The whole organisation, with no subject: the export and Article 30 shape. */
    public static function forOrganisation(OrgScope $scope): self
    {
        return new self($scope);
    }

    /** One subject inside one organisation: the erasure shape. */
    public static function forSubject(OrgScope $scope, int $userId): self
    {
        return new self($scope, $userId);
    }
}
