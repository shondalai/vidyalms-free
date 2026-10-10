<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

/**
 * Which Open Badges property this element mirrors, so the designer can warn
 * that removing it breaks the credential rather than only the picture.
 *
 * Default when absent: the designer cannot warn, and the element is treated
 * as decoration.
 */
interface ContributesToCredential
{
    /**
     * A dotted path into the credential body: "credentialSubject.achievement.name",
     * "validUntil", "credentialSubject.creditsEarned".
     *
     * The last one is where Open Badges 3.0 puts it: creditsEarned is an
     * AchievementSubject property and AchievementSubject is credentialSubject.
     * Achievement defines no such term, so a claim inside it is dropped or
     * errors under JSON-LD expansion with the OB 3.0 context.
     */
    public function credentialProperty(): string;
}
