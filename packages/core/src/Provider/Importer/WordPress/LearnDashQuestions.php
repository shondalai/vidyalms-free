<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\QuestionDraft;

/**
 * One ProQuiz question row, translated into a free question type.
 *
 * ## The schema this file was written from
 *
 * `learndash_pro_quiz_question` as LearnDash inherited it from WP Pro Quiz:
 * `answer_type` is one of `single`, `multiple`, `free_answer`, `sort_answer`,
 * `matrix_sort_answer`, `cloze_answer`, `assessment_answer` or `essay`, and
 * `answer_data` is a serialised list of `WpProQuiz_Model_AnswerTypes` whose
 * private properties are `_answer`, `_html`, `_points`, `_correct`,
 * `_sortString` and `_sortStringHtml`. Cloze gaps are written inside the
 * answer text as `{one|two}`, where the alternatives of one gap are separated
 * by a vertical bar. **Unverified against a real install.**
 *
 * ## What is refused, and why it is refused rather than converted
 *
 * `matrix_sort_answer`, `assessment_answer` and `essay` have no free type that
 * carries them. Each is skipped with a row problem naming the foreign type
 * word, never converted into a type that asks the learner something else: a
 * matrix question flattened into a multiple choice is a different question,
 * and the teacher would find that out from a grade dispute rather than from
 * the log.
 */
final class LearnDashQuestions
{
    /** The ProQuiz answer types that have a free home, and where they land. */
    public const TYPES = [
        'single' => 'single_choice',
        'multiple' => 'multiple_choice',
        'free_answer' => 'short_answer',
        'sort_answer' => 'ordering',
        'cloze_answer' => 'fill_blank',
    ];

    /** The property names a serialised answer carries, once unmangled. */
    private const ANSWER = '_answer';
    private const CORRECT = '_correct';

    /** The key one cloze gap is given, numbered from one. */
    private const BLANK = 'b';

    private function __construct()
    {
    }

    public static function supports(string $answerType): bool
    {
        return isset(self::TYPES[$answerType]);
    }

    /**
     * @param array<string, mixed> $row a `learndash_pro_quiz_question` row
     *
     * @throws \RuntimeException when the row cannot produce a valid question
     */
    public static function map(array $row): QuestionDraft
    {
        $type = WordPressSource::text($row['answer_type'] ?? null);
        $free = self::TYPES[$type] ?? throw new \RuntimeException(
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            sprintf('answer type "%s" has no free equivalent', $type),
        );

        $prompt = WordPressSource::text($row['question'] ?? null);

        if ($prompt === '') {
            $prompt = WordPressSource::text($row['title'] ?? null);
        }

        if ($prompt === '') {
            throw new \RuntimeException('it asks nothing');
        }

        $points = is_numeric($row['points'] ?? null) ? (float) $row['points'] : 1.0;
        $answers = self::answers($row['answer_data'] ?? null);
        $sourceId = WordPressSource::text($row['id'] ?? null);
        [$definition, $note] = self::definition($type, $free, $prompt, $answers);

        return QuestionDraft::of($sourceId, $definition['type'], $definition['definition'], $points, $note);
    }

    /**
     * @param list<array<string, string>> $answers
     *
     * @return array{0: array{type: string, definition: array<string, mixed>}, 1: string|null}
     */
    private static function definition(string $type, string $free, string $prompt, array $answers): array
    {
        return match ($type) {
            'single' => self::single($free, $prompt, $answers),
            'multiple' => [['type' => $free, 'definition' => self::multiple($prompt, $answers)], null],
            'free_answer' => [['type' => $free, 'definition' => self::free($prompt, $answers)], null],
            'sort_answer' => [['type' => $free, 'definition' => self::sorted($prompt, $answers)], null],
            // Exhaustive over TYPES, which map() already required.
            default => [['type' => $free, 'definition' => self::cloze($prompt, $answers)], null],
        };
    }

    /**
     * A single answer question, or a true or false one when that is plainly
     * what the author wrote. LearnDash has no true or false type of its own,
     * so a two option question whose options are the two words is one, and
     * landing it on the free true/false type is what an author expects to see.
     *
     * @param list<array<string, string>> $answers
     *
     * @return array{0: array{type: string, definition: array<string, mixed>}, 1: string|null}
     */
    private static function single(string $free, string $prompt, array $answers): array
    {
        $words = [];

        foreach ($answers as $answer) {
            // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            $words[] = strtolower(trim(strip_tags($answer[self::ANSWER] ?? '')));
        }

        if (\count($answers) === 2 && ($words === ['true', 'false'] || $words === ['false', 'true'])) {
            $correct = null;

            foreach ($answers as $index => $answer) {
                if (self::isTrue($answer[self::CORRECT] ?? '')) {
                    $correct = $words[$index] === 'true';
                }
            }

            if ($correct === null) {
                throw new \RuntimeException('it marks no correct answer');
            }

            return [
                ['type' => 'true_false', 'definition' => ['prompt' => $prompt, 'correct' => $correct]],
                'a two option true or false question became the free true/false type',
            ];
        }

        $options = self::options($answers);
        $correct = null;

        foreach ($answers as $index => $answer) {
            if (self::isTrue($answer[self::CORRECT] ?? '')) {
                $correct = $options[$index]['key'];
                break;
            }
        }

        if ($correct === null) {
            throw new \RuntimeException('it marks no correct answer');
        }

        return [
            ['type' => $free, 'definition' => ['prompt' => $prompt, 'options' => $options, 'correct' => $correct]],
            null,
        ];
    }

