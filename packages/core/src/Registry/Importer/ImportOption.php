<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * One option an importer accepts, described well enough for a host to draw a
 * control for it.
 *
 * Options were always a free key/value map on the wire, which is right: their
 * meaning belongs to the importer that declares them, and the core must never
 * grow an opinion about what "profile" means to a QTI package. What was
 * missing was any way for a screen to know an option exists. The Import screen
 * on both hosts therefore offered exactly two source shapes and nothing else,
 * so a QTI export profile, a Moodle XML dialect, a Community Quiz table prefix
 * and the JSON course key were all reachable only by an API client.
 *
 * This is the declaration and nothing more. It carries no validation rule and
 * no default the importer does not apply itself: an option's meaning stays in
 * `import()`, and a screen that renders a choice the importer then ignores is
 * the importer's bug, not this class's. The one thing it does enforce is that
 * a declaration cannot describe a control a host could not draw: a choice with
 * no choices, a default that is not one of them, or a key the pipeline already
 * owns.
 */
final class ImportOption
{
    /** A single line of free text. */
    public const KIND_TEXT = 'text';

    /** A whole number. */
    public const KIND_NUMBER = 'number';

    /** A checkbox; the value on the wire is a boolean. */
    public const KIND_BOOLEAN = 'boolean';

    /** One of `choices`, which the host draws as a select. */
    public const KIND_CHOICE = 'choice';

    /**
     * @var list<string>
     */
    public const KINDS = [self::KIND_TEXT, self::KIND_NUMBER, self::KIND_BOOLEAN, self::KIND_CHOICE];

    /**
     * The one option name the pipeline owns. RunImportJob decides it from
     * which button the operator pressed, so an importer that declared it would
     * put a control on the screen that the run then overrules.
     */
    public const RESERVED_KEY = 'dry_run';

    /**
     * @param string                                    $key     the wire name, as it appears in the options map
     * @param string                                    $label   already translated, because the importer holds the translator
     * @param string                                    $kind    one of the KIND_* constants
     * @param string                                    $help    one sentence under the control, already translated
     * @param string                                    $default the value a host preselects; an empty string means none
     * @param list<array{value: string, label: string}> $choices the offered values, for KIND_CHOICE only
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $kind = self::KIND_TEXT,
        public readonly string $help = '',
        public readonly string $default = '',
        public readonly array $choices = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new \InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'An import option key is lowercase letters, digits and underscores, starting with a letter: ' . $key,
            );
        }

        if ($key === self::RESERVED_KEY) {
            throw new \InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'The "' . self::RESERVED_KEY . '" option belongs to the import pipeline and cannot be declared.',
            );
        }

        if (!\in_array($kind, self::KINDS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException('Unknown import option kind: ' . $kind);
        }

        if ($kind === self::KIND_CHOICE && $choices === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException('The "' . $key . '" option is a choice with nothing to choose from.');
        }

        if ($kind !== self::KIND_CHOICE && $choices !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException('The "' . $key . '" option carries choices but is not a choice.');
        }

        if ($choices !== [] && $default !== '' && !\in_array($default, self::values($choices), true)) {
            throw new \InvalidArgumentException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'The default of the "' . $key . '" option is not one of its choices: ' . $default,
            );
        }
    }

    /**
     * A checkbox, whose value travels as a boolean.
     */
    public static function boolean(string $key, string $label, string $help = '', bool $default = false): self
    {
        return new self($key, $label, self::KIND_BOOLEAN, $help, $default ? '1' : '');
    }

    /**
     * A select. The first argument of each choice is what reaches `import()`.
     *
     * @param list<array{value: string, label: string}> $choices
     */
    public static function choice(
        string $key,
        string $label,
        array $choices,
        string $help = '',
        string $default = '',
    ): self {
        return new self($key, $label, self::KIND_CHOICE, $help, $default, $choices);
    }

