<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\Certificate\HtmlCertificateRenderer;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class HtmlCertificateRendererTest extends TestCase
{
    private HtmlCertificateRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new HtmlCertificateRenderer(new StubTranslator());
    }

    public function testKeyIsHtml(): void
    {
        self::assertSame('html', $this->renderer->key());
    }

    public function testLabelComesFromTheTranslator(): void
    {
        self::assertSame('vidyalms.certificate_renderer.html', $this->renderer->label());
    }

    public function testReplacesPlaceholdersWithContextValues(): void
    {
        $html = $this->renderer->render(CertificateRenderContext::create(
            [],
            ['body' => '<p>{learner_name} finished {course_title} on {completed_at}</p>'],
            [
                'learner_name' => 'Asha Learner',
                'course_title' => 'Advanced Weaving',
                'completed_at' => '2026-01-15',
            ],
        ));

        self::assertSame('<p>Asha Learner finished Advanced Weaving on 2026-01-15</p>', $html);
    }

    public function testEscapesHostileContextValues(): void
    {
        $html = $this->renderer->render(CertificateRenderContext::create(
            [],
            ['body' => '<h1>{learner_name}</h1>'],
            ['learner_name' => "Mallory <script>alert('x')</script> & Co \"quoted\""],
        ));

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&amp; Co', $html);
        self::assertStringContainsString('&quot;quoted&quot;', $html);
        self::assertStringContainsString('&#039;x&#039;', $html);
    }

    public function testLeavesUnknownPlaceholdersIntact(): void
    {
        $html = $this->renderer->render(CertificateRenderContext::create(
            [],
            ['body' => '{learner_name} earned {badge_name}'],
            ['learner_name' => 'Asha'],
        ));

        self::assertSame('Asha earned {badge_name}', $html);
    }

    public function testReplacesRepeatedPlaceholdersEverywhere(): void
    {
        $html = $this->renderer->render(CertificateRenderContext::create(
            [],
            ['body' => '{verification_code} / {verification_code}'],
            ['verification_code' => 'ABC123'],
        ));

        self::assertSame('ABC123 / ABC123', $html);
    }

    public function testLeavesTokenIntactForNonScalarContextValue(): void
    {
        $html = $this->renderer->render(CertificateRenderContext::create(
            [],
            ['body' => 'Value: {metadata}'],
            ['metadata' => ['nested' => 'array']],
        ));

        self::assertSame('Value: {metadata}', $html);
    }

    public function testRendersEmptyStringWhenTemplateBodyIsMissing(): void
    {
        self::assertSame('', $this->renderer->render(CertificateRenderContext::create([], [], ['learner_name' => 'Asha'])));
    }
}
