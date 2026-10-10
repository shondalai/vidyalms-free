<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\Credential\DeclaresFormatPreference;

/**
 * A binary format outranking the free JSON one, which is the shape of two of
 * the six shipped formats: the free baked PNG and the paid PAdES document.
 *
 * The bytes are deliberately not valid UTF-8, because that is the whole
 * failure: credential_json is a MEDIUMTEXT column in a utf8mb4 table, and a
 * site whose highest-ranked available format was binary wrote raw bytes into
 * it inside the issuance transaction.
 */
final class FakeBinaryFormat implements CredentialFormatInterface, DeclaresFormatPreference
{
    public const KEY = 'fake.binary';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Fake binary';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function preference(): int
    {
        return 200;
    }

    public function build(IssuanceRecord $record, CredentialContext $context): CredentialArtefact
    {
        return CredentialArtefact::of(
            RenderedArtefact::of("%PDF-1.7\n\xFF\xFE\x00\x01binary", $this->mimeType(), 'credential.pdf'),
            self::KEY,
            'FakePades2026',
        );
    }
}
