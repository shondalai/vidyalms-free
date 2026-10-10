<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * The question an invitation match policy answers: may THIS account use THIS
 * invitation.
 *
 * A context object with a private constructor and named factories, like the
 * others in this directory. There are three factories because the shipped
 * ceremony asks the question at three separate moments and each one holds a
 * different amount of the answer, and a single fabricable constructor would
 * let a caller assert a match the ceremony never actually performed.
 *
 * The addresses are normalised here rather than at each call site, because
 * three call sites lowercasing and trimming by hand is three chances to
 * forget one and a provider that then compares "A@b.test" against "a@b.test"
 * and refuses a legitimate acceptance.
 *
 * **The raw invitation token and the raw proof code never reach a provider.**
 * A match policy decides identity, not possession; possession is proved by
 * the constant-time comparisons in the use case, which run before the policy
 * is asked at all.
 */
final class InvitationMatchContext
{
    /** The account presented a raw invitation token and the row was found by its hash. */
    public const STEP_TOKEN = 'token';

    /** The account entered the mailbox proof code sent to the invited address. */
    public const STEP_PROOF = 'proof';

    /** The outbox is about to render a message carrying a secret to the invited address. */
    public const STEP_DELIVERY = 'delivery';

    private function __construct(
        public readonly string $step,
        public readonly int $organisationId,
        public readonly int $invitationId,
        public readonly int $invitationRevision,
        public readonly string $invitedEmail,
        public readonly string $accountEmail,
        public readonly int $accountUserId,
        public readonly ?int $claimId,
    ) {
    }

    /**
     * The moment a signed-in account presents the raw invitation token.
     *
     * This is the first of the two acceptance gates and the weaker one: a
     * token can be forwarded, so a match here only decides whether this
     * account may begin the ceremony at all.
     */
    public static function forToken(
        int $organisationId,
        int $invitationId,
        int $invitationRevision,
        string $invitedEmail,
        string $accountEmail,
        int $accountUserId,
    ): self {
        return new self(
            self::STEP_TOKEN,
            $organisationId,
            $invitationId,
            $invitationRevision,
            self::normalise($invitedEmail),
            self::normalise($accountEmail),
            $accountUserId,
            null,
        );
    }

    /**
     * The moment a mailbox proof code is verified against a claim row.
     *
     * This is the gate that actually admits, and it is asked after the code
     * has been compared in constant time, so a provider is deciding identity
     * over a possession that is already proved.
     */
    public static function forProof(
        int $organisationId,
        int $invitationId,
        int $invitationRevision,
        string $invitedEmail,
        string $accountEmail,
        int $accountUserId,
        int $claimId,
    ): self {
        return new self(
            self::STEP_PROOF,
            $organisationId,
            $invitationId,
            $invitationRevision,
            self::normalise($invitedEmail),
            self::normalise($accountEmail),
            $accountUserId,
            $claimId,
        );
    }

    /**
     * The moment the outbox is about to put a secret in front of an address.
     *
     * It is asked here as well as at acceptance because the proof mail is
     * addressed to the invited mailbox and names the account that asked for
     * it: an account whose address moved between the claim and the send must
     * not receive a code minted for the address it used to hold.
     */
    public static function forDelivery(
        int $organisationId,
        int $invitationId,
        int $invitationRevision,
        string $invitedEmail,
        string $accountEmail,
        int $accountUserId,
        ?int $claimId,
    ): self {
        return new self(
            self::STEP_DELIVERY,
            $organisationId,
            $invitationId,
            $invitationRevision,
            self::normalise($invitedEmail),
            self::normalise($accountEmail),
            $accountUserId,
            $claimId,
        );
    }

    private static function normalise(string $address): string
    {
        return strtolower(trim($address));
    }
}
