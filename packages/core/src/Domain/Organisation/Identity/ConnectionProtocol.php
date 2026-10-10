<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * The stored vocabulary of org_connections.protocol, and the ONE file in the
 * core allowed to spell a foreign protocol word in code.
 *
 * This is the anti-corruption boundary written down. Everything else in the
 * core knows a connection has a dialect, an issuer and a verification state; it
 * does not know what any of the words below mean, cannot parse anything shaped
 * like one, and holds no certificate, secret or bearer token for any of them.
 * The CI check named "protocol-words" refuses these words in code anywhere else
 * in packages/core, and exempts this file by name, so the boundary is a build
 * failure rather than a convention.
 *
 * They exist here at all because the column stores them: an operator looking at
 * a connection row has to see what kind of thing it is, and a free build that
 * could only store its own word would make every paid connection row read as
 * "host".
 *
 * The free build writes exactly one of them, {@see HOST}, and terminates
 * nothing: the host has already authenticated the person by its own means and
 * the core is reading what the host says about them afterwards.
 */
final class ConnectionProtocol
{
    /** The host CMS's own account and groups. The only one the free build writes. */
    public const HOST = 'host';

    /** An assertion-based federation a paid package terminates. */
    public const ASSERTION = 'saml';

    /** A token-based federation a paid package terminates. */
    public const TOKEN = 'oidc';

    /** A directory push a paid package receives on its own HTTP surface. */
    public const DIRECTORY_PUSH = 'scim';

    /**
     * The core's own four words, and not the whole storable vocabulary.
     *
     * A package that terminates a federation the core does not spell declares
     * it through `TerminatesProtocol` and the connection surface accepts the
     * word its dialect declares. These four stay named because the column
     * stores them, the reports bucket on them and the free build writes one of
     * them; they are the documented set rather than the closed one.
     *
     * @var list<string>
     */
    public const PROTOCOLS = [self::HOST, self::ASSERTION, self::TOKEN, self::DIRECTORY_PUSH];

    /**
     * What the free build may write.
     *
     * A connection naming any other protocol is refused at save unless a
     * registered identity mapper declares that dialect, which is the rule that
     * stops an organisation administrator recording a federation nothing on the
     * site can read and then being surprised that nobody can sign in through it.
     *
     * @var list<string>
     */
    public const FREE = [self::HOST];

    private function __construct()
    {
    }

    public static function isKnown(string $protocol): bool
    {
        return \in_array($protocol, self::PROTOCOLS, true);
    }

    /**
     * Whether this word may be stored at all, before anybody asks who declares
     * it.
     *
     * {@see PROTOCOLS} is the core's own vocabulary and was also the whole
     * storable one, which made the seam beside it unreachable: a package
     * declaring `TerminatesProtocol` with a word the core does not spell, CAS
     * or WS-Fed on any of the three hosts, was refused before its declaration
     * was ever read, and the only row it could write was `host`, which would
     * have called a federation the host's own account.
     *
     * So the stored vocabulary is open and the gate moved one step later: this
     * says the word is a word, and the registered dialect's own
     * `protocols()` says whether anything on this site terminates it. Nothing
     * is widened by being spellable, because a word nobody declares is still
     * refused.
     *
     * The shape is deliberately narrow. It is a lower-case identifier, short
     * enough for the column, so a protocol word cannot carry a URL, a
     * separator that means something to a later reader, or an entity id
     * somebody mistook for a kind.
     */
    public static function isStorable(string $protocol): bool
    {
        return preg_match('/^[a-z][a-z0-9_-]{1,19}$/D', $protocol) === 1;
    }
}
