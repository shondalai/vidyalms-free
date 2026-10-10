<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;

/**
 * One media-type table, published from the package that owns the artefact
 * shape.
 *
 * Four closed copies of parts of it existed and two of them already
 * disagreed: the same baked SVG credential downloaded as image/svg+xml on
 * Joomla and as an unknown blob on WordPress, and the core answered the same
 * question two ways inside one package, so a credential in a format the core
 * already knows about downloaded as .json carrying SVG.
 */
final class RenderedArtefactMediaTypesTest extends TestCase
{
    public function testTheExtensionIsDerivedFromTheMediaTypeAndNeverAssumed(): void
    {
        self::assertSame('html', RenderedArtefact::extensionFor('text/html'));
        self::assertSame('svg', RenderedArtefact::extensionFor('image/svg+xml'));
        self::assertSame('json', RenderedArtefact::extensionFor('application/ld+json'));
        self::assertSame('jwt', RenderedArtefact::extensionFor('application/vc+jwt'));
        self::assertSame('bin', RenderedArtefact::extensionFor('application/x-nothing'));
    }

    public function testTheInverseAnswersOnlyWhatThisProductClaims(): void
    {
        self::assertSame('image/svg+xml', RenderedArtefact::mediaTypeFor('svg'));
        self::assertSame('application/pdf', RenderedArtefact::mediaTypeFor('.PDF'));
        // Two types map to one extension, so the extension answers the one
        // every client can read; the linked-data type is chosen by content
        // negotiation and never by a filename.
        self::assertSame('application/json', RenderedArtefact::mediaTypeFor('json'));
        self::assertNull(RenderedArtefact::mediaTypeFor('xlsx'), 'a host list still owns its own types');
    }

    public function testEveryExtensionTheTableNamesRoundTripsToAMediaType(): void
    {
        foreach (RenderedArtefact::EXTENSIONS as $mediaType => $extension) {
            self::assertNotNull(RenderedArtefact::mediaTypeFor($extension), $mediaType);
        }
    }

    public function testOnlyTextualTypesMayBeFrozenIntoATextColumn(): void
    {
        self::assertTrue(RenderedArtefact::isTextual('application/ld+json'));
        self::assertTrue(RenderedArtefact::isTextual('application/json'));
        self::assertTrue(RenderedArtefact::isTextual('application/vc+jwt'));
        self::assertTrue(RenderedArtefact::isTextual('text/html'));
        self::assertTrue(RenderedArtefact::isTextual('image/svg+xml'));

        // The two binary shipped formats, one of them free.
        self::assertFalse(RenderedArtefact::isTextual('application/pdf'));
        self::assertFalse(RenderedArtefact::isTextual('image/png'));
    }
}
