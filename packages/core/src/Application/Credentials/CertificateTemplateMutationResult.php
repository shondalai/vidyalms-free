<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

/**
 * The value produced by one claimed template-aggregate mutation together
 * with the authoritative root revision committed by that same transaction.
 *
 * @template T
 */
final class CertificateTemplateMutationResult
{
    /**
     * @param T $value
     */
    public function __construct(
        public readonly mixed $value,
        public readonly int $revision,
    ) {
    }
}
