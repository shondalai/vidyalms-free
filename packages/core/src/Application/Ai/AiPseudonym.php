<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

/**
 * The one way a person becomes a reference a bridge may see.
 *
 * A bridge receives `hash('sha256', $siteIdentifier . $userId)` rather than a
 * user id, a name or an email, for the reason the precedent this copies
 * documents: the site can still determine who made the request without
 * passing personal data to the model provider.
 *
 * It is salted with the site reference so two sites never mint the same
 * pseudonym for the same person, which is what stops a shared model host
 * correlating one learner across installations.
 *
 * One implementation, in one place, because a second one that forgot the salt
 * would be indistinguishable from this one at every call site.
 */
final class AiPseudonym
{
    private function __construct()
    {
    }

    public static function forUser(string $siteRef, int $userId): string
    {
        return hash('sha256', $siteRef . $userId);
    }

    /**
     * The same shape for a subject that is not the caller, such as the
     * learner a feedback draft is about. Kept distinct in its own namespace
     * so a run's caller reference and its subject references cannot be
     * confused for one another when they happen to be the same person.
     */
    public static function forSubject(string $siteRef, string $subjectType, int $subjectId): string
    {
        return hash('sha256', $siteRef . ':' . $subjectType . ':' . $subjectId);
    }
}
