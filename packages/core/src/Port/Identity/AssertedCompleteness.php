<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * Whether the source could say everything it knows, this time.
 *
 * This is the safety valve, and it is in the type system rather than in a
 * comment because the failure it prevents is a customer's whole roster
 * disappearing. Directories cap the number of groups they will put in a
 * token, and above the cap they omit the group list **entirely** and hand out
 * a link to fetch it instead. Group filters are configured per application
 * and can be configured to emit nothing. So an empty or absent group list is
 * routinely a truthful "too many to send", not "this person is in no groups",
 * and reading it as removal is how a token size change deletes a roster.
 *
 * The rule is one sentence and it is enforced in one place: only a source
 * marked complete, on a connection marked authoritative, may drive a removal.
 * A partial source may add and refresh and may never remove.
 */
final class AssertedCompleteness
{
    /** The source said everything it knows, so an absence is a denial. */
    public const COMPLETE = 'complete';

    /** The source may have been truncated, so an absence is silence. */
    public const PARTIAL = 'partial';

    /** @var list<string> */
    public const VALUES = [self::COMPLETE, self::PARTIAL];

    private function __construct()
    {
    }

    public static function isKnown(string $value): bool
    {
        return \in_array($value, self::VALUES, true);
    }

    /**
     * The value, or a refusal.
     *
     * An unknown word is refused rather than read as partial. Partial is the
     * safe reading of a *known* truncation; reading an unparseable value as
     * safe would hide a mapper that is emitting something nobody has looked at.
     */
    public static function require(string $value, string $field = 'completeness'): string
    {
        if (!self::isKnown($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be one of %s, not "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::VALUES),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $value
            ));
        }

        return $value;
    }

    public static function fromComplete(bool $complete): string
    {
        return $complete ? self::COMPLETE : self::PARTIAL;
    }
}
