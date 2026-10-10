<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\Credential\ReportsAvailability;

/**
 * A format that is installed and cannot serve, which is what a signing
 * format with no key reference configured is on a real site.
 *
 * Its media type is unique, so it is the case where an unavailable format
 * would otherwise be the only candidate and the caller must be told the build
 * cannot serve that shape rather than handed an internal error.
 */
final class FakeUnservableFormat implements CredentialFormatInterface, ReportsAvailability
{
    public const KEY = 'fake.unservable';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Fake unservable';
    }

    public function mimeType(): string
    {
        return 'application/vc+jwt';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function build(IssuanceRecord $record, CredentialContext $context): CredentialArtefact
    {
        throw new \RuntimeException('No signing key is configured under the reference "".');
    }
}