    /**
     * Turns what a screen submitted into the options map `import()` is handed,
     * one declaration at a time.
     *
     * It lives here rather than in each adapter because the coercion is the
     * declaration's meaning and not a host's: a checkbox posts nothing when it
     * is off and the string "1" when it is on, and an importer written against
     * `($options['enrolments'] ?? false) === true` reads that string as false
     * and silently does the opposite of what the operator asked. One
     * implementation, shared by both hosts, is the only way the same form on
     * two CMSes cannot mean two things.
     *
     * The rules, in the order they matter:
     *
     * - a boolean is always present in the answer, true or false, because its
     *   absence from a form is a decision rather than a silence;
     * - a choice outside the declared values is dropped, so a hand-built post
     *   cannot smuggle a dialect the importer never offered;
     * - a number that is not one is dropped;
     * - an empty text field is dropped, which is what lets the importer's own
     *   default stand;
     * - a submitted key nothing declared is passed through when it is a
     *   scalar, because an importer may read an option it chose not to draw
     *   and a script setting one must keep working.
     *
     * @param list<self>           $declared
     * @param array<string, mixed> $submitted
     *
     * @return array<string, mixed>
     */
    public static function read(array $declared, array $submitted): array
    {
        $values = [];
        $known  = [];

        foreach ($declared as $option) {
            $known[] = $option->key;
            /** @var mixed $raw */
            $raw = $submitted[$option->key] ?? null;

            if ($option->kind === self::KIND_BOOLEAN) {
                $values[$option->key] = self::truth($raw);

                continue;
            }

            if ($raw === null || \is_array($raw) || \is_object($raw)) {
                continue;
            }

            /** @var scalar $raw */
            $text = trim((string) $raw);

            if ($text === '') {
                continue;
            }

            if ($option->kind === self::KIND_NUMBER) {
                if (preg_match('/^-?\d+$/', $text) === 1) {
                    $values[$option->key] = (int) $text;
                }

                continue;
            }

            if ($option->kind === self::KIND_CHOICE && !\in_array($text, self::values($option->choices), true)) {
                continue;
            }

            $values[$option->key] = $text;
        }

        /** @var mixed $raw */
        foreach ($submitted as $key => $raw) {
            if (\in_array($key, $known, true) || $key === self::RESERVED_KEY || !\is_scalar($raw)) {
                continue;
            }

            $values[$key] = $raw;
        }

        return $values;
    }

    /**
     * What a submitted value means for a boolean option.
     *
     * A checkbox posts "1" or nothing at all, which is why absence is false
     * and presence is true; that half is unchanged and is what both host
     * screens rely on. What is new is the negative words. An API client sends
     * JSON, and `{"skip_unknown_roles": "no"}` used to read as true, because a
     * non-empty string is not empty: the option meant the opposite of what the
     * caller wrote, and the coercion the two screens ran was the only thing
     * standing between a form and that answer.
     *
     * A real boolean false, a numeric zero and the four words a person writes
     * for "off" are false; everything else present is true.
     */
    private static function truth(mixed $raw): bool
    {
        if ($raw === null || $raw === false || $raw === 0 || $raw === 0.0) {
            return false;
        }

        if (!\is_string($raw)) {
            return true;
        }

        return !\in_array(strtolower(trim($raw)), ['', '0', 'no', 'false', 'off'], true);
    }

    /**
     * The shape a host screen or the API renders from.
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     kind: string,
     *     help: string,
     *     default: string,
     *     choices: list<array{value: string, label: string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'kind' => $this->kind,
            'help' => $this->help,
            'default' => $this->default,
            'choices' => $this->choices,
        ];
    }

    /**
     * @param list<array{value: string, label: string}> $choices
     *
     * @return list<string>
     */
    private static function values(array $choices): array
    {
        $values = [];

        foreach ($choices as $choice) {
            $values[] = $choice['value'];
        }

        return $values;
    }
}
