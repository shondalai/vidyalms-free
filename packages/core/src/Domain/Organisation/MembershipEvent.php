<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * The membership event vocabulary, split.
 *
 * ## What was wrong with one key
 *
 * The first slice wrote two keys for the whole of membership:
 * `member.added` and `member.updated`. Everything a person or a directory could
 * do to a membership other than create it arrived as one word with a details
 * payload, so the question the whole append-only stream exists to answer, "why
 * did this person have access on 3 March", was answered by decoding JSON and
 * comparing a previous_state against a state, per row, in the reader.
 *
 * That is not a reporting inconvenience. An organisation reading its own audit
 * cannot filter on "who was given the reporting role last quarter" without a
 * scan, `event_key` is the column the export route already filters on, and a
 * retention policy that keeps role grants longer than attribute edits cannot be
 * expressed at all when both are one word.
 *
 * ## The eight keys
 *
 * `joined` is the creation. The other seven are the seven distinguishable
 * things that happen to a membership afterwards, and each one is a separate row
 * even when one request caused several: a request that moves somebody's unit
 * and grants them a role writes two rows, because they are two facts with two
 * different retention answers and two different audiences.
 *
 * ## The old words are not rewritten
 *
 * `member.added` and `member.updated` stay exactly as they were written, on
 * both development sites and anywhere else. LEGACY_KEYS names them so a reader
 * can tell a pre-split row from a post-split one without a date comparison, and
 * so nothing in the product ever writes one again by accident. Rewriting them
 * would mean inventing which of seven meanings each historical row had, from a
 * payload that records the state a membership moved to and not always why.
 *
 * ## Two clocks, one vocabulary
 *
 * Every key here is written with an `occurred_at` and a `recorded_at`, because
 * a backdated correction and a delayed webhook are otherwise indistinguishable:
 * a roster load naming a `dateLastModified` of last Tuesday happened last
 * Tuesday and was recorded today, and a report that cannot tell those apart
 * cannot answer an audit.
 */
final class MembershipEvent
{
    /** A membership row came into existence. */
    public const JOINED = 'joined';

    /** A role was added to the membership. */
    public const ROLE_GRANTED = 'role_granted';

    /** A role was taken off it. */
    public const ROLE_REVOKED = 'role_revoked';

    /** The membership moved to a different unit of the tree. */
    public const UNIT_CHANGED = 'unit_changed';

    /** Access stopped: suspended, or marked inactive or completed by a roster. */
    public const SUSPENDED = 'suspended';

    /** Access resumed. */
    public const RESUMED = 'resumed';

    /** The membership was closed. Terminal, and the row stays. */
    public const REMOVED = 'removed';

    /** One or more directory attributes changed and nothing else did. */
    public const ATTRIBUTES_UPDATED = 'attributes_updated';

    /** The membership changed hands between a person and a provisioning source. */
    public const GOVERNANCE_CHANGED = 'governance_changed';

    /**
     * The whole vocabulary, in the order 15-organisations.md publishes it.
     *
     * @var list<string>
     */
    public const KEYS = [
        self::JOINED,
        self::ROLE_GRANTED,
        self::ROLE_REVOKED,
        self::UNIT_CHANGED,
        self::SUSPENDED,
        self::RESUMED,
        self::REMOVED,
        self::ATTRIBUTES_UPDATED,
        self::GOVERNANCE_CHANGED,
    ];

    /**
     * The two coarse keys written before the split, kept so a reader can name
     * them and so nothing writes one again.
     *
     * @var list<string>
     */
    public const LEGACY_KEYS = ['member.added', 'member.updated'];

    /** A person acting in the console. */
    public const SOURCE_MANUAL = 'manual';

    /** An invitation the person accepted. */
    public const SOURCE_INVITATION = 'invitation';

    /** A roster file an operator uploaded. */
    public const SOURCE_ROSTER = 'roster';

    /** A provisioning source asserting continuously. */
    public const SOURCE_DIRECTORY = 'directory';

    /** The product itself: a sweep, an expiry, a scheduled task. */
    public const SOURCE_SYSTEM = 'system';

    /**
     * @var list<string>
     */
    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_INVITATION,
        self::SOURCE_ROSTER,
        self::SOURCE_DIRECTORY,
        self::SOURCE_SYSTEM,
    ];

    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_PRINCIPAL = 'principal';
    public const ACTOR_IMPORTER = 'importer';
    public const ACTOR_GATEWAY = 'gateway';

    /**
     * @var list<string>
     */
    public const ACTOR_TYPES = [
        self::ACTOR_SYSTEM,
        self::ACTOR_PRINCIPAL,
        self::ACTOR_IMPORTER,
        self::ACTOR_GATEWAY,
    ];

    private function __construct()
    {
    }

    /** Whether this is one of the nine published keys. */
    public static function isKnown(string $key): bool
    {
        return \in_array($key, self::KEYS, true);
    }

    /** Whether this row predates the split and carries none of the new columns. */
    public static function isLegacy(string $key): bool
    {
        return \in_array($key, self::LEGACY_KEYS, true);
    }

    /**
     * The key a membership state change earns.
     *
     * One place, because the roster path, the provisioning path and the console
     * all have to agree: `inactive` and `completed` are stops rather than
     * closures, so all three of them are `suspended` events, and only `left` is
     * a removal. A caller answering this question itself is a caller that will
     * eventually answer it differently.
     */
    public static function forStateChange(string $from, string $to): string
    {
        if ($to === MembershipState::LEFT) {
            return self::REMOVED;
        }

        if ($to === MembershipState::ACTIVE) {
            return $from === MembershipState::ACTIVE ? self::ATTRIBUTES_UPDATED : self::RESUMED;
        }

        return self::SUSPENDED;
    }
}
