<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

use Shondalai\VidyaLMS\Core\Domain\Roster\OrgMembershipRecord;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;

/**
 * The one place that answers whether an external source may close a
 * membership. There is not a second.
 *
 * The rule is stated once for both halves of this area, because it is one
 * rule: **absence never removes.** An absent group claim is a directory
 * saying "too many to send"; a person missing from a roster file is a file
 * that did not mention them. Neither is a denial, and reading either as one
 * is how a token size change or a filtered export deletes a customer's whole
 * roster on a day nobody meant to change anything.
 *
 * Two conditions, both necessary, for the assertion half:
 *
 * - the **source** says it could tell us everything it knows this time; and
 * - the **connection** is marked authoritative, which only a site-scoped
 *   principal may set, so a tenant cannot vouch for its own removals.
 *
 * One condition for the roster half: the row carries an explicit deletion
 * instruction. A row that is merely inactive is present and not
 * participating, which is a state this product now has and not a removal.
 *
 * Nothing here writes. It answers, and the answer carries its reason so the
 * caller can record why a removal did not happen, which is the record an
 * operator needs when a directory change did not take effect.
 */
final class MembershipRemovalGuard
{
    private function __construct()
    {
    }

    /**
     * Whether this assertion may drive a removal, and why not when it may not.
     *
     * @return array{bool, string} the answer and one of MappingDecision's removal reasons
     */
    public function permitsFromAssertion(AssertedIdentity $identity, bool $connectionAuthoritative): array
    {
        if (!$identity->isComplete()) {
            return [false, MappingDecision::REMOVAL_SOURCE_PARTIAL];
        }

        if (!$connectionAuthoritative) {
            return [false, MappingDecision::REMOVAL_CONNECTION_NOT_AUTHORITATIVE];
        }

        return [true, MappingDecision::REMOVAL_PERMITTED];
    }

    /**
     * Whether a roster row instructs a removal.
     *
     * The signature takes the status a row carried rather than the row, so one
     * guard serves the organisation record, the person record, the membership
     * record and the course assignment without four overloads.
     *
     * It reads the **shipped** roster vocabulary and no longer a second one of
     * its own. There used to be two: a `ForeignStatus` beside four record
     * classes nothing referenced, whose three words were active, inactive and
     * tobedeleted, and the live {@see OrgMembershipRecord}, whose four add
     * completed. So the claim that one guard served both halves of this area
     * was false in the shipped build, the roster half decided removal from an
     * inline test of its own, and this method had no caller at all. The dead
     * family is gone and the one vocabulary is the live one.
     */
    public function permitsFromRoster(string $foreignStatus): bool
    {
        return $foreignStatus === OrgMembershipRecord::STATUS_TO_BE_DELETED;
    }

    /**
     * The guard.
     *
     * It is a static factory rather than a constructor because a caller
     * holding one of these is holding a rule and not a service, and a rule
     * with no state should not be injected as though it had some.
     */
    public static function rule(): self
    {
        return new self();
    }
}
