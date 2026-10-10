<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseTitles;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RendersToBinary;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;

/**
 * Renders a template without issuing anything.
 *
 * The renderer runs with the purpose "preview" rather than "issue", so a
 * renderer can watermark and an element can tell that the values it sees are
 * not a credential's. Nothing is written: no certificate row, no code, no
 * status list index, no artefact in storage. A preview that writes anything
 * is a preview that has issued a credential.
 *
 * Tokens resolve against whatever the caller supplies. Named a certificate,
 * the preview answers what that credential would look like if it were
 * reissued from the template as it stands now, and the certificate itself
 * keeps rendering from its own snapshot. Named a learner and a course, the
 * real values are used. Named neither, the preview resolves against the
 * person asking, which is a real name the registry can answer for rather than
 * a sample table that would be exactly the hard-coded token list this design
 * deletes.
 *
 * An unresolved token is left intact in the output rather than blanked, and
 * is listed, so a template written for a richer renderer degrades visibly
 * instead of silently losing a line.
 *
 * Requires manage.
 */
final class PreviewCertificateTemplate
{
    /** The code a preview prints, so nothing that reaches paper looks issued. */
    public const SAMPLE_CODE = 'PREVIEW-0000';

    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateChecks $checks,
        private readonly CertificateRendererRegistry $renderers,
        private readonly CertificateTokens $tokens,
        private readonly CertificateRepository $certificates,
        private readonly CourseTitles $courseTitles,
        private readonly UsersPort $users,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly TranslatorPort $translator,
        private readonly LocalePort $locale = new FixedLocale(),
        private readonly string $verificationBaseUrl = '',
        private readonly string $assetBaseUrl = '',
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function execute(Principal $principal, int $templateId, array $body): CertificatePreviewResult
    {
        $this->guard->require($principal, Capability::MANAGE);

        $template = $this->aggregate->require($templateId);
        $fields = new CertificateTemplateFields($body);
        $userId = $fields->nullableInt('user_id', 1);
        $courseId = $fields->nullableInt('course_id', 1);
        $certificateId = $fields->nullableInt('certificate_id', 1);
        $fields->check();

        $certificate = $certificateId === null ? null : $this->certificates->findById($certificateId);

        if ($certificateId !== null && $certificate === null) {
            // A preview that quietly ignored the certificate it was asked
            // about would answer a different question with a 200, and the
            // author would compare the wrong document.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate', $certificateId);
        }
        $pages = $this->templates->pagesOf($templateId);
        $locale = \is_string($template['locale'] ?? null) && $template['locale'] !== ''
            ? $template['locale']
            : $this->locale->current();

        $verificationUrl = $this->verificationUrlFor($certificate);
        $context = CertificateRenderContext::create(
            $this->certificateRow($certificate),
            $template,
            $this->tokensFor($principal, $certificate, $userId, $courseId, $locale, $verificationUrl),
            $locale,
            'UTC',
            $pages,
            $verificationUrl,
            CertificateRenderContext::PURPOSE_PREVIEW,
            false,
            $this->assetBaseUrl,
            $this->clock->now(),
        );

        $artefact = $this->render($template, $context);
        $isHtml = $artefact->mediaType === 'text/html';

        return new CertificatePreviewResult(
            $artefact->mediaType,
            $isHtml ? $artefact->bytes : null,
            $isHtml ? null : 'data:' . $artefact->mediaType . ';base64,' . base64_encode($artefact->bytes),
            $this->checks->run($template, $pages),
            $this->unresolvedTokens($template, $pages, $locale, $isHtml ? $artefact->bytes : ''),
        );
    }

