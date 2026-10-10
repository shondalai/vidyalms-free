<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credential;

/**
 * A format that says how it ranks against another claiming the same media
 * type.
 *
 * **Absent means the free baseline**, zero, which is what an unsigned
 * document is worth against a signed one under the same Accept header.
 *
 * It exists because three shipped formats declare `application/ld+json`: the
 * free unsigned document, the signed Open Badges 3.0 Data Integrity
 * credential and the CLR 2.0 bundle. Without a declared ranking the registry
 * had nothing to choose by but registration order, and the free build
 * registers first, so a wallet sending the one Accept header it knows was
 * served the unsigned document on a fully licensed site and the paid formats
 * were unreachable. That defeats the whole stated payoff of the format seam,
 * which is that adding a signed format is a registration rather than a
 * migration: it was a registration that changed nothing observable.
 *
 * A securing mechanism ranks above no securing mechanism. Beyond that the
 * numbers are the registering package's own business, and equal preferences
 * fall back to registration order.
 */
interface DeclaresFormatPreference
{
    /**
     * Higher wins among formats sharing one media type.
     */
    public function preference(): int;
}
