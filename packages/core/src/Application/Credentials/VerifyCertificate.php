<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseTitles;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Provider\Token\CertificateTokenResolver;

/**
 * Public by design: guests may verify, so no capability is checked and an
 * unknown code returns a calm not-found result instead of throwing.
 *
 * That calm answer is about structure, not content. This endpoint is a PII
 * disclosure surface: it returns a person's name against a completion, which
 * is what makes a certificate verifiable and is also personal data. Four
 * things bound it, and none of them is in this class by accident. The route
 * carries the noindex header and the rate limiter, from core middleware
 * rather than from each adapter. The site setting decides whether a learner
 * name is ever published. The per-certificate flag decides it again for this
 * credential. And the payload carries no email address, no user id, no
 * enrolment id and no internal certificate id, ever.
 *
 * Lookup accepts the code with or without separators, because people retype
 * what they see on paper and the display form is grouped.
 */
final class VerifyCertificate
{
    public const SETTING_PUBLISH_LEARNER_NAME = CredentialNamePublication::SITE_SETTING;

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CourseTitles $courseTitles,
        private readonly UsersPort $users,
        private readonly ClockPort $clock,
        private readonly ?Settings $settings = null,
        private readonly ?CertificateCreditRepository $credits = null,
        private readonly string $verificationBaseUrl = '',
        private readonly string $credentialBaseUrl = '',
    ) {
    }

    public function execute(Principal $principal, string $code): CertificateVerification
    {
        $certificate = $this->certificates->findByCode($code)
            ?? $this->certificates->findByCode(CertificateTokenResolver::normalise($code));

        if ($certificate === null) {
            return CertificateVerification::notFound();
        }

        $now = $this->clock->now();
        $status = $certificate->statusAt($now);

        $superseded = $certificate->supersededById === null
            ? null
            : $this->certificates->findById($certificate->supersededById);

        return CertificateVerification::of(
            $status,
            $this->courseTitles->titleOf($certificate->courseId),
            $this->publishedLearnerName($certificate),
            $certificate->issuedAt,
            $certificate->expiresAt,
            $certificate->isRevoked(),
            $certificate->isExpired($now),
            $certificate->revokedAt,
            $certificate->completedAt,
            $certificate->validFrom,
            $this->credits?->forCertificate($certificate->id) ?? [],
            $this->credentialUrl($certificate->uuid),
            $superseded === null ? null : $this->verificationUrl($superseded->code),
        );
    }

    /**
     * Whether the holder's name appears at all is a site decision plus a
     * per-certificate flag that records what that decision was when the
     * credential was issued. That is Credly's accepted-and-public gate
     * expressed for a self-hosted product.
     *
     * The row's flag is authoritative, because a decision taken at issue is
     * the decision the holder was told about, and flipping a site setting
     * afterwards must not retroactively publish a name against a completion
     * that was issued under the opposite rule.
     *
     * A row issued before the flag existed carries no credential uuid, and
     * for those the site setting is the only decision available: reading the
     * defaulted zero instead would silently blank the name on every
     * certificate a site had already issued, which is a data loss dressed up
     * as a privacy improvement.
     *
     * Names are withheld until the site opts in, and holders may opt out.
     */
    private function publishedLearnerName(Certificate $certificate): ?string
    {
        $published = CredentialNamePublication::allowed($this->settings, $certificate->userId)
            && ($certificate->uuid === null || $certificate->published);

        if (!$published) {
            return null;
        }
        if ($certificate->contextSnapshot !== null) {
            $tokens = TemplateSnapshot::tokensOf($certificate->contextSnapshot);
            $name = $tokens['user.full_name'] ?? $tokens['learner_name'] ?? null;
            return \is_string($name) && $name !== '' ? $name : null;
        }

        return $this->users->findById($certificate->userId)?->name;
    }

    private function credentialUrl(?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '' || $this->credentialBaseUrl === '') {
            return null;
        }

        return rtrim($this->credentialBaseUrl, '/') . '/' . rawurlencode($uuid);
    }

    private function verificationUrl(string $code): ?string
    {
        return $this->verificationBaseUrl === ''
            ? null
            : \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append($this->verificationBaseUrl, $code);
    }
}
