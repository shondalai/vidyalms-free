<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificatePdfAssets;
use Shondalai\VidyaLMS\Core\Boot\CredentialProviders;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Pdf\PdfDocument;
use Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort;
use Shondalai\VidyaLMS\Core\Port\Storage\ReadsPublicFiles;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Provider\Certificate\PdfCertificateRenderer;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;

final class PdfCertificateRendererTest extends TestCase
{
    private function renderer(PdfPort $port, ?StoragePort $storage = null): PdfCertificateRenderer
    {
        $elements = new CertificateElementRegistry();

        foreach (CredentialProviders::elements() as $element) {
            $elements->register($element);
        }

        return new PdfCertificateRenderer($elements, new CatalogueTranslator(), $port, $storage);
    }

    public function testThePortReceivesResolvedTextLabelsReadableVerificationAndVectorQr(): void
    {
        $port = $this->createMock(PdfPort::class);
        $port->method('provenance')->willReturn(['engine' => 'fixture', 'fonts' => ['regular' => 'hash']]);
        $port->expects(self::once())->method('render')->willReturnCallback(static function (PdfDocument $document): string {
            self::assertSame(297.0, $document->width);
            self::assertSame('Sofía Müller <script>literal</script>', $document->pages[0][0]['text']);
            self::assertSame('Issued on 5 September 2026', $document->pages[0][1]['text']);
            self::assertSame('ABCD-2345 https://example.test/verify/ABCD2345', $document->pages[0][2]['text']);
            self::assertSame('qr', $document->pages[0][3]['op']);
            self::assertIsArray($document->pages[0][3]['matrix']);
            self::assertSame([], $document->assets);

            return '%PDF-fixture';
        });
        $page = new CertificateTemplatePage(1, 1, elements: [
            new CertificateTemplateElement(1, 1, 'learner_name', width: 200, height: 30),
            new CertificateTemplateElement(2, 1, 'issue_date', ['label' => 'Issued on'], posY: 40, width: 200, height: 20),
            new CertificateTemplateElement(3, 1, 'verification_code', posY: 70, width: 200, height: 20),
            new CertificateTemplateElement(4, 1, 'qr_code', posY: 100, width: 28, height: 28),
        ]);
        $renderer = $this->renderer($port);
        $artifact = $renderer->renderBinary(CertificateRenderContext::create([], ['format' => 'elements'], [
            'user.full_name' => 'Sofía Müller <script>literal</script>', 'certificate.issued_at' => '5 September 2026',
            'certificate.code_display' => 'ABCD-2345', 'certificate.verification_url' => 'https://example.test/verify/ABCD2345',
        ], pages: [$page], purpose: CertificateRenderContext::PURPOSE_PREVIEW));
        self::assertSame('application/pdf', $artifact->mediaType);
        self::assertSame('certificate-preview.pdf', $artifact->suggestedFilename);
    }

    public function testPublicationFreezesImageBytesAndIssuedRenderingNeverReadsTheMutableFileAgain(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jg1cAAAAASUVORK5CYII=', true);
        self::assertIsString($png);
        $storage = $this->createMockForIntersectionOfInterfaces([StoragePort::class, ReadsPublicFiles::class]);
        self::assertInstanceOf(StoragePort::class, $storage);
        $storage->expects(self::once())->method('readPublic')->with('seals/owned.png', 1048576)->willReturn($png);
        $port = $this->createMock(PdfPort::class);
        $port->method('provenance')->willReturn(['engine' => 'fixture']);
        $port->expects(self::once())->method('render')->willReturnCallback(static function (PdfDocument $document) use ($png): string {
            self::assertSame(hash('sha256', $png), $document->assets['seals/owned.png']['sha256']);
            self::assertSame(base64_encode($png), $document->assets['seals/owned.png']['base64']);

            return '%PDF-fixture';
        });
        $page = new CertificateTemplatePage(1, 1, elements: [
            new CertificateTemplateElement(1, 1, 'image', ['path' => 'seals/owned.png', 'alt' => 'Seal'], width: 30, height: 30),
        ]);
        $renderer = $this->renderer($port, $storage);
        $template = ['format' => 'elements'] + $renderer->prepareSource(['format' => 'elements'], [$page]);
        $renderer->renderBinary(CertificateRenderContext::create([], $template, pages: [$page], fromSnapshot: true));
    }

