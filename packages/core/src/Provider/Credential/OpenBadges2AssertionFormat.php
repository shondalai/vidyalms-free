<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Credential;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;

/**
 * Open Badges 2.0 hosted assertion, also embedded in the PNG badge.
 * Verification reads this document again to check its current revocation status.
 */
final class OpenBadges2AssertionFormat implements CredentialFormatInterface
{
    public const KEY = 'openbadges2.assertion';

    public const CONTEXT = 'https://w3id.org/openbadges/v2';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.credential_format.openbadges2_assertion');
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function build(IssuanceRecord $record, CredentialContext $context): CredentialArtefact
    {
        $body = $this->body($record, $context);

        return CredentialArtefact::of(
            RenderedArtefact::of(
                json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $this->mimeType(),
                'assertion-' . $record->code . '.json',
            ),
            self::KEY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function body(IssuanceRecord $record, CredentialContext $context): array
    {
        $uuid = $record->uuid ?? $record->code;
        $id = $context->credentialUrl($uuid) . '?format=openbadges2';
        $status = $record->statusAt($context->now);

        $assertion = [
            '@context' => self::CONTEXT,
            'type' => 'Assertion',
            'id' => $id,
            'badge' => $this->badgeClass($record, $context, $id),
            // Hosted verification: the verifier fetches this document from the
            // address the badge names and compares. No key, no proof, no
            // rotation, which is exactly why this format is free.
            'verification' => ['type' => 'HostedBadge'],
            'issuedOn' => $this->instant($record->validFrom ?? $record->issuedAt),
        ];

        $recipient = $this->recipient($record);

        if ($recipient !== null) {
            $assertion['recipient'] = $recipient;
        }

        if ($record->expiresAt !== null) {
            $assertion['expires'] = $this->instant($record->expiresAt);
        }

        if (\in_array($status, [CredentialStatus::Revoked, CredentialStatus::Superseded], true)) {
            // The specification requires a hosted issuer to say so on the
            // assertion itself; a wallet that keeps a copy has nothing else to
            // read.
            $assertion['revoked'] = true;

            // The operator's free-text reason is internal and may contain
            // personal information. The public assertion discloses status only.
        }

        $verificationUrl = $context->verificationUrl($record->code);

        if ($verificationUrl !== '') {
            $assertion['evidence'] = $verificationUrl;
        }

        return $assertion;
    }

    /**
     * @return array<string, mixed>
     */
    private function badgeClass(IssuanceRecord $record, CredentialContext $context, string $assertionId): array
    {
        $badge = [
            // Embedded rather than an IRI, because the product publishes no
            // separate BadgeClass document and a dangling IRI is worse than an
            // embedded class a verifier can read in one fetch.
            'type' => 'BadgeClass',
            'id' => $assertionId . '#badge',
            'name' => $context->achievementName ?? 'Certificate',
            'description' => $context->achievementDescription ?? ($context->achievementName ?? 'Certificate'),
            'criteria' => $context->criteriaUrl === null || $context->criteriaUrl === ''
                ? ['narrative' => $context->achievementDescription ?? 'Course completion.']
                : ['id' => $context->criteriaUrl],
            'issuer' => [
                'type' => 'Issuer',
                'id' => $context->issuerId(),
                'name' => $context->issuerName(),
                'url' => $context->issuerUrl(),
            ],
        ];

        if ($context->imageUrl !== null && $context->imageUrl !== '') {
            $badge['image'] = $context->imageUrl;
        }

        return $badge;
    }

    /**
     * The salted IdentityObject the issuance already froze. A learner with no
     * address gets no recipient rather than a hash of an empty string, which
     * would be the same digest for everybody.
     *
     * @return array<string, mixed>|null
     */
    private function recipient(IssuanceRecord $record): ?array
    {
        if ($record->recipientIdentityHash === null) {
            return null;
        }

        $recipient = [
            'type' => 'email',
            'hashed' => true,
            'identity' => $record->recipientIdentityHash,
        ];

        if ($record->recipientIdentitySalt !== null) {
            $recipient['salt'] = $record->recipientIdentitySalt;
        }

        return $recipient;
    }

    private function instant(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
