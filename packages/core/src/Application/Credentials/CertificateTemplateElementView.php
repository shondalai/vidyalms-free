<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;

/**
 * One stored element together with the registry's answer about it.
 *
 * The stored row and the registry's answer are two different facts and the
 * authoring surface needs both: an element whose provider is not registered
 * is returned untouched with `available` false and renders as a visible
 * placeholder naming the missing package, because dropping it would lose an
 * author's work the moment a package is uninstalled.
 *
 * `currentVersion` is the version the provider declares now, which is what
 * lets a designer store a new element at the current shape while leaving an
 * older sibling alone until it is edited. A stored version above it is a miss
 * and is reported as unavailable, which is the case a plain presence check
 * cannot see.
 */
final class CertificateTemplateElementView
{
    public function __construct(
        public readonly CertificateTemplateElement $element,
        public readonly bool $available,
        public readonly int $currentVersion,
        public readonly ?string $package,
    ) {
    }
}
