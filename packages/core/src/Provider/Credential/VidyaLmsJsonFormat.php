<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Credential;

use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialElementClaims;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;

/**
 * The free build's unsigned credential, and the resolution of the one
 * genuinely open standards question in the area.
 *
 * An unsigned document opening with the W3C credentials context invites a
 * wallet to attempt verification of something that has no securing mechanism
 * and to report a failure that is our fault rather than the wallet's. So this
 * format emits its own namespace as the first context entry, does not carry
 * the VerifiableCredential type, and is documented as the shape a signed
 * credential will take, unsigned.
 *
 * Everything else follows VC 2.0 and Open Badges 3.0 exactly: the field names
 * are issuer, credentialSubject, validFrom, validUntil and credentialStatus,
 * and the class shapes are AchievementCredential, AchievementSubject,
 * Achievement and IdentityObject. The body is one signature away from
 * conformant, and that claim is machine-checked rather than asserted: the
 * conformance test injects the W3C and Open Badges contexts and types and
 * validates the result against the published schema.
 *
 * Recipient identity is a salted digest and never an address, so a publicly
 * shareable credential proves it was issued to the holder of an address
 * without publishing the address.
 */
final class VidyaLmsJsonFormat implements CredentialFormatInterface
{
    public const KEY = 'vidyalms.json';
    public const CONTEXT = 'https://vidyalms.org/ns/credentials/v1';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.credential_format.vidyalms_json');
    }

    public function mimeType(): string
    {
        return 'application/ld+json';
    }

    public function build(IssuanceRecord $record, CredentialContext $context): CredentialArtefact
    {
        $body = $this->body($record, $context);
        // The free unsigned document has no status-list publisher. Its
        // hosted endpoint checks current status before returning the claims.
        // Keep the reusable VC body intact for signed format providers.
        unset($body['credentialStatus']);
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        return CredentialArtefact::of(
            RenderedArtefact::of($json, $this->mimeType(), 'credential-' . $record->code . '.json'),
            self::KEY,
        );
    }

    /**
     * The credential body, exposed so the conformance test can inject the
     * standard contexts and types and validate the same bytes the endpoint
     * serves. A test that built its own body would prove nothing about what
     * ships.
     *
     * @return array<string, mixed>
     */
    public function body(IssuanceRecord $record, CredentialContext $context): array
    {
        $uuid = $record->uuid ?? $record->code;
        $achievementId = $context->credentialUrl($uuid) . '#achievement';

        $achievement = [
            'id' => $achievementId,
            'type' => ['Achievement'],
            'achievementType' => $record->achievementType,
            'name' => $context->achievementName ?? '',
            'description' => $context->achievementDescription ?? '',
            'criteria' => $context->criteriaUrl === null
                ? ['narrative' => $this->translator->translate('vidyalms.credential.default_criteria')]
                : ['id' => $context->criteriaUrl],
        ];

        if ($context->imageUrl !== null && $context->imageUrl !== '') {
            $achievement['image'] = ['id' => $context->imageUrl, 'type' => 'Image'];
        }

        $subject = [
            'type' => ['AchievementSubject'],
            'achievement' => $achievement,
        ];

        if ($record->recipientIdentityHash !== null) {
            $subject['identifier'] = [[
                'type' => 'IdentityObject',
                'hashed' => true,
                'identityHash' => $record->recipientIdentityHash,
                'identityType' => 'emailAddress',
                'salt' => $record->recipientIdentitySalt,
            ]];
        }

        $credits = $this->creditsEarned($record->credits);

        if ($credits !== null) {
            $subject['creditsEarned'] = $credits;
        }

        $body = [
            '@context' => [self::CONTEXT],
            'id' => $context->credentialUrl($uuid),
            'type' => ['AchievementCredential'],
            'issuer' => $this->issuer($context),
            'validFrom' => $this->instant($record->validFrom ?? $record->issuedAt),
            'credentialSubject' => $subject,
        ];

        if ($record->expiresAt !== null) {
            $body['validUntil'] = $this->instant($record->expiresAt);
        }

        $status = $this->credentialStatus($context);

        if ($status !== []) {
            $body['credentialStatus'] = $status;
        }

        if ($record->enrolmentId !== null) {
            $body['evidence'] = [[
                'id' => $context->verificationUrl($record->code),
                'type' => ['Evidence'],
                'name' => $this->translator->translate('vidyalms.credential.evidence_name'),
            ]];
        }

        // What the certificate's own elements declared they mirror. It never
        // replaces anything above, so an element adds to the document and can
        // never rewrite the issuer or the validity window.
        return CredentialElementClaims::mergeInto($body, $context->elementClaims);
    }

    /**
     * @return array<string, mixed>
     */
    private function issuer(CredentialContext $context): array
    {
        // Both are required members of the Profile and neither may be empty:
        // an empty id is not an IRI, and an unnamed issuer on a shareable
        // credential means a verifier cannot tell who issued it.
        $issuer = [
            'id' => $context->issuerId(),
            'type' => ['Profile'],
            'name' => $context->issuerName(),
        ];

        if ($issuer['name'] === '') {
            $issuer['name'] = $issuer['id'];
        }

        $url = $context->issuerUrl();

        if ($url !== '' && $url !== $issuer['id']) {
            $issuer['url'] = $url;
        }

        $image = $context->issuer['image'] ?? null;

        if (\is_string($image) && $image !== '') {
            $issuer['image'] = ['id' => $image, 'type' => 'Image'];
        }

        return $issuer;
    }

    /**
     * One entry per allocated purpose, which is why allocation is a child
     * table: revocation and suspension are independent axes and one slot can
     * carry one of them.
     *
     * @return list<array<string, mixed>>
     */
    private function credentialStatus(CredentialContext $context): array
    {
        $entries = [];

        foreach ($context->statusEntries as $entry) {
            $entries[] = [
                'id' => $context->statusListUrl($entry['listUuid']) . '#' . $entry['index'],
                'type' => 'BitstringStatusListEntry',
                'statusPurpose' => $entry['purpose'],
                'statusListIndex' => (string) $entry['index'],
                'statusListCredential' => $context->statusListUrl($entry['listUuid']),
            ];
        }

        return $entries;
    }

    /**
     * Open Badges 3.0 puts the amount on the subject, so credit needs no
     * proprietary extension. A credential carrying two schemes reports the
     * first here and both in the verification payload, because creditsEarned
     * is a single number by specification.
     *
     * @param list<CertificateCredit> $credits
     */
    private function creditsEarned(array $credits): ?float
    {
        foreach ($credits as $credit) {
            if (is_numeric($credit->amount)) {
                return (float) $credit->amount;
            }
        }

        return null;
    }

    private function instant(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
