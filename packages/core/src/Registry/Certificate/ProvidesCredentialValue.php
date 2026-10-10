<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * The value an element puts at its declared credential property, when that is
 * not the line it prints.
 *
 * Default when absent: the claim is the element's own resolveText(), which is
 * right for a name, a title or a date, where the printed line and the
 * machine-readable fact are the same string.
 *
 * It exists for the ones where they are not. Open Badges 3.0 declares
 * AchievementSubject.creditsEarned as a number, and the credit elements print
 * "6.00 hours (Accessibility)", possibly over two lines on a course carrying
 * credit under two schemes. Writing that string at a numeric property is a
 * document a conforming displayer cannot read, and widening the claim rather
 * than adding a method to the shipped ContributesToCredential keeps every
 * external implementation of that interface working.
 */
interface ProvidesCredentialValue
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return string|int|float|null null means the element contributes nothing on this certificate
     */
    public function credentialValue(array $payload, ElementRenderContext $context): string|int|float|null;
}
