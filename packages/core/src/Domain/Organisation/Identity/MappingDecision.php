<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Why this person was granted what they were granted, on this assertion.
 *
 * It is the explainer, and it is the answer to the one question a customer
 * cannot otherwise answer: why did this person have access on the third of
 * March. Without it the answer lives in an identity provider log the customer
 * does not own and cannot search, and the product's own record says only that
 * a membership changed.
 *
 * So the decision lists every rule that fired, every asserted key that matched
 * nothing, every rule that was refused and the reason, and whether the source
 * was allowed to remove anything at all. It is stored behind
 * org_membership_events.decision_ref, which is what makes the event stream
 * navigable backwards from a membership to the assertion that caused it.
 *
 * The refusal reasons are a closed vocabulary rather than free text, because
 * the screen renders them and a support conversation quotes them.
 */
final class MappingDecision
{
    /** The stored shape of an explainer. */
    public const SHAPE_VERSION = 1;

    /** The rule named an organisation that is not the connection's own. */
    public const REFUSED_FOREIGN_ORG = 'foreign_organisation';

    /** The rule named a unit that is not inside the connection's organisation. */
    public const REFUSED_FOREIGN_UNIT = 'foreign_unit';

    /** The rule named a role no mapping may grant, or one that carries site administration. */
    public const REFUSED_ROLE = 'forbidden_role';

    /** The whole rule set was written in a shape this build cannot read. */
    public const REFUSED_UNREADABLE = 'unreadable_shape';

    /** The rule compared a group value against a different kind of key. */
    public const REFUSED_KEY_KIND = 'key_kind_mismatch';

    /** Why a source was not allowed to remove anything. */
    public const REMOVAL_SOURCE_PARTIAL = 'source_partial';
    public const REMOVAL_CONNECTION_NOT_AUTHORITATIVE = 'connection_not_authoritative';

    /**
     * The pass read nothing it could compare, so it is not evidence that
     * anybody left.
     *
     * A complete assertion on an authoritative connection whose every group
     * carried a key kind no rule was written for granted nothing and still
     * permitted removal, and so did one carrying only group names nobody had
     * written a rule about. That is a directory that changed its group key kind
     * or its naming, which is the case the value_kind vocabulary exists for in
     * the first place: an identity provider emitting names where it used to
     * emit object ids. Treating it as "granted nothing, so close everything" is
     * the same failure as a truncated claim, arriving by a different door.
     */
    public const REMOVAL_EVIDENCE_UNREADABLE = 'evidence_unreadable';

    public const REMOVAL_PERMITTED = 'permitted';

    /**
     * @param list<MappingGrant>                              $grants
     * @param list<array{rule: int, condition: string}>       $fired
     * @param list<string>                                    $unmatchedKeys
     * @param list<array{rule: int, reason: string}>          $refusals
     */
    private function __construct(
        public readonly string $issuer,
        public readonly string $subject,
        public readonly array $grants,
        public readonly array $fired,
        public readonly array $unmatchedKeys,
        public readonly array $refusals,
        public readonly bool $mayRemove,
        public readonly string $removalBasis,
        /** Rules dropped because they no longer parse, counted rather than named. */
        public readonly int $droppedRules,
    ) {
    }

    /**
     * @param list<MappingGrant>                        $grants
     * @param list<array{rule: int, condition: string}> $fired
     * @param list<string>                              $unmatchedKeys
     * @param list<array{rule: int, reason: string}>    $refusals
     */
    public static function of(
        string $issuer,
        string $subject,
        array $grants,
        array $fired,
        array $unmatchedKeys,
        array $refusals,
        bool $mayRemove,
        string $removalBasis,
        int $droppedRules = 0,
    ): self {
        return new self(
            $issuer,
            $subject,
            $grants,
            $fired,
            $unmatchedKeys,
            $refusals,
            $mayRemove,
            $removalBasis,
            $droppedRules,
        );
    }

    /** Whether anything at all was granted. Deny by default means this is routinely false. */
    public function grantsAnything(): bool
    {
        return $this->grants !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return StoredShape::wrap([
            'issuer' => $this->issuer,
            'subject' => $this->subject,
            'grants' => array_map(static fn (MappingGrant $grant): array => $grant->toArray(), $this->grants),
            'fired' => $this->fired,
            'unmatched' => $this->unmatchedKeys,
            'refusals' => $this->refusals,
            'may_remove' => $this->mayRemove,
            'removal_basis' => $this->removalBasis,
            'dropped_rules' => $this->droppedRules,
        ], self::SHAPE_VERSION);
    }
}