    public function testChangedRuntimeCannotRerenderAFrozenCertificate(): void
    {
        $port = $this->createMock(PdfPort::class);
        $port->method('provenance')->willReturn(['engine' => 'new-version']);
        $port->expects(self::never())->method('render');
        $renderer = $this->renderer($port);
        $this->expectException(ConflictException::class);
        $renderer->renderBinary(CertificateRenderContext::create([], [
            'format' => 'elements', 'render_provenance' => ['engine' => 'old-version'],
        ], pages: [new CertificateTemplatePage(1, 1)], fromSnapshot: true));
    }

    public function testJsonDatabaseObjectKeyReorderingDoesNotChangeFrozenRuntimeIdentity(): void
    {
        $port = $this->createMock(PdfPort::class);
        $port->method('provenance')->willReturn(['engine' => 'fixture', 'fonts' => ['regular' => 'a', 'bold' => 'b']]);
        $port->expects(self::once())->method('render')->willReturn('%PDF-fixture');
        $renderer = $this->renderer($port);
        $source = ['format' => 'elements', 'render_provenance' => [
            'fonts' => ['bold' => 'b', 'regular' => 'a'], 'source_grammar' => 1, 'engine' => 'fixture', 'renderer' => 'pdf',
        ]];
        self::assertSame('application/pdf', $renderer->renderBinary(CertificateRenderContext::create(
            [],
            $source,
            pages: [new CertificateTemplatePage(1, 1)],
            fromSnapshot: true,
        ))->mediaType);
    }

    public function testRuntimeRejectsIncompatibleDocumentContracts(): void
    {
        $identity = ['engine' => 'fixture', 'fonts' => ['regular' => 'a', 'bold' => 'b'], 'order' => ['a', 'b']];
        $port = $this->createMock(PdfPort::class);
        $port->method('provenance')->willReturn($identity);
        $port->expects(self::never())->method('render');
        $renderer = $this->renderer($port);
        $expected = $renderer->renderProvenance();
        $missing = $expected;
        unset($missing['source_grammar']);

        foreach (
            [
            array_replace($expected, ['source_grammar' => '1']),
            array_replace($expected, ['engine' => 'another-engine']),
            array_replace($expected, ['renderer' => 'html']),
            $expected + ['worker_grammar' => 99], $missing,
            ] as $changed
        ) {
            try {
                $renderer->renderBinary(CertificateRenderContext::create([], [
                    'format' => 'elements', 'render_provenance' => $changed,
                ], pages: [new CertificateTemplatePage(1, 1)], fromSnapshot: true));
                self::fail('Changed frozen runtime evidence must fail closed.');
            } catch (ConflictException $failure) {
                self::assertStringContainsString('frozen PDF renderer', $failure->getMessage());
            }
        }
    }

    public function testSecurityUpdatesDoNotInvalidatePublishedTemplates(): void
    {
        $port = $this->createMock(PdfPort::class);
        $port->method('provenance')->willReturn([
            'engine' => 'dompdf', 'worker_grammar' => 2, 'engine_version' => '3.1.7',
            'dependency_lock_sha256' => 'new-lock', 'drawing_sha256' => 'new-code',
            'canvas_sha256' => 'new-canvas', 'fonts' => ['regular' => 'new-font'],
        ]);
        $port->expects(self::once())->method('render')->willReturn('%PDF-updated-runtime');
        $renderer = $this->renderer($port);
        $source = ['format' => 'elements', 'render_provenance' => array_replace($renderer->renderProvenance(), [
            'engine_version' => '3.1.6', 'dependency_lock_sha256' => 'old-lock',
            'drawing_sha256' => 'old-code', 'canvas_sha256' => 'old-canvas', 'fonts' => ['regular' => 'old-font'],
        ])];
        self::assertSame('%PDF-updated-runtime', $renderer->renderBinary(CertificateRenderContext::create(
            [],
            $source,
            pages: [new CertificateTemplatePage(1, 1)],
            fromSnapshot: true,
        ))->bytes);
        self::assertSame('old-lock', $source['render_provenance']['dependency_lock_sha256']);
    }

    public function testUnknownProviderAndUnsafeImageAreBlockingBeforePublication(): void
    {
        $port = $this->createStub(PdfPort::class);
        $renderer = $this->renderer($port);
        $findings = $renderer->sourceFindings(['format' => 'elements'], [
            new CertificateTemplatePage(1, 1, background: 'https://127.0.0.1/private', elements: [
                new CertificateTemplateElement(1, 1, 'uninstalled'),
            ]),
            new CertificateTemplatePage(2, 1, width: 210, height: 297),
        ]);
        self::assertSame(['pdf.element', 'pdf.asset'], array_column($findings, 'code'));
        self::assertTrue($findings[0]->blocks());
    }

