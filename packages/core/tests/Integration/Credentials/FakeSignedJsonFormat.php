<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\Credential\DeclaresFormatPreference;
use Shondalai\VidyaLMS\Core\Registry\Credential\ReportsAvailability;

/**
 * A securing mechanism claiming the same media type as the free document,
 * which is exactly what the two shipped paid JSON-LD formats do.
 *
 * It also reports its own availability, because a signing format with no key
 * configured is installed and cannot serve, and that has to be a negotiation
 * miss rather than an exception escaping into a 500.
 */
final class FakeSignedJsonFormat implements
    CredentialFormatInterface,
    DeclaresFormatPreference,
    ReportsAvailability
{
    public const KEY = 'fake.signed';

    public function __construct(private readonly bool $available = true)
    {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Fake signed';
    }

    public function mimeType(): string
    {
        return 'application/ld+json';
    }

    public function preference(): int
    {
        return 100;
    }

    public function build(IssuanceRecord $record, CredentialContext $context): CredentialArtefact
    {
        return CredentialArtefact::of(
            RenderedArtefact::of('{"proof":"fake"}', $this->mimeType(), 'credential.json'),
            self::KEY,
            'FakeProof2026',
        );
    }
}
