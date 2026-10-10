<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * A renderer written against the shipped interface alone still publishes.
 *
 * SupportsTemplateFormats arrived beside CertificateRendererInterface, after
 * third parties were invited to implement the interface. Treating its absence
 * as "consumes no format" narrowed the shipped contract retroactively: an
 * external renderer that had always been able to read a template body was
 * refused at publication for not answering a question that did not exist when
 * it was written.
 */
final class RendererFormatDefaultTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->kernel->registries->certificateRenderers->register(new PlainCertificateRenderer());
    }

    public function testARendererDeclaringNoFormatsIsOfferedForTheHtmlGrammar(): void
    {
        $options = $this->fixture->kernel->certificateStudio->rendererOptions();
        $offered = [];

        foreach ($options as $option) {
            $offered[$option['key']] = $option['formats'];
        }

        self::assertArrayHasKey(PlainCertificateRenderer::KEY, $offered);
        self::assertSame(['html'], $offered[PlainCertificateRenderer::KEY]);
    }

    public function testAnHtmlTemplateOnThatRendererPublishes(): void
    {
        $id = $this->createTemplate('html');
        $published = $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1]);

        self::assertSame(200, $published->status, json_encode(self::data($published), JSON_THROW_ON_ERROR));
        self::assertSame('published', self::data($published)['state']);
    }

    /**
     * The refusal is still there for the grammar the default does not cover:
     * degrading means accepting less, never accepting everything.
     */
    public function testAnElementsTemplateOnThatRendererIsStillRefused(): void
    {
        $id = $this->createTemplate('elements');

        self::assertSame(
            422,
            $this->call('POST', "/manage/certificate-templates/$id/publish", ['expected_revision' => 1])->status,
        );
    }

    private function createTemplate(string $format): int
    {
        $created = $this->call('POST', '/manage/certificate-templates', [
            'name' => 'External renderer ' . $format,
            'format' => $format,
            'renderer' => PlainCertificateRenderer::KEY,
            'body' => '<p>Awarded to {learner_name}. Verify at {verification_url}.</p>',
        ]);

        self::assertSame(201, $created->status, json_encode(self::data($created), JSON_THROW_ON_ERROR));

        return (int) self::data($created)['id'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function call(string $method, string $path, array $body = []): ApiResponse
    {
        return $this->fixture->call($method, $path, $this->fixture->manager(), $body, idempotencyKey: bin2hex(random_bytes(8)));
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(ApiResponse $response): array
    {
        self::assertNotNull($response->data);

        return $response->data;
    }
}