    /**
     * @param array<string, mixed> $template
     */
    private function render(array $template, CertificateRenderContext $context): RenderedArtefact
    {
        $rendererKey = \is_string($template['renderer'] ?? null) ? $template['renderer'] : 'html';

        if (!$this->renderers->has($rendererKey)) {
            // The published miss direction for a renderer is visible: an
            // author previewing a template whose package was removed sees
            // which package is missing rather than an empty page. The issue
            // path says the same thing in the same words, and neither may
            // throw an unknown provider exception at a request.
            return RenderedArtefact::html(
                '<div class="vy-certificate vy-certificate-missing-renderer" lang="'
                    . htmlspecialchars($context->locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                    . htmlspecialchars(
                        $this->translator->translate(
                            'vidyalms.certificate_renderer.missing',
                            ['renderer' => $rendererKey],
                        ),
                        ENT_QUOTES | ENT_SUBSTITUTE,
                        'UTF-8',
                    )
                    . '</div>',
                'certificate-preview.html',
            );
        }

        $renderer = $this->renderers->get($rendererKey);

        if ($renderer instanceof RendersToBinary) {
            return $renderer->renderBinary($context);
        }

        return RenderedArtefact::html($renderer->render($context), 'certificate-preview.html');
    }

    /**
     * @return array<string, string>
     */
    private function tokensFor(
        Principal $principal,
        ?Certificate $certificate,
        ?int $userId,
        ?int $courseId,
        string $locale,
        string $verificationUrl,
    ): array {
        $subjectId = $certificate->userId
            ?? $userId
            ?? ($principal->isGuest() ? null : $principal->userId);
        $subjectCourseId = $certificate->courseId ?? $courseId;
        $user = $subjectId === null ? null : $this->users->findById($subjectId);
        $now = $this->clock->now();
        $courseTitle = $subjectCourseId === null ? null : $this->courseTitles->titleOf($subjectCourseId);
        $completedAt = $certificate->completedAt ?? $now;

        $resolved = $this->tokens->resolveForIssuance(IssuanceParameterContext::create(
            $certificate->code ?? self::SAMPLE_CODE,
            $certificate?->uuid,
            $certificate->issuedAt ?? $now,
            $completedAt,
            $certificate?->expiresAt,
            $user?->name,
            $subjectId,
            $subjectCourseId,
            $courseTitle,
            $certificate?->enrolmentId,
            $certificate?->id,
            $certificate->credits ?? [],
            $locale,
        ));

        // The five bare keys a template seeded before the token layer names.
        // The issue path carries them for the same reason, so a preview that
        // dropped them would draw the seeded free template as raw
        // placeholders and report a defect the issued certificate does not
        // have. An empty one is left out rather than substituted with
        // nothing, because the miss direction for a token is to leave the
        // placeholder intact.
        $legacy = array_filter([
            'learner_name' => $user->name ?? '',
            'course_title' => $courseTitle ?? '',
            'completed_at' => LocalisedDate::long($completedAt, $locale),
            'verification_code' => $certificate->code ?? self::SAMPLE_CODE,
            'verification_url' => $verificationUrl,
        ], static fn (string $value): bool => $value !== '');

        return $resolved + $legacy;
    }

    /**
     * Every placeholder the template names that no registered resolver owns.
     * They are left intact in the output as well, so the list explains what
     * an author is looking at rather than replacing it.
     *
     * @param array<string, mixed>          $template
     * @param list<CertificateTemplatePage> $pages
     *
     * @return list<string>
     */
    private function unresolvedTokens(array $template, array $pages, string $locale, string $rendered = ''): array
    {
        $context = ElementValidationContext::create(
            'template',
            (int) ($template['id'] ?? 0),
            null,
            $this->tokens->availableTokens(),
            $locale,
        );

        $unresolved = $context->unresolvedTokens(\is_string($template['body'] ?? null) ? $template['body'] : '');

        foreach ($pages as $page) {
            foreach ($page->elements as $element) {
                foreach ($context->unresolvedTokens(self::textOf($element)) as $token) {
                    $unresolved[] = $token;
                }
            }
        }

        // A registered token that resolved to nothing survives into the
        // output and was reported by nothing: the list above answers "does
        // this build know the prefix", which is a different question from
        // "did this site answer it". So the rendered bytes are read back and
        // every placeholder still standing in them is named. This is the one
        // check that sees the certificate an author would actually receive.
        foreach (self::placeholdersIn($rendered) as $token) {
            $unresolved[] = $token;
        }

        sort($unresolved);

        return array_values(array_unique($unresolved));
    }

    /**
     * @return list<string>
     */
    private static function placeholdersIn(string $rendered): array
    {
        if ($rendered === '' || preg_match_all('/\{([a-z0-9_]+\.[a-z0-9_]+)\}/', $rendered, $matches) === false) {
            return [];
        }

        return $matches[1];
    }

    /**
     * Every string an element payload holds, flattened, because a token can
     * sit in any field a provider declares and the core knows none of them.
     */
    private static function textOf(CertificateTemplateElement $element): string
    {
        $text = '';
        $payload = $element->data;

        array_walk_recursive(
            $payload,
            static function (mixed $value) use (&$text): void {
                if (\is_string($value)) {
                    $text .= ' ' . $value;
                }
            },
        );

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function certificateRow(?Certificate $certificate): array
    {
        if ($certificate === null) {
            return ['id' => 0, 'code' => self::SAMPLE_CODE, 'status' => 'active'];
        }

        return [
            'id' => $certificate->id,
            'user_id' => $certificate->userId,
            'course_id' => $certificate->courseId,
            'enrolment_id' => $certificate->enrolmentId,
            'template_id' => $certificate->templateId,
            'code' => $certificate->code,
            'uuid' => $certificate->uuid,
            'issued_at' => $certificate->issuedAt->format('Y-m-d H:i:s'),
            'completed_at' => $certificate->completedAt?->format('Y-m-d H:i:s'),
            'expires_at' => $certificate->expiresAt?->format('Y-m-d H:i:s'),
            'status' => $certificate->storedStatus->value,
        ];
    }

    private function verificationUrlFor(?Certificate $certificate): string
    {
        if ($this->verificationBaseUrl === '') {
            return '';
        }

        return \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append(
            $this->verificationBaseUrl,
            $certificate->code ?? self::SAMPLE_CODE,
        );
    }
}
