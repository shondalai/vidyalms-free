<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;

/**
 * The stored half of the status enumeration, and what a value outside it
 * means.
 *
 * Reading an unrecognised stored value back as "active" is a fail-open
 * default on the single column that governs whether a credential is in force,
 * in a design where every other registry miss fails closed or fails visible.
 */
final class CredentialStatusVocabularyTest extends TestCase
{
    public function testTheThreeStoredValuesRoundTrip(): void
    {
        self::assertSame(CredentialStatus::Active, CredentialStatus::fromStored('active'));
        self::assertSame(CredentialStatus::Suspended, CredentialStatus::fromStored('suspended'));
        self::assertSame(CredentialStatus::Revoked, CredentialStatus::fromStored('revoked'));
        self::assertSame(CredentialStatus::Superseded, CredentialStatus::fromStored('superseded'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function outOfVocabularyValues(): array
    {
        return [
            ['ACTIVE'],
            ['revokd'],
            // The two derived values, which are computed from the clock and
            // must never be stored: a row carrying one is a defect, and
            // reading it back as in force hides the defect.
            ['expired'],
            ['not_yet_valid'],
            ['written.by.a.package.nobody.installed'],
        ];
    }

    /**
     * @dataProvider outOfVocabularyValues
     */
    public function testAValueOutsideTheStoredVocabularyNeverReadsAsInForce(string $stored): void
    {
        $resolved = CredentialStatus::fromStored($stored);

        self::assertNotSame(
            CredentialStatus::Active,
            $resolved,
            'an unrecognised status column read back as a credential in force',
        );
    }

    public function testAnAbsentColumnIsTheLegacyRowAndStaysInForce(): void
    {
        // A row written before the column existed is not a defect, and
        // reading it as held would withdraw every credential a site issued
        // before the migration.
        self::assertSame(CredentialStatus::Active, CredentialStatus::fromStored(null));
        self::assertSame(CredentialStatus::Active, CredentialStatus::fromStored(''));
    }

    public function testAStoredWithdrawalOutranksEveryClockAnswer(): void
    {
        $now = new \DateTimeImmutable('2028-01-01 00:00:00');

        // "Lapsed" and "withdrawn" are materially different answers to a
        // relying party, so a certificate revoked in 2026 whose expiry passed
        // in 2028 reports revoked and not expired.
        self::assertSame(
            CredentialStatus::Revoked,
            CredentialStatus::resolve(
                CredentialStatus::Revoked,
                null,
                new \DateTimeImmutable('2027-01-01 00:00:00'),
                $now,
            ),
        );

        self::assertSame(
            CredentialStatus::Superseded,
            CredentialStatus::resolve(
                CredentialStatus::Superseded,
                null,
                new \DateTimeImmutable('2027-01-01 00:00:00'),
                $now,
            ),
        );

        self::assertSame(
            CredentialStatus::NotYetValid,
            CredentialStatus::resolve(
                CredentialStatus::Active,
                new \DateTimeImmutable('2029-01-01 00:00:00'),
                new \DateTimeImmutable('2027-01-01 00:00:00'),
                $now,
            ),
        );
    }
}
