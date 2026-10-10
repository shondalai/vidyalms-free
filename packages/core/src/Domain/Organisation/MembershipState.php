<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * The states an organisation membership can be in, in one place.
 *
 * It was four inline literals inside the use cases that validated them, which
 * is how the shipped vocabulary and the published one were able to disagree
 * without anything failing. tooling/organisations/spec.php nominated this class
 * and this constant by name and reported the machine as hand maintained until
 * it existed; now it generates.
 *
 * ## Why six and not four
 *
 * `invited`, `active`, `suspended` and `left` were the first slice's. R5 adds
 * Canvas's `inactive` and `completed`, because visible but not participating is
 * a real state an organisation administrator needs and neither of the two words
 * already here says it:
 *
 * - `suspended` is a decision about the person, and it is what an administrator
 *   or a directory does when access should stop and the relationship should
 *   not.
 * - `inactive` is a decision about the roster. A OneRoster export marks a
 *   student who has moved on, and the row must stay readable and stop counting.
 * - `completed` is the same shape with a different cause: the programme ended.
 * - `left` is terminal and the row is closed. It is never reopened, because a
 *   closed row is what makes history answerable; rejoining writes a new row.
 *
 * ## Participating is the only question access asks
 *
 * `active` is the whole of it. Everything else is a membership that exists and
 * grants nothing, which is why adding two states costs the authorisation path
 * nothing at all: it already asked for `active` by name and every read that
 * composes access still does.
 *
 * ## The uniqueness rule follows participation, not existence
 *
 * `org_memberships.active_key` holds its value only while the state is
 * `active`, so a person may hold one active membership per organisation and any
 * number of closed or paused ones. That is unchanged by the two new words: both
 * of them release the key, exactly as `suspended` and `left` do.
 */
final class MembershipState
{
    /** Invited and not yet accepted. No membership rights at all. */
    public const INVITED = 'invited';

    /** The one participating state. */
    public const ACTIVE = 'active';

    /** Access stopped by a decision about the person. */
    public const SUSPENDED = 'suspended';

    /** On the roster, not participating: the source says this person moved on. */
    public const INACTIVE = 'inactive';

    /** On the roster, not participating: the programme ended. */
    public const COMPLETED = 'completed';

    /** Terminal. The row is closed and is never reopened. */
    public const LEFT = 'left';

    /**
     * The whole vocabulary, in lifecycle order.
     *
     * @var list<string>
     */
    public const STATES = [
        self::INVITED,
        self::ACTIVE,
        self::SUSPENDED,
        self::INACTIVE,
        self::COMPLETED,
        self::LEFT,
    ];

    /**
     * The states an administrator or a roster may set on an open membership.
     *
     * `invited` is absent because it is written by issuing an invitation and by
     * nothing else: a caller who could set it by hand could manufacture a
     * pending invitation with no token behind it.
     *
     * @var list<string>
     */
    public const SETTABLE = [
        self::ACTIVE,
        self::SUSPENDED,
        self::INACTIVE,
        self::COMPLETED,
        self::LEFT,
    ];

    /**
     * The states that hold a person's place without granting anything.
     *
     * @var list<string>
     */
    public const PAUSED = [
        self::SUSPENDED,
        self::INACTIVE,
        self::COMPLETED,
    ];

    private function __construct()
    {
    }

    /** Whether this word is one of the six. */
    public static function isKnown(string $state): bool
    {
        return \in_array($state, self::STATES, true);
    }

    /**
     * Whether this state grants anything, which is the only question the
     * authorisation path asks of a membership.
     */
    public static function participates(string $state): bool
    {
        return $state === self::ACTIVE;
    }

    /** Whether the row is closed, which is the one state that cannot be reopened. */
    public static function isClosed(string $state): bool
    {
        return $state === self::LEFT;
    }
}
