<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseTitles;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RendersToBinary;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;

/**
 * Renders a certificate for its owner or a manager, from what the issuance
 * froze.
 *
 * Rendering reads the snapshot and never the live template. A row issued
 * before the snapshot existed falls back to the live template, and the answer
 * says that it did, so an administrator can see which certificates are
 * unfrozen instead of discovering it later.
 *
 * The renderer lookup is guarded. It was an unguarded get(), so a template
 * naming a renderer whose package had been removed threw an unknown provider
 * exception straight out of the render path; a removed extension has to
 * degrade to a placeholder naming what is missing, which is the published
 * miss direction for a renderer and what ADR 0002 requires.
 *
 * The core knows no URLs, so the caller supplies the verification base and
 * the code is appended as a path segment.
 */
final class RenderCertificate
{
    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateRendererRegistry $renderers,
        private readonly CourseTitles $courseTitles,
        private readonly UsersPort $users,
        private readonly AccessGuard $guard,
        private readonly TranslatorPort $translator,
        /**
         * The locale travels on the render context, so a renderer that has to
         * format a date has the answer rather than guessing. A frozen
         * snapshot carries its own, which wins: the certificate was issued in
         * one language and reprinting it in another would change what the
         * holder was given.
         */
        private readonly LocalePort $locale = new FixedLocale(),
        private readonly ?CertificateTokens $tokens = null,
        private readonly string $assetBaseUrl = '',
        /**
         * The clock a status is judged against. Absent, the row's own issue
         * instant stands in, which never refuses: a harness with no clock
         * behaves exactly as this did before the refusal existed.
         */
        private readonly ?ClockPort $clock = null,
    ) {
    }

    public function execute(Principal $principal, int $certificateId, string $verificationBaseUrl): string
    {
        return $this->renderArtefact($principal, $certificateId, $verificationBaseUrl)->bytes;
    }

    /**
     * The entry point a stored artefact comes from.
     *
     * The capability that lets a renderer produce bytes needed a call site and
     * had none: the task wrote whatever the base method returned to a
     * hard-coded .html path, so a renderer implementing the binary capability
     * would never have had it called and its bytes would have landed on an
     * HTML path with the wrong media type.
     */
    public function renderArtefact(
        Principal $principal,
        int $certificateId,
        string $verificationBaseUrl,
    ): RenderedArtefact {
        $certificate = $this->certificates->findById($certificateId);

        if ($certificate === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate', $certificateId);
        }

        $ownsCertificate = !$principal->isGuest() && $certificate->belongsTo($principal->userId);

        if (!$ownsCertificate && !$this->guard->can($principal, Capability::MANAGE)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw NotAuthorizedException::forCapability(Capability::MANAGE);
        }

        return $this->render($certificate, $verificationBaseUrl);
    }

    /**
     * The unguarded path, for the queued render the issuance already
     * authorised when it created the row. It is a separate entry point rather
     * than a nullable principal so nothing can reach it from a request by
     * accident, which is the precedent the reissue fan-out already sets.
     */
    public function forScheduledTask(int $certificateId, string $verificationBaseUrl): RenderedArtefact
    {
        $certificate = $this->certificates->findById($certificateId);

        if ($certificate === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate', $certificateId);
        }

        return $this->render($certificate, $verificationBaseUrl);
    }

    private function render(Certificate $certificate, string $verificationBaseUrl): RenderedArtefact
    {
        // A withdrawn or replaced credential is not drawn again on demand.
        // The revoke ceremony's stated consequence is that the verification
        // page reports it revoked; producing a fresh artefact for it goes
        // well beyond the copy already in somebody's downloads folder.
        $status = $certificate->statusAt($this->clock?->now() ?? $certificate->issuedAt);

        if ($status === CredentialStatus::Revoked || $status === CredentialStatus::Superseded) {
            throw ConflictException::with(
                'This certificate is no longer in force.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                array_filter([
                    // RFC 9457 reserves "status" for the HTTP status code.
                    'credential_status' => $status->value,
                    'superseded_by_id' => $certificate->supersededById,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        $context = $this->contextFor($certificate, $verificationBaseUrl);
        $rendererKey = \is_string($context->template['renderer'] ?? null)
            ? $context->template['renderer']
            : 'html';

        if (!$this->renderers->has($rendererKey)) {
            return RenderedArtefact::html(
                $this->missingRendererDocument($rendererKey, $context),
                $this->filename($certificate, 'html'),
            );
        }

        $renderer = $this->renderers->get($rendererKey);

        if ($renderer instanceof RendersToBinary) {
            return $renderer->renderBinary($context);
        }

        return RenderedArtefact::html($renderer->render($context), $this->filename($certificate, 'html'));
    }

    /**
     * Whether this certificate rendered from what it froze. The response says
     * so, because an administrator has to be able to find the unfrozen rows.
     */
    public function rendersFromSnapshot(Certificate $certificate): bool
    {
        return $certificate->hasSnapshot();
    }

    private function contextFor(Certificate $certificate, string $verificationBaseUrl): CertificateRenderContext
    {
        $verificationUrl = $verificationBaseUrl === ''
            ? ''
            : \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append($verificationBaseUrl, $certificate->code);

        if ($certificate->templateSnapshot !== null) {
            $template = TemplateSnapshot::templateOf($certificate->templateSnapshot);
            $tokens = TemplateSnapshot::tokensOf($certificate->contextSnapshot);

            return CertificateRenderContext::create(
                $this->certificateRow($certificate),
                $template,
                $tokens,
                TemplateSnapshot::stringOf($certificate->contextSnapshot, 'locale', $this->locale->current()),
                TemplateSnapshot::stringOf($certificate->contextSnapshot, 'timezone', 'UTC'),
                TemplateSnapshot::pagesOf($certificate->templateSnapshot),
                TemplateSnapshot::stringOf($certificate->contextSnapshot, 'verification_url', $verificationUrl),
                CertificateRenderContext::PURPOSE_ISSUE,
                true,
                $this->assetBaseUrl,
                $certificate->issuedAt,
            );
        }

        // The fallback, and only for rows that predate the snapshot. It reads
        // the live template, which is exactly the behaviour this design
        // exists to end, so it is reported rather than hidden.
        $template = $this->templates->findById($certificate->templateId);

        if ($template === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Certificate template', $certificate->templateId);
        }

        $locale = $this->locale->current();

        return CertificateRenderContext::create(
            $this->certificateRow($certificate),
            $template,
            $this->liveTokens($certificate, $verificationUrl, $locale),
            $locale,
            'UTC',
            $this->templates->pagesOf($certificate->templateId),
            $verificationUrl,
            CertificateRenderContext::PURPOSE_ISSUE,
            false,
            $this->assetBaseUrl,
            $certificate->issuedAt,
        );
    }

    /**
     * @return array<string, string>
     */
    private function liveTokens(Certificate $certificate, string $verificationUrl, string $locale): array
    {
        $user = $this->users->findById($certificate->userId);
        $learnerName = $user === null ? '' : $user->name;
        $courseTitle = $this->courseTitles->titleOf($certificate->courseId) ?? '';

        $legacy = [
            // The five keys the pre-registry render assembled by hand. They
            // stay because a template seeded before the token layer names
            // them, and the html renderer leaves what it cannot resolve
            // intact, so keeping them is what makes this backward compatible
            // rather than a migration.
            'learner_name' => $learnerName,
            'course_title' => $courseTitle,
            'completed_at' => LocalisedDate::long($certificate->completedAt ?? $certificate->issuedAt, $locale),
            'verification_code' => $certificate->code,
            'verification_url' => $verificationUrl,
        ];

        if ($this->tokens === null) {
            return $legacy;
        }

        $resolved = $this->tokens->resolveForIssuance(IssuanceParameterContext::create(
            $certificate->code,
            $certificate->uuid,
            $certificate->issuedAt,
            $certificate->completedAt,
            $certificate->expiresAt,
            $learnerName,
            $certificate->userId,
            $certificate->courseId,
            $courseTitle,
            $certificate->enrolmentId,
            $certificate->id,
            $certificate->credits,
            $locale,
        ));

        if ($verificationUrl !== '') {
            $resolved['certificate.verification_url'] = $verificationUrl;
        }

        return $resolved + $legacy;
    }

    /**
     * @return array<string, mixed>
     */
    private function certificateRow(Certificate $certificate): array
    {
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
            'valid_from' => $certificate->validFrom?->format('Y-m-d H:i:s'),
            'expires_at' => $certificate->expiresAt?->format('Y-m-d H:i:s'),
            'revoked_at' => $certificate->revokedAt?->format('Y-m-d H:i:s'),
            'status' => $certificate->storedStatus->value,
            'artefact_path' => $certificate->artefactPath,
        ];
    }

    private function missingRendererDocument(string $rendererKey, CertificateRenderContext $context): string
    {
        $message = $this->translator->translate(
            'vidyalms.certificate_renderer.missing',
            ['renderer' => $rendererKey],
        );

        return '<div class="vy-certificate vy-certificate-missing-renderer" lang="'
            . htmlspecialchars($context->locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }

    private function filename(Certificate $certificate, string $extension): string
    {
        return 'certificate-' . $certificate->code . '.' . $extension;
    }
}
