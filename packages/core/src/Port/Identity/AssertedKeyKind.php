<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * What kind of thing an asserted group key actually is.
 *
 * The vocabulary is closed and it is mandatory on every asserted group,
 * because a group value is not an identifier unless the directory says so.
 * One directory emits object ids, another emits display names, another emits
 * a distinguished name, and the display name of a group is explicitly neither
 * unique nor immutable in the standard that defines it. A rule written
 * against a name and evaluated against an id matches nothing; a rule written
 * against a name and evaluated against a *renamed* name matches the wrong
 * people. Naming the kind is what lets a rule refuse to compare two values
 * that are not the same kind of thing.
 *
 * Five kinds and no sixth without a wave that means it: a stored rule carries
 * its kind, so adding one changes what an existing rule may be compared with.
 */
final class AssertedKeyKind
{
    /** An immutable object identifier the directory minted. */
    public const ID = 'id';

    /** A display name, which may be renamed and may repeat. */
    public const NAME = 'name';

    /** A directory distinguished name. */
    public const DN = 'dn';

    /** A slash-separated path through a group tree. */
    public const PATH = 'path';

    /** A value qualified by the domain that issued it. */
    public const SCOPED = 'scoped';

    /** @var list<string> */
    public const KINDS = [self::ID, self::NAME, self::DN, self::PATH, self::SCOPED];

    private function __construct()
    {
    }

    public static function isKnown(string $kind): bool
    {
        return \in_array($kind, self::KINDS, true);
    }

    /**
     * The kind, or a refusal naming the field.
     *
     * There is no default. A caller who cannot say what kind of key it holds
     * is a caller whose rules cannot be evaluated safely, and guessing "name"
     * for it is how an id comparison silently starts matching nothing.
     */
    public static function require(string $kind, string $field = 'key_kind'): string
    {
        if (!self::isKnown($kind)) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be one of %s, not "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::KINDS),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $kind
            ));
        }

        return $kind;
    }
}