    /**
     * @param list<array<string, string>> $answers
     *
     * @return array<string, mixed>
     */
    private static function multiple(string $prompt, array $answers): array
    {
        $options = self::options($answers);
        $correct = [];

        foreach ($answers as $index => $answer) {
            if (self::isTrue($answer[self::CORRECT] ?? '')) {
                $correct[] = $options[$index]['key'];
            }
        }

        if ($correct === []) {
            throw new \RuntimeException('it marks no correct answer');
        }

        return ['prompt' => $prompt, 'options' => $options, 'correct' => $correct];
    }

    /**
     * ProQuiz keeps every accepted answer of a free answer question in one
     * text, one per line.
     *
     * @param list<array<string, string>> $answers
     *
     * @return array<string, mixed>
     */
    private static function free(string $prompt, array $answers): array
    {
        $accepted = [];

        foreach ($answers as $answer) {
            $lines = preg_split('/\R/', $answer[self::ANSWER] ?? '');

            foreach (\is_array($lines) ? $lines : [] as $line) {
                // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
                $line = trim(strip_tags($line));

                if ($line !== '' && !\in_array($line, $accepted, true)) {
                    $accepted[] = $line;
                }
            }
        }

        if ($accepted === []) {
            throw new \RuntimeException('it accepts no answer');
        }

        return ['prompt' => $prompt, 'accepted' => $accepted, 'case_sensitive' => false];
    }

    /**
     * A sorting question. ProQuiz stores the answers in the correct order and
     * shuffles them when the learner sees them, so the stored order is the key.
     *
     * @param list<array<string, string>> $answers
     *
     * @return array<string, mixed>
     */
    private static function sorted(string $prompt, array $answers): array
    {
        $items = self::options($answers);

        if (\count($items) < 2) {
            throw new \RuntimeException('it has fewer than two items');
        }

        return ['prompt' => $prompt, 'items' => $items, 'correct' => array_column($items, 'key')];
    }

    /**
     * A cloze question. The gaps are written inside the answer text as
     * `{one|two}`, and the free build's marker is `[[key]]`.
     *
     * @param list<array<string, string>> $answers
     *
     * @return array<string, mixed>
     */
    private static function cloze(string $prompt, array $answers): array
    {
        $text = $answers === [] ? '' : ($answers[0][self::ANSWER] ?? '');
        $blanks = [];
        $index = 0;

        $sentence = preg_replace_callback(
            '/\{([^{}]*)\}/',
            static function (array $match) use (&$blanks, &$index): string {
                $accepted = [];

                foreach (explode('|', $match[1]) as $option) {
                    // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
                    $option = trim(strip_tags($option));

                    if ($option !== '' && !\in_array($option, $accepted, true)) {
                        $accepted[] = $option;
                    }
                }

                if ($accepted === []) {
                    return $match[0];
                }

                $index++;
                $key = self::BLANK . $index;
                $blanks[] = ['key' => $key, 'accepted' => $accepted];

                return '[[' . $key . ']]';
            },
            $text,
        );

        if ($blanks === [] || !\is_string($sentence)) {
            throw new \RuntimeException('it has no gap with an answer in it');
        }

        return [
            'prompt' => trim($prompt . "\n" . $sentence),
            'blanks' => $blanks,
            'case_sensitive' => false,
        ];
    }

    /**
     * The answers as keyed options. The keys come from position, which is
     * stable for as long as the source rows are: a reordered answer list
     * re-imports as a changed question, exactly as it would in the authoring
     * UI.
     *
     * @param list<array<string, string>> $answers
     *
     * @return list<array{key: string, label: string}>
     */
    private static function options(array $answers): array
    {
        $options = [];
        $index = 0;

        foreach ($answers as $answer) {
            $index++;
            $label = trim($answer[self::ANSWER] ?? '');
            $options[] = ['key' => 'o' . $index, 'label' => $label === '' ? 'o' . $index : $label];
        }

        if (\count($options) < 2) {
            throw new \RuntimeException('it has fewer than two options');
        }

        return $options;
    }

    /**
     * The serialised answer list as scalar maps, with no object anywhere
     * reconstructed.
     *
     * @return list<array<string, string>>
     */
    public static function answers(mixed $answerData): array
    {
        $decoded = WordPressSource::decode(\is_string($answerData) ? $answerData : '');

        if (!\is_array($decoded)) {
            return [];
        }

        $answers = [];

        /** @var mixed $entry */
        foreach ($decoded as $entry) {
            $flat = WordPressSource::flatten($entry);

            if ($flat !== []) {
                $answers[] = $flat;
            }
        }

        return $answers;
    }

    private static function isTrue(string $value): bool
    {
        $value = strtolower(trim($value));

        return $value !== '' && $value !== '0' && $value !== 'false' && $value !== 'no';
    }
}
