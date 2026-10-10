<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseTitles;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusEntry;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;

/**
 * The record and the context one credential build is handed, in the one place
 * the freeze and the read both use.
 *
 * It reads the frozen snapshot first and live state only where the snapshot
 * has nothing to say, which is the same rule the renderer follows. A course
 * renamed in 2028 must not change the name inside a credential issued in
 * 2026: the rendered artefact was already protected by the template snapshot
 * and the credential was not, which is the design's headline failure
 * reproduced one layer up.
 *
 * A row issued before the freeze existed has no snapshot, so it falls back to
 * live state and the caller reports that it did.
 */
final class CredentialAssembly
{
    public const SETTING_ISSUER = 'certificates.issuer';

    public function __construct(
        private readonly CourseTitles $courseTitles,
        private readonly ClockPort $clock,
        private readonly ?CertificateCreditRepository $credits = null,
        private readonly ?CredentialStatusRepository $statusLists = null,
        private readonly ?CertificateTemplateRepository $templates = null,
        private readonly ?Settings $settings = null,
        private readonly string $credentialBaseUrl = '',
        private readonly string $verificationBaseUrl = '',
        private readonly string $statusListBaseUrl = '',
        /**
         * What the frozen elements declared they mirror in the credential.
         * Absent, the document is whatever the format builds, which is what
         * every build did before the capability had a call site.
         */
        private readonly ?CredentialElementClaims $elementClaims = null,
        /**
         * Who issued this. Absent, the raw settings record answers and an
         * unconfigured site emits an empty issuer, which is what every
         * shipped site did.
         */
        private readonly ?IssuerIdentity $issuer = null,
    ) {
    }

    public function recordFor(Certificate $certificate): IssuanceRecord
    {
        return $certificate->toIssuanceRecord(
            $this->achievementTypeOf($certificate),
            $this->credits?->forCertificate($certificate->id) ?? [],
        );
    }

    public function contextFor(Certificate $certificate): CredentialContext
    {
        $frozen = $certificate->contextSnapshot;
        $achievementName = TemplateSnapshot::tokensOf($frozen)['course.title']
            ?? $this->courseTitles->titleOf($certificate->courseId);
        $now = $this->clock->now();

        return CredentialContext::create(
            $now,
            $this->issuerFor($certificate),
            $this->credentialBaseUrl,
            $this->verificationBaseUrl,
            $this->statusListBaseUrl,
            $achievementName,
            // Achievement.description is a required member of the published
            // Open Badges 3.0 schema. An empty string satisfies the schema
            // and says nothing, and a displayer renders a blank line, so the
            // course's own description answers where there is one and the
            // achievement name answers where there is not.
            $this->descriptionFor($certificate, $achievementName),
            null,
            null,
            $this->statusEntriesFor($certificate),
            TemplateSnapshot::stringOf($frozen, 'locale', 'en-GB'),
            $this->elementClaims?->forCertificate($certificate, $now) ?? [],
        );
    }

    /**
     * The issuer as configured now, for the freeze and for a template that
     * carried none.
     *
     * It goes through IssuerIdentity so the id and the name are never empty:
     * both are required members of the Open Badges 3.0 Profile, and a
     * credential emitting "" for either fails the schema it claims to be one
     * signature away from. A site that has written nothing still gets the
     * host facts the adapter handed the kernel.
     *
     * @return array<string, string>
     */
    public function issuerSetting(): array
    {
        if ($this->issuer !== null) {
            return $this->issuer->asMap();
        }

        $issuer = $this->settings?->getArray(self::SETTING_ISSUER, []) ?? [];
        $strings = [];

        foreach ($issuer as $key => $value) {
            if (\is_string($key) && \is_string($value) && $value !== '') {
                $strings[$key] = $value;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    private function issuerFor(Certificate $certificate): array
    {
        $frozen = TemplateSnapshot::issuerOf($certificate->contextSnapshot);

        return $frozen === [] ? $this->issuerSetting() : $frozen;
    }

    private function achievementTypeOf(Certificate $certificate): string
    {
        $frozen = TemplateSnapshot::stringOf($certificate->templateSnapshot, 'achievement_type', '');

        if ($frozen !== '') {
            return $frozen;
        }

        $template = $this->templates?->findById($certificate->templateId);
        $declared = $template['achievement_type'] ?? null;

        return \is_string($declared) && $declared !== '' ? $declared : 'CertificateOfCompletion';
    }

    private function descriptionFor(Certificate $certificate, ?string $achievementName): ?string
    {
        $summary = $this->courseTitles->summaryOf($certificate->courseId);

        return $summary ?? $achievementName;
    }

    /**
     * @return list<array{purpose: string, listUuid: string, index: int}>
     */
    private function statusEntriesFor(Certificate $certificate): array
    {
        $entries = [];

        foreach ($this->statusLists?->entriesFor(IssuanceRecord::SUBJECT_CERTIFICATE, $certificate->id) ?? [] as $e) {
            /** @var CredentialStatusEntry $e */
            $entries[] = $e->toCredentialEntry();
        }

        return $entries;
    }
}
