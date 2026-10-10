<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * What an importer does when the row it is loading already exists.
 *
 * Four values, borrowed from Moodle's user upload because Moodle made it an
 * explicit run option for a reason worth copying: an importer that guesses
 * will eventually guess wrong on a customer's live roster, and the wrong guess
 * is not a slow import, it is four hundred people's job titles overwritten
 * from a stale export.
 *
 * - `create_only` writes rows that are new and leaves the rest alone. The
 *   choice for a file that adds this term's intake to an existing roster.
 * - `create_and_update` is the default, and is what an operator means when
 *   they say "load this roster".
 * - `update_only` corrects rows that exist and creates nothing. The choice for
 *   a file that fixes departments after somebody typed them wrong, where a
 *   typo in a key must not quietly create a person.
 * - `create_with_suffix` creates a distinct object rather than colliding, for
 *   the sources where the natural key is not really unique.
 *
 * It is a declaration and not an enforcement. The core cannot apply an upsert
 * policy on an importer's behalf, because only the importer knows what "the
 * same row" means in its own format; what this class owns is that all four are
 * spelled the same way in every importer, that the option is drawn the same
 * way on every screen, and that a value nobody offers cannot arrive from a
 * hand-built post.
 */
final class UpsertPolicy
{
    /** The wire name of the option, identical across every importer that offers it. */
    public const OPTION_KEY = 'upsert_policy';

    public const CREATE_ONLY = 'create_only';
    public const CREATE_AND_UPDATE = 'create_and_update';
    public const UPDATE_ONLY = 'update_only';
    public const CREATE_WITH_SUFFIX = 'create_with_suffix';

    public const DEFAULT = self::CREATE_AND_UPDATE;

    /**
     * @var list<string>
     */
    public const POLICIES = [
        self::CREATE_ONLY,
        self::CREATE_AND_UPDATE,
        self::UPDATE_ONLY,
        self::CREATE_WITH_SUFFIX,
    ];

    private function __construct()
    {
    }

    /**
     * The declaration a screen draws a select from.
     *
     * Labels arrive already translated, because the importer holds the
     * translator and the host does not know this importer's string keys. The
     * four values are this class's; the four words in front of them are the
     * caller's, in the caller's language.
     *
     * @param array<string, string> $labels value to already-translated label
     */
    public static function option(string $label, array $labels, string $help = ''): ImportOption
    {
        $choices = [];

        foreach (self::POLICIES as $policy) {
            $choices[] = ['value' => $policy, 'label' => $labels[$policy] ?? $policy];
        }

        return ImportOption::choice(self::OPTION_KEY, $label, $choices, $help, self::DEFAULT);
    }

    /**
     * The policy a run is under.
     *
     * A value nobody offers falls back to the default rather than throwing,
     * which is the same rule ImportOption::read() applies to a choice: a
     * hand-built post cannot smuggle a fifth behaviour, and it cannot fail an
     * import either.
     *
     * @param array<string, mixed> $options
     */
    public static function of(array $options): string
    {
        $value = $options[self::OPTION_KEY] ?? null;

        return \is_string($value) && \in_array($value, self::POLICIES, true) ? $value : self::DEFAULT;
    }

    /** @param array<string, mixed> $options */
    public static function mayCreate(array $options): bool
    {
        return self::of($options) !== self::UPDATE_ONLY;
    }

    /** @param array<string, mixed> $options */
    public static function mayUpdate(array $options): bool
    {
        $policy = self::of($options);

        return $policy === self::CREATE_AND_UPDATE || $policy === self::UPDATE_ONLY;
    }
}
