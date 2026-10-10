<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware\NoIndexMiddleware;

/**
 * A response carrying bytes says so on the object.
 *
 * The convention used to be an inline array literal specified by a comment,
 * so each adapter wrote its own heuristic to recognise it and the two had to
 * agree forever on a rule written down nowhere. A drift there does not fail a
 * test; it serves base64 text where a wallet expected a PNG.
 */
final class BinaryResponseTest extends TestCase
{
    public function testABinaryResponseAnswersForItselfRatherThanBeingSniffed(): void
    {
        $response = ApiResponse::binary("\x89PNG\r\n\x1a\n", 'image/png', 'badge.png');

        self::assertTrue($response->isBinary());
        self::assertSame("\x89PNG\r\n\x1a\n", $response->binaryBytes());
        self::assertSame('image/png', $response->headers['Content-Type']);
        self::assertSame('inline; filename="badge.png"', $response->headers['Content-Disposition']);
    }

    public function testAnOrdinaryDocumentCarryingAContentFieldIsNotBytes(): void
    {
        // The reason the heuristic had to be narrow, recorded nowhere before
        // and now enforced: this is a JSON document, not a PNG.
        $response = ApiResponse::ok(['content' => 'some markdown', 'encoding' => 'base64']);

        self::assertFalse($response->isBinary());
        self::assertSame('', $response->binaryBytes());
    }

    public function testTheBase64WireFormIsKeptSoAnExistingEmitterStillWorks(): void
    {
        $response = ApiResponse::binary('raw-bytes', 'application/pdf', 'certificate.pdf');

        self::assertIsArray($response->data);
        self::assertSame(base64_encode('raw-bytes'), $response->data[ApiResponse::BINARY_CONTENT_KEY]);
        self::assertSame(ApiResponse::BINARY_ENCODING, $response->data[ApiResponse::BINARY_ENCODING_KEY]);
    }

    public function testExtraHeadersAreCarriedAndAQuoteCannotBreakTheFilename(): void
    {
        $response = ApiResponse::binary('x', 'image/png', 'we"ird.png', ['X-Robots-Tag' => 'noindex']);

        self::assertSame('noindex', $response->headers['X-Robots-Tag']);
        self::assertSame('inline; filename="weird.png"', $response->headers['Content-Disposition']);
    }

    public function testAddingAHeaderKeepsTheBytes(): void
    {
        // The credential routes are behind NoIndexMiddleware, which used to
        // rebuild the response with the three-argument constructor. That
        // dropped the bytes, so the baked PNG reached both adapters as an
        // ordinary document and their emitters, which ask rather than sniff,
        // wrote base64 text under an image/png Content-Type. The old
        // convention hid it: the envelope it sniffed for rides on data and
        // survived the rebuild.
        $response = ApiResponse::binary("\x89PNG", 'image/png', 'badge.png', ['X-Robots-Tag' => 'noindex'])
            ->withHeaders([NoIndexMiddleware::HEADER => NoIndexMiddleware::VALUE]);

        self::assertTrue($response->isBinary());
        self::assertSame("\x89PNG", $response->binaryBytes());
        self::assertSame('image/png', $response->headers['Content-Type']);
        // The middleware's value wins, which is the precedence it already had.
        self::assertSame(NoIndexMiddleware::VALUE, $response->headers[NoIndexMiddleware::HEADER]);
    }

    public function testTheNoIndexMiddlewareLeavesABinaryCredentialBinary(): void
    {
        $middleware = new NoIndexMiddleware(['/credentials/']);
        $request = new ApiRequest('GET', '/credentials/abc', Principal::guest());

        $response = $middleware->process(
            $request,
            static fn (): ApiResponse => ApiResponse::binary("\x89PNG", 'image/png', 'badge.png'),
        );

        self::assertTrue($response->isBinary());
        self::assertSame("\x89PNG", $response->binaryBytes());
        self::assertSame(NoIndexMiddleware::VALUE, $response->headers[NoIndexMiddleware::HEADER]);
    }
}
