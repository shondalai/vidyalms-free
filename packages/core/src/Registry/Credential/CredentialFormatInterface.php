<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credential;

use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One machine-readable shape a credential can be served in.
 *
 * Signing is not in the free core and Open Badges conformance is not claimed
 * without it: conformance requires Data Integrity EdDSA and ECDSA, which
 * means key generation, storage and rotation. That is a fact rather than a
 * preference, and it is why credential_json, credential_format and proof_type
 * exist from day one: adding a signed format is then a registration and never
 * a migration.
 *
 * The miss direction is published and is the gentle one: an unregistered
 * format is absent from content negotiation and is never an error, because a
 * wallet asking for a shape this build cannot serve should be told what it
 * can have instead of being handed a failure that reads as ours.
 */
interface CredentialFormatInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The media type this format is negotiated and served under.
     */
    public function mimeType(): string;

    public function build(IssuanceRecord $record, CredentialContext $context): CredentialArtefact;
}