    public function testExactLetterAndMixedPaperSurviveSourceSnapshotsAndTheMechanicalPort(): void
    {
        $port = $this->createMock(PdfPort::class);
        $port->method('provenance')->willReturn([]);
        $port->expects(self::once())->method('render')->with(self::callback(static function (PdfDocument $document): bool {
            self::assertSame([['width' => 215.9, 'height' => 279.4], ['width' => 297.0, 'height' => 210.0]], $document->pageSizes);
            return true;
        }))->willReturn('%PDF-test');
        $page = new CertificateTemplatePage(1, 1, width: 215.9, height: 279.4);
        self::assertSame(215.9, CertificateTemplatePage::fromSnapshot($page->toSnapshot())->width);
        $renderer = $this->renderer($port);
        $renderer->renderBinary(CertificateRenderContext::create([], ['format' => 'elements'], [], pages: [
            $page, new CertificateTemplatePage(2, 1, sequence: 2),
        ]));
    }

    public function testInvalidCssStyleIsRejectedBeforeItReachesTheMechanicalPort(): void
    {
        $port = $this->createMock(PdfPort::class);
        $port->expects(self::never())->method('render');
        $renderer = $this->renderer($port);
        $page = new CertificateTemplatePage(1, 1, elements: [
            new CertificateTemplateElement(1, 1, 'text', ['text' => 'Safe', 'colour' => 'red; background:url(file:///secret)']),
        ]);
        self::assertSame('pdf.style', $renderer->sourceFindings(['format' => 'elements'], [$page])[0]->code);
        $this->expectException(ValidationException::class);
        $renderer->prepareSource(['format' => 'elements'], [$page]);
    }

    public function testAssetValidatorRefusesSvgAndTraversalAndByteOverflow(): void
    {
        foreach (['../secret.png', '/absolute.png', 'C:/secret.png', 'https://example.test/logo.png', 'data:x', 'a/../b.png'] as $path) {
            self::assertFalse(CertificatePdfAssets::safePath($path));
        }
        self::assertTrue(CertificatePdfAssets::safePath('certificates/own-logo.png'));
        $this->expectException(ValidationException::class);
        CertificatePdfAssets::imageType('<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///secret"/></svg>');
    }

    public function testColourPanelsProduceBoundedVectorFillsAndFineRules(): void
    {
        $port = $this->createMock(PdfPort::class);
        $port->method('provenance')->willReturn(['engine' => 'fixture']);
        $port->expects(self::once())->method('render')->willReturnCallback(static function (PdfDocument $document): string {
            self::assertSame('fill', $document->pages[0][0]['op']);
            self::assertSame('#202c3b', $document->pages[0][0]['colour']);
            self::assertSame(297.0, $document->pages[0][0]['w']);
            self::assertSame(210.0, $document->pages[0][0]['h']);
            self::assertEqualsWithDelta(25.4 / 72, $document->pages[0][1]['h'], 0.00001);
            return '%PDF-fixture';
        });
        $renderer = $this->renderer($port);
        $page = new CertificateTemplatePage(1, 1, elements: [
            new CertificateTemplateElement(1, 1, 'colour_panel', ['colour' => '#202c3b'], width: 297, height: 210),
            new CertificateTemplateElement(
                2,
                1,
                'colour_panel',
                ['colour' => '#cbb67c', 'rule_width_pt' => 1],
                sequence: 2,
                posX: 40,
                posY: 168,
                width: 180,
                height: 1
            ),
        ]);
        self::assertSame([], $renderer->sourceFindings(['format' => 'elements'], [$page]));
        $renderer->renderBinary(CertificateRenderContext::create([], ['format' => 'elements'], pages: [$page]));
    }

    public function testPageAndElementCountsAreBounded(): void
    {
        $renderer = $this->renderer($this->createStub(PdfPort::class));
        $tooManyPages = array_fill(0, 9, new CertificateTemplatePage(1, 1));
        self::assertSame('pdf.source', $renderer->sourceFindings(['format' => 'elements'], $tooManyPages)[0]->code);
        $page = new CertificateTemplatePage(1, 1, elements: array_fill(0, 101, new CertificateTemplateElement(1, 1, 'text')));
        self::assertSame('pdf.paper', $renderer->sourceFindings(['format' => 'elements'], [$page])[0]->code);
    }
}
