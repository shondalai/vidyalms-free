<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Domain\Commerce;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgEntitlementGrantContext;

/**
 * One vocabulary with two families in it, and one derivation of the digest
 * the unique index is carried on.
 */
final class SourceRefTest extends TestCase
{
    public function testTheDigestSeparatorIsTheShippedColonAndNotANul(): void
    {
        // The organisation slice wrote real digests with a colon on both
        // development sites under a live unique index before the commerce
        // path had a writer. Changing the separator now orphans every one of
        // them, so the shipped derivation is the one this class publishes.
        self::assertSame(
            hash('sha256', 'org:org7:course-3:member-11:grant-2'),
            SourceRef::digest('org', 'org7:course-3:member-11:grant-2'),
        );

        self::assertSame(':', SourceRef::DIGEST_SEPARATOR);
    }

    public function testANullReferenceWritesANullDigest(): void
    {
        // Which is what preserves the manual and import paths' reliance on
        // MySQL and SQLite treating nulls as distinct in a unique index.
        self::assertNull(SourceRef::digest('manual', null));
    }

    public function testTheCommerceFamilyAlwaysNamesTheCourse(): void
    {
        self::assertSame(
            'wc-order-9001-course-42',
            SourceRef::compose('wc', 'order', 9001, 42),
        );
        self::assertSame(
            'native-order-12-item-3-course-42',
            SourceRef::compose('native', 'order', 12, 42, itemId: 3),
        );
        self::assertSame(
            'native-gift-77-user-501-course-42',
            SourceRef::compose('native', 'gift', 77, 42, beneficiaryUserId: 501),
        );
        self::assertSame(
            'native-order-12-course-42-r2',
            SourceRef::compose('native', 'order', 12, 42, reversalOrdinal: 2),
        );

        $this->expectException(\InvalidArgumentException::class);
        SourceRef::compose('wc', 'order', 9001, 0);
    }

    public function testTheOrgPrefixIsReservedSoTwoFamiliesCannotCollide(): void
    {
        $this->expectExceptionMessage('reserved for organisation references');
        SourceRef::compose('org7', 'order', 1, 42);
    }

    public function testAnOrganisationRefAndACommerceRefCannotDigestIdentically(): void
    {
        // The guarantee the reserved prefix buys, asserted rather than
        // asserted about: no commerce source key can produce a reference that
        // opens the way an organisation one does, so no pair of refs from the
        // two families can ever be the same string.
        $orgRef = SourceRef::organisationGrant(7, 3, 11, 2);
        self::assertTrue(SourceRef::isOrganisationRef($orgRef));

        $commerceRef = SourceRef::compose('wc', 'order', 7, 3);
        self::assertFalse(SourceRef::isOrganisationRef($commerceRef));
        self::assertNotSame(
            SourceRef::digest('org', $orgRef),
            SourceRef::digest('purchase', $commerceRef),
        );
    }

    public function testTheOrganisationFamilyIsTheShippedShapeVerbatim(): void
    {
        self::assertSame('org7:course-3:member-11:grant-2', SourceRef::organisationGrant(7, 3, 11, 2));
        // The seat stem carries assignment_ref, which is what the published
        // grammar always said and what the code did not do: the autoincrement
        // id is unique only because a reclaim nulls holder_key so the next
        // assign inserts a fresh row, which is uniqueness by consequence.
        self::assertSame(
            'org7:seat-assign-4F2A9C1B7E0D5A386C41B2E9D07A5F13:course-3',
            SourceRef::organisationSeat(7, '4F2A9C1B7E0D5A386C41B2E9D07A5F13', 3)
        );

        // A reference is opaque and alphanumeric, so nothing can smuggle a
        // separator or another family's stem into the middle of a ref.
        foreach (['', 'has-a-hyphen', 'has:a:colon', str_repeat('A', 65)] as $refused) {
            try {
                SourceRef::organisationSeat(7, $refused, 3);
                self::fail('An assignment reference of "' . $refused . '" must be refused.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('assignment reference', $error->getMessage());
            }
        }
    }

    /**
     * The organisation grant context composes through this helper rather than
     * beside it, which is what makes the two families one vocabulary rather
     * than two grammars sharing a column.
     */
    public function testTheOrganisationGrantContextComposesThroughTheHelper(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../../../src/Application/Organisation/OrgEntitlementGrantContext.php',
        );
        self::assertIsString($source);

        self::assertStringContainsString('SourceRef::organisationGrant(', $source);
        self::assertStringContainsString('SourceRef::organisationSeat(', $source);
        self::assertTrue(class_exists(OrgEntitlementGrantContext::class));
    }

    public function testTheDuplicateRepairKeyIsOutsideBothFamilies(): void
    {
        $repaired = SourceRef::duplicateRepairRef('wc-order-9001-course-42', 55);

        self::assertSame('wc-order-9001-course-42-dup55', $repaired);
        // A repaired key must never match a replay, a reversal ordinal or a
        // future bridge's composition, so it cannot be a value compose()
        // produces.
        self::assertNotSame(SourceRef::compose('wc', 'order', 9001, 42, reversalOrdinal: 55), $repaired);
    }

    public function testTheDuplicateRepairKeyStaysInsideTheColumnAndKeepsItsSuffix(): void
    {
        $long = str_repeat('a', SourceRef::MAX_LENGTH);
        $repaired = SourceRef::duplicateRepairRef($long, 55);

        // A reference already at the column's width would otherwise have its
        // suffix truncated back onto the winner's key by a non-strict MySQL,
        // which is the one way this repair produces the collision it removes.
        self::assertSame(SourceRef::MAX_LENGTH, \strlen($repaired));
        self::assertStringEndsWith('-dup55', $repaired);
        self::assertNotSame(SourceRef::duplicateRepairRef($long, 56), $repaired);
    }

    public function testAnObjectSegmentCarryingACourseRunIsRefused(): void
    {
        // compose('wc', 'order', '5-course-99', 7) produced
        // wc-order-5-course-99-course-7, which no reader can take apart again
        // and which breaks rule 2 of the grammar.
        $this->expectException(\InvalidArgumentException::class);
        SourceRef::compose('wc', 'order', '5-course-99', 7);
    }

    public function testAnObjectTypeCarryingTheOrganisationSeparatorIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SourceRef::compose('wc', 'order:seat', 5, 7);
    }
}
