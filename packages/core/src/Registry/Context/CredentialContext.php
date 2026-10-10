<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialUrlUnavailable;

/**
 * What a credential format may read while building a document.
 *
 * The issuer, the public URLs and the achievement description are site facts
 * the core does not otherwise hold, and a format that had to fetch them would
 * need a database handle it has no other use for. The status list entries are
 * here because they are allocated by the code that creates the credential and
 * cannot be back-filled deterministically afterwards: a signed credential
 * cannot be edited once it sits in a wallet.
 */
final class CredentialContext
{
    /**
     * @param array<string, string>                           $issuer       name, url and image
     * @param list<array{purpose: string, listUuid: string, index: int}> $statusEntries
     */
    private function __construct(
        public readonly array $issuer,
        public readonly string $credentialBaseUrl,
        public readonly string $verificationBaseUrl,
        public readonly string $statusListBaseUrl,
        public readonly ?string $achievementName,
        public readonly ?string $achievementDescription,
        public readonly ?string $criteriaUrl,
        public readonly ?string $imageUrl,
        public readonly array $statusEntries,
        public readonly string $locale,
        public readonly \DateTimeImmutable $now,
        /**
         * What the certificate's own frozen elements declared they mirror in
         * the credential, keyed by dotted credential path.
         *
         * It is here so extending the emitted document is a registration: an
         * element that declares a credential property and resolves to text
         * puts that text into the body, and a format merges the map without
         * knowing which elements exist. A claim never replaces a property the
         * format itself wrote.
         *
         * A claim is not always a string: Open Badges 3.0 declares
         * creditsEarned a number, and an element whose printed line and
         * machine-readable fact differ contributes the second through
         * ProvidesCredentialValue.
         *
         * @var array<string, string|int|float>
         */
        public readonly array $elementClaims = [],
    ) {
    }

    /**
     * @param array<string, string>                                      $issuer
     * @param list<array{purpose: string, listUuid: string, index: int}> $statusEntries
     * @param array<string, string|int|float>                            $elementClaims
     */
    public static function create(
        \DateTimeImmutable $now,
        array $issuer = [],
        string $credentialBaseUrl = '',
        string $verificationBaseUrl = '',
        string $statusListBaseUrl = '',
        ?string $achievementName = null,
        ?string $achievementDescription = null,
        ?string $criteriaUrl = null,
        ?string $imageUrl = null,
        array $statusEntries = [],
        string $locale = 'en-GB',
        array $elementClaims = [],
    ): self {
        return new self(
            $issuer,
            $credentialBaseUrl,
            $verificationBaseUrl,
            $statusListBaseUrl,
            $achievementName,
            $achievementDescription,
            $criteriaUrl,
            $imageUrl,
            $statusEntries,
            $locale,
            $now,
            $elementClaims,
        );
    }

    public function issuerName(): string
    {
        return $this->issuer['name'] ?? '';
    }

    public function issuerUrl(): string
    {
        return $this->issuer['url'] ?? '';
    }

    /**
     * The issuer's identifier, which is a required member of the Open Badges
     * 3.0 Profile and must be an IRI.
     *
     * An empty string is not an IRI, and that is what every shipped site
     * emitted. The address answers where there is one; a URN answers where
     * there is not, because a document that says its issuer has no published
     * address is honest and a document with a blank id is broken.
     */
    public function issuerId(): string
    {
        $id = $this->issuer['id'] ?? '';

        if ($id !== '') {
            return $id;
        }

        $url = $this->issuerUrl();

        return $url !== '' ? $url : 'urn:vidyalms:issuer';
    }

    /**
     * The credential's own id, minted from the kernel option and never from
     * the request.
     *
     * A host that supplied neither base gets a named refusal rather than an
     * empty string: `id` is a required member, an empty string is not an IRI,
     * and a document with a blank id fails the very schema this format claims
     * to be one signature away from.
     */
    public function credentialUrl(string $uuid): string
    {
        if ($this->credentialBaseUrl === '') {
            throw CredentialUrlUnavailable::credentialBase();
        }

        return rtrim($this->credentialBaseUrl, '/') . '/' . rawurlencode($uuid);
    }

    public function hasCredentialBaseUrl(): bool
    {
        return $this->credentialBaseUrl !== '';
    }

    public function verificationUrl(string $code): string
    {
        return $this->verificationBaseUrl === ''
            ? ''
            : \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append($this->verificationBaseUrl, $code);
    }

    /**
     * Where a status list is published, minted from the kernel option for the
     * same reason and refused the same way: an entry whose
     * statusListCredential is an empty string sends a verifier nowhere, and
     * the verifier algorithm's first step is to dereference it.
     */
    public function statusListUrl(string $listUuid): string
    {
        if ($this->statusListBaseUrl === '') {
            throw CredentialUrlUnavailable::statusListBase();
        }

        return rtrim($this->statusListBaseUrl, '/') . '/' . rawurlencode($listUuid);
    }

    public function hasStatusListBaseUrl(): bool
    {
        return $this->statusListBaseUrl !== '';
    }
}
