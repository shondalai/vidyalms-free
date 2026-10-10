<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * One organisation's claim on a mail domain, as a read model.
 *
 * The free build records these and shows them unverified. The read model says
 * so in its own words rather than leaving a screen to infer it from a status
 * string, because "unverified" and "cannot be verified on this build" are two
 * different sentences to show an operator and only one of them is honest here.
 */
final class OrgDomain
{
    private function __construct(
        public readonly int $id,
        public readonly int $organisationId,
        public readonly string $domain,
        public readonly string $method,
        public readonly string $token,
        public readonly string $status,
        public readonly ?string $verifiedAt,
        public readonly ?string $lastCheckedAt,
        public readonly int $failureCount,
    ) {
    }

    public static function fromRow(
        int $id,
        int $organisationId,
        string $domain,
        string $method,
        string $token,
        string $status,
        ?string $verifiedAt,
        ?string $lastCheckedAt,
        int $failureCount,
    ): self {
        return new self(
            $id,
            $organisationId,
            $domain,
            // Refused rather than coerced. It used to fall back to 'dns-txt',
            // so a proof obtained by a registrar API, an MX record or a
            // postmaster mailbox was recorded as a DNS TXT proof that had
            // never been published, and the row then claimed evidence nobody
            // could go and look at.
            self::method($method),
            $token,
            DomainStatus::isKnown($status) ? $status : DomainStatus::PENDING,
            $verifiedAt,
            $lastCheckedAt,
            max(0, $failureCount),
        );
    }

    /**
     * The method word a row may carry.
     *
     * A row whose method this build does not know is a row written by a
     * package that has since been removed, and it is kept as it is: the proof
     * it names really was performed, and rewriting it as one this build
     * happens to spell would be the record claiming something that never
     * happened. What is refused is the empty word, which names nothing.
     */
    private static function method(string $method): string
    {
        $trimmed = trim($method);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('A domain claim names the method its proof was obtained by.');
        }

        return $trimmed;
    }

    public function isVerified(): bool
    {
        return $this->status === DomainStatus::VERIFIED;
    }

    /**
     * The record an operator has to publish, as the value and not as
     * instructions.
     *
     * Composing it here keeps one token in one shape: a screen that built its
     * own would drift from whatever a verifier later looks for, and the two
     * would disagree in a way nobody could see.
     */
    public function proofRecord(): string
    {
        return 'vidyalms-site-verification=' . $this->token;
    }

    /**
     * @param bool $canVerify whether anything installed can actually prove a claim
     * @param bool $mayClaim  whether this reader may write a claim on this organisation
     *
     * @return array<string, mixed>
     */
    public function toArray(bool $canVerify, bool $mayClaim = true): array
    {
        return [
            'id' => $this->id,
            'organisation_id' => $this->organisationId,
            'domain' => $this->domain,
            'method' => $this->method,
            'status' => $this->status,
            // The token is instructions for the one person who has to publish
            // it, and it is the whole proof: anybody holding it can prove this
            // domain from any DNS zone they control. Every organisation read
            // right reached it, so a reporter and a subtree manager who cannot
            // claim a domain were both handed the claim of one. Null rather
            // than absent, so a screen can say "you cannot see this" instead of
            // rendering a gap.
            'proof_record' => $mayClaim ? $this->proofRecord() : null,
            'verified_at' => $this->verifiedAt,
            'last_checked_at' => $this->lastCheckedAt,
            'failure_count' => $this->failureCount,
            // The honest sentence. False means no installed provider can
            // answer, which is the free build, and the screen says so rather
            // than showing a Verify button that always fails.
            'verifiable' => $canVerify,
        ];
    }
}
