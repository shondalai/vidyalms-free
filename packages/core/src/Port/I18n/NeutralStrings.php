<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\I18n;

/**
 * The translator keys the core's own copy uses, named once so a use case
 * states a key rather than a string, plus the neutral catalogue's English for
 * a caller that has no translator.
 *
 * The English is a fallback for a detached caller (a grading probe outside a
 * request, a fixture, the catalogue generator), never a substitute for the
 * port: everything on a request path is handed the host's translator and gets
 * the site's language.
 *
 * It is inlined rather than loaded from i18n/catalogue.php because the
 * catalogue is not a runtime input on either host: the Joomla build compiles
 * it into INI files and ships no PHP copy beside the library, so a require
 * relative to src/ would be a file that is not there. NeutralStringsTest
 * asserts every pair here still matches the catalogue, so the two cannot
 * drift.
 */
final class NeutralStrings
{
    public const MISS_UNKNOWN_TYPE = 'vidyalms.assessment.miss.unknown_type';
    public const MISS_UNKNOWN_TYPE_PACKAGE = 'vidyalms.assessment.miss.unknown_type_package';
    public const MISS_UNSUPPORTED_VERSION = 'vidyalms.assessment.miss.unsupported_version';
    public const MISS_UNSUPPORTED_VERSION_PACKAGE = 'vidyalms.assessment.miss.unsupported_version_package';
    public const MISS_DEFINITION_LOST = 'vidyalms.assessment.miss.definition_lost';
    public const MISS_NOT_RENDERED = 'vidyalms.assessment.miss.not_rendered';

    public const ERROR_TOO_FEW_SELECTED = 'vidyalms.assessment.error.too_few_selected';
    public const ERROR_TOO_MANY_SELECTED = 'vidyalms.assessment.error.too_many_selected';
    public const ERROR_MATCH_MAX = 'vidyalms.assessment.error.match_max';
    public const ERROR_INVALID_PERMUTATION = 'vidyalms.assessment.error.invalid_permutation';
    public const ERROR_VALUE_NOT_NUMERIC = 'vidyalms.assessment.error.value_not_numeric';
    public const ERROR_OUT_OF_RANGE = 'vidyalms.assessment.error.out_of_range';
    public const ERROR_UNSUPPORTED_FILE_TYPE = 'vidyalms.assessment.error.unsupported_file_type';

    /**
     * What arrived is not a response to this question at all.
     *
     * It is a shape error and never a wrong answer: nothing has looked at
     * what the learner chose, only at whether what was posted could be an
     * answer to this interaction. It reaches a learner when a stale bundle
     * posts an old shape, so the words say what to do rather than what was
     * judged.
     */
    public const ERROR_INVALID_ENVELOPE = 'vidyalms.assessment.error.invalid_envelope';

    public const QUESTION_PRESET_PREFIX = 'vidyalms.question_preset.';

    /** @var array<string, string> */
    private const ENGLISH = [
        self::MISS_UNKNOWN_TYPE =>
            'The provider for question type "{type}" is not installed, so this answer was not marked.',
        self::MISS_UNKNOWN_TYPE_PACKAGE =>
            'The "{package}" package provides question type "{type}" and is not installed, '
            . 'so this answer was not marked.',
        self::MISS_UNSUPPORTED_VERSION =>
            'This question was authored in a newer format (version {version}) than the installed "{type}" provider '
            . 'reads, so it was not marked.',
        self::MISS_UNSUPPORTED_VERSION_PACKAGE =>
            'This question was authored in a newer format (version {version}) than the installed "{type}" provider '
            . 'from "{package}" reads, so it was not marked.',
        self::MISS_DEFINITION_LOST =>
            'The stored definition this answer was served from is no longer available, so it was not marked.',
        self::MISS_NOT_RENDERED =>
            'This question could not be shown, so there was nothing to answer and it was not marked.',
        self::ERROR_TOO_FEW_SELECTED => 'Choose at least {min}.',
        self::ERROR_TOO_MANY_SELECTED => 'Choose at most {max}.',
        self::ERROR_MATCH_MAX => 'This one can be used at most {limit} times.',
        self::ERROR_INVALID_PERMUTATION => 'Every item must appear exactly once in the order you give.',
        self::ERROR_VALUE_NOT_NUMERIC => 'Give a number.',
        self::ERROR_OUT_OF_RANGE => 'That value is outside the scale.',
        self::ERROR_UNSUPPORTED_FILE_TYPE => 'That file type is not accepted for this question.',
        self::ERROR_INVALID_ENVELOPE => 'That answer could not be read for this question. Answer it again.',
        self::QUESTION_PRESET_PREFIX . 'single_choice' => 'Multiple choice, one answer',
        self::QUESTION_PRESET_PREFIX . 'multiple_choice' => 'Multiple response',
        self::QUESTION_PRESET_PREFIX . 'true_false' => 'True or false',
        self::QUESTION_PRESET_PREFIX . 'short_answer' => 'Short answer',
        self::QUESTION_PRESET_PREFIX . 'fill_blank' => 'Fill in the blank',
        self::QUESTION_PRESET_PREFIX . 'ordering' => 'Sequencing',
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function catalogue(): array
    {
        return self::ENGLISH;
    }

    /**
     * The catalogue's English for a key, or the key itself when it names no
     * string, which is what makes a missing entry visible instead of empty.
     *
     * @param array<string, scalar|null> $parameters
     */
    public static function english(string $key, array $parameters = []): string
    {
        $text = self::ENGLISH[$key] ?? $key;

        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', $value === null ? '' : (string) $value, $text);
        }

        return $text;
    }

    /**
     * Translates through the port when there is one and falls back to the
     * neutral English otherwise, so no caller repeats the null check.
     *
     * @param array<string, scalar|null> $parameters
     */
    public static function say(?TranslatorPort $translator, string $key, array $parameters = []): string
    {
        return $translator?->translate($key, $parameters) ?? self::english($key, $parameters);
    }
}
