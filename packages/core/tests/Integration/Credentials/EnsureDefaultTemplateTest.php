<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\EnsureDefaultTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\RenderCertificate;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Certificate\HtmlCertificateRenderer;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;

final class EnsureDefaultTemplateTest extends CredentialsTestCase
{
    private EnsureDefaultTemplate $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCase = $this->ensureDefaultTemplateUseCase();
    }

    public function testSeedsPublishedDefaultTemplateWhenMissing(): void
    {
        $templateId = $this->useCase->execute();

        $template = self::row($this->templates->findById($templateId));

        self::assertSame('Default certificate', $template['name']);
        self::assertSame('html', $template['renderer']);
        self::assertSame(1, (int) $template['is_default']);
        self::assertSame('published', $template['state']);
        self::assertSame('2026-01-15 10:00:00', $template['created_at']);
        self::assertSame('2026-01-15 10:00:00', $template['updated_at']);

        $body = (string) $template['body'];

        foreach (
            [
                '{learner_name}',
                '{course_title}',
                '{completed_at}',
                '{verification_code}',
                '{verification_url}',
            ] as $placeholder
        ) {
            self::assertStringContainsString($placeholder, $body);
        }
    }

    public function testTheSeededTemplateLeavesNoPlaceholderOnASiteWithNoLogo(): void
    {
        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner', 'asha@example.test');
        $this->useCase->execute();

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $renderers = new CertificateRendererRegistry();
        $renderers->register(new HtmlCertificateRenderer($this->ports->translator));

        $html = (new RenderCertificate(
            $this->certificates,
            $this->templates,
            $renderers,
            $this->courseTitles,
            $this->ports->users,
            $this->guard,
            $this->ports->translator,
        ))->execute(Principal::user(5), $certificate->id, 'https://example.test/verify');

        // {site.logo_url} resolves to nothing on a site that has configured
        // no logo, which is every fresh install. Left intact inside an img
        // src it is a broken image with a literal placeholder for a source,
        // on an evidentiary artefact.
        self::assertStringNotContainsString('{site.logo_url}', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testSeededNameAndBodyCopyComeFromTheCatalogue(): void
    {
        $templateId = $this->useCase->execute();

        $template = self::row($this->templates->findById($templateId));
        $body = (string) $template['body'];

        self::assertSame('Default certificate', $template['name']);
        self::assertStringContainsString('This certificate is awarded to', $body);
        self::assertStringContainsString('for successfully completing', $body);
        self::assertStringContainsString(
            'Verify this certificate with code {verification_code} at {verification_url}',
            $body,
        );
    }

    public function testSeedingTranslatesThroughTheTranslatorPort(): void
    {
        $templateId = (new EnsureDefaultTemplate($this->templates, $this->ports->translator))->execute();

        $template = self::row($this->templates->findById($templateId));

        self::assertSame('vidyalms.certificate.default_template_name', $template['name']);
        self::assertStringContainsString('vidyalms.certificate.awarded_to', (string) $template['body']);
        self::assertStringContainsString('vidyalms.certificate.for_completing', (string) $template['body']);
        self::assertStringContainsString('vidyalms.certificate.verify_hint', (string) $template['body']);
    }

    public function testRunningTwiceCreatesNothingNew(): void
    {
        $first = $this->useCase->execute();
        $second = $this->useCase->execute();

        self::assertSame($first, $second);
        self::assertSame(1, $this->ports->database->count(Query::from('certificate_templates')));
    }

    public function testTranslatedLabelsAreTextAndDoNotChangeTemplateMarkup(): void
    {
        $translator = new class implements \Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort {
            public function translate(string $key, array $parameters = []): string
            {
                return str_ends_with($key, '.logo_alt') ? 'Academy "A" & friends' : $key;
            }
        };
        $id = (new EnsureDefaultTemplate($this->templates, $translator))->execute();
        $template = self::row($this->templates->findById($id));
        self::assertStringContainsString('alt="Academy &quot;A&quot; &amp; friends"', (string) $template['body']);
        self::assertStringContainsString('{learner_name}', (string) $template['body']);
    }

    public function testUnpublishedDefaultDoesNotCountAsSeeded(): void
    {
        $draftId = $this->templates->insert('Old draft', 'html', '{learner_name}', true, 'draft');

        $seededId = $this->useCase->execute();

        self::assertNotSame($draftId, $seededId);
        self::assertSame(2, $this->ports->database->count(Query::from('certificate_templates')));

        $published = self::row($this->templates->findPublishedDefault());
        self::assertSame($seededId, (int) $published['id']);
    }
}
