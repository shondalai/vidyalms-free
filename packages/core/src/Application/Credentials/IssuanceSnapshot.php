<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseTitles;
use Shondalai\VidyaLMS\Core\Domain\Credentials\NewCertificate;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;
use Shondalai\VidyaLMS\Core\Registry\Credit\ComputesFromParams;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeRegistry;

/**
 * What one issuance freezes, in one collaborator rather than inside the use
 * case.
 *
 * It exists so the issue path and the reissue path cannot disagree about what
 * a snapshot contains: two hand-built freezes drift, and a reissue that
 * froze less than the original would be an evidentiary defect nobody notices
 * until an audit.
 *
 * It is not a service object in disguise. It has one job, it holds no state
 * between calls, and the use cases still own every rule: this decides what
 * goes in the box, and they decide when the box is sealed.
 */
final class IssuanceSnapshot
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTokens $tokens,
        private readonly UsersPort $users,
        private readonly CourseTitles $courseTitles,
        private readonly CreditSchemeRegistry $creditSchemes,
        private readonly RandomPort $random,
        private readonly string $verificationBaseUrl = '',
        private readonly LocalePort $locale = new FixedLocale(),
        private readonly string $timezone = 'UTC',
        /**
         * The issuer, frozen with everything else. It is inside the
         * credential document a wallet keeps, so a settings edit years later
         * must not change what an already-issued credential says about who
         * issued it.
         */
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * @param array<string, mixed>    $template
     * @param list<CertificateCredit> $credits
     */
    public function draftFor(
        int $userId,
        int $courseId,
        int $enrolmentId,
        array $template,
        string $code,
        string $uuid,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $completedAt,
        ?\DateTimeImmutable $expiresAt,
        array $credits,
        bool $published,
        ?string $issuerEarnerId = null,
        ?Certificate $supersedes = null,
    ): NewCertificate {
        $templateId = (int) ($template['id'] ?? 0);
        $pages = array_key_exists('pages', $template)
            ? TemplateSnapshot::pagesOf(\Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape::wrap($template, 1))
            : ($templateId > 0 ? $this->templates->pagesOf($templateId) : []);
        $locale = \is_string($template['locale'] ?? null) && $template['locale'] !== ''
            ? $template['locale']
            : $this->locale->current();

        $learnerName = $this->users->findById($userId)?->name;
        $verificationUrl = $this->verificationUrl($code);

        $tokens = $this->tokens->resolveForIssuance(IssuanceParameterContext::create(
            $code,
            $uuid,
            $issuedAt,
            $completedAt,
            $expiresAt,
            $learnerName,
            $userId,
            $courseId,
            $this->courseTitles->titleOf($courseId),
            $enrolmentId,
            $supersedes?->id,
            $credits,
            $locale,
            $this->timezone,
        ));

        // Composed here rather than left to a resolver that may not have a
        // base URL configured, because the frozen verification URL is what a
        // printed certificate carries for the life of the credential.
        if ($verificationUrl !== '') {
            $tokens['certificate.verification_url'] = $verificationUrl;
        }

        $tokens += $this->legacyAliases(
            $tokens,
            $learnerName,
            $this->courseTitles->titleOf($courseId),
            $code,
            $verificationUrl,
        );

        // The identity pair is generated once and carried forward by every
        // reissue: regenerating the salt changes the hash, and a relying
        // party that pinned the old one can no longer match the holder.
        [$identityHash, $identitySalt] = $this->recipientIdentity($userId);

        $renderer = \is_string($template['renderer'] ?? null) ? $template['renderer'] : 'html';

        return $supersedes === null
            ? new NewCertificate(
                $userId,
                $courseId,
                $enrolmentId,
                $templateId,
                $code,
                $uuid,
                $issuedAt,
                $completedAt,
                null,
                $expiresAt,
                (int) ($template['revision'] ?? 1),
                TemplateSnapshot::freezeTemplate($template, $pages),
                TemplateSnapshot::freezeContext(
                    $tokens,
                    $locale,
                    $this->timezone,
                    $verificationUrl,
                    $this->issuer(),
                ),
                $renderer,
                null,
                $published,
                $identityHash,
                $identitySalt,
                null,
                $issuerEarnerId,
                null,
                null,
                $credits,
            )
            : NewCertificate::supersedingReissue(
                $supersedes,
                $code,
                $uuid,
                $issuedAt,
                $templateId,
                (int) ($template['revision'] ?? 1),
                TemplateSnapshot::freezeTemplate($template, $pages),
                TemplateSnapshot::freezeContext(
                    $tokens,
                    $locale,
                    $this->timezone,
                    $verificationUrl,
                    $this->issuer(),
                ),
                $renderer,
                $expiresAt,
                null,
                $credits,
            );
    }

    /**
     * The credit row one authored course credit becomes, with the scheme's
     * own arithmetic applied.
     *
     * A scheme that is not registered is not an error: the row is stored and
     * rendered as a raw amount and unit with no validation and no export,
     * which is the published miss direction. A compliance figure that
     * silently disappears is worse than one that is visibly unvalidated.
     */
    public function creditFor(CourseCredit $authored): CertificateCredit
    {
        $minutes = $authored->contactMinutes();
        $stated = $authored->statedAmount();

        if (!$this->creditSchemes->has($authored->scheme)) {
            return new CertificateCredit(
                $authored->scheme,
                $stated ?? '0.00',
                'unknown',
                $authored->fieldOfStudy(),
                $authored->awardingBodyId(),
                $minutes,
                $authored->params,
                $authored->paramsVersion,
            );
        }

        $scheme = $this->creditSchemes->get($authored->scheme);
        // A scheme whose amount is not a pure function of minutes computes it
        // from the parameters the author recorded. NASBA's fifth increments
        // and ACCME's authored MOC points are both unexpressible through
        // amountFor(), and the params exist for exactly that arithmetic.
        // Absent, the capability means amountFor() with the params ignored.
        $amount = $stated ?? ($scheme instanceof ComputesFromParams
            ? $scheme->amountFromParams($this->creditParams($authored, $minutes))
            : ($minutes === null ? '0.00' : $scheme->amountFor($minutes)));

        return new CertificateCredit(
            $authored->scheme,
            $amount,
            $scheme->unit(),
            $authored->fieldOfStudy(),
            $authored->awardingBodyId(),
            $minutes,
            $authored->params,
            $authored->paramsVersion,
        );
    }

    /**
     * The authored document plus the shared fact, which is the bag a scheme
     * that computes from its own parameters is handed.
     *
     * @return array<string, mixed>
     */
    private function creditParams(CourseCredit $authored, ?int $minutes): array
    {
        $params = $authored->params;
        $params['contact_minutes'] = $minutes ?? 0;

        return $params;
    }

    /**
     * The issuer as configured now, frozen onto the row with everything else.
     *
     * @return array<string, string>
     */
    private function issuer(): array
    {
        $issuer = $this->settings?->getArray(CredentialAssembly::SETTING_ISSUER, []) ?? [];
        $strings = [];

        foreach ($issuer as $key => $value) {
            if (\is_string($key) && \is_string($value) && $value !== '') {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }

    /**
     * The five undotted placeholder names the pre-registry render assembled
     * by hand, frozen beside the dotted ones.
     *
     * They are here because the seeded template body names them, and because
     * every template a site authored before the token registry existed names
     * them too. Dropping them would turn one release into a migration:
     * adding placeholders is backward compatible precisely because the
     * renderers leave what they cannot resolve intact, and that only helps if
     * what was already there still resolves.
     *
     * @param array<string, string> $tokens
     *
     * @return array<string, string>
     */
    private function legacyAliases(
        array $tokens,
        ?string $learnerName,
        ?string $courseTitle,
        string $code,
        string $verificationUrl,
    ): array {
        $aliases = [
            'learner_name' => $learnerName ?? ($tokens['user.full_name'] ?? ''),
            'course_title' => $courseTitle ?? ($tokens['course.title'] ?? ''),
            'completed_at' => $tokens['certificate.completed_at'] ?? '',
            'verification_code' => $code,
            'verification_url' => $verificationUrl,
        ];

        return array_filter($aliases, static fn (string $value): bool => $value !== '');
    }

    public function verificationUrl(string $code): string
    {
        return $this->verificationBaseUrl === ''
            ? ''
            : \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append($this->verificationBaseUrl, $code);
    }

    /**
     * Open Badges 3.0's IdentityObject: sha256 over the identifier plus a
     * per-row salt, so a publicly shareable credential proves it was issued
     * to the holder of an address without publishing the address.
     *
     * A learner with no address gets no identity object rather than a hash of
     * an empty string, which would be the same digest for everybody.
     *
     * @return array{?string, ?string}
     */
    private function recipientIdentity(int $userId): array
    {
        $email = $this->users->findById($userId)?->email;

        if ($email === null || $email === '') {
            return [null, null];
        }

        $salt = $this->random->uuid();

        return ['sha256$' . hash('sha256', strtolower($email) . $salt), $salt];
    }
}
