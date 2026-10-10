<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\QuestionDraft;

/**
 * One Tutor LMS question and its answers, translated into a free question type.
 *
 * ## The schema this file was written from
 *
 * `tutor_quiz_questions` carries `question_id`, `quiz_id`, `question_title`,
 * `question_description`, `question_type`, `question_mark` and
 * `question_order`; `tutor_quiz_question_answers` carries `answer_id`,
 * `belongs_question_id`, `answer_title`, `is_correct`,
 * `answer_two_gap_match` and `answer_order`. The `question_type` vocabulary is
 * `true_false`, `single_choice`, `multiple_choice`, `open_ended`,
 * `fill_in_the_blank`, `short_answer`, `matching`, `image_matching`,
 * `image_answering`, `ordering` and `h5p`. A fill in the blank question writes
 * its sentence in `answer_title` with `{dash}` where each gap goes and its
 * answers in `answer_two_gap_match`, separated by a vertical bar.
 * **Unverified against a real install.**
 *
 * ## The two that come across as human graded, deliberately
 *
 * `open_ended` and `short_answer` store no correct answer in Tutor, because
 * Tutor grades them by hand. They land on the free short answer type with no
 * accepted answers, which is exactly what that type calls a question needing a
 * human grader. Nothing is invented and nothing is auto-marked wrong.
 *
 * `matching`, `image_matching`, `image_answering` and `h5p` have no free home
 * and are skipped with a row problem naming the Tutor type word.
 */
final class TutorLmsQuestions
{
    public const TYPES = [
        'true_false' => 'true_false',
        'single_choice' => 'single_choice',
        'multiple_choice' => 'multiple_choice',
        'open_ended' => 'short_answer',
        'short_answer' => 'short_answer',
        'fill_in_the_blank' => 'fill_blank',
        'ordering' => 'ordering',
    ];

    /** The types Tutor grades by hand and stores no answer for. */
    private const HUMAN_GRADED = ['open_ended', 'short_answer'];

    /** What Tutor writes where a gap goes. */
    public const GAP = '{dash}';

    /** The key one gap is given, numbered from one. */
    private const BLANK = 'b';

    private function __construct()
    {
    }

    public static function supports(string $questionType): bool
    {
        return isset(self::TYPES[$questionType]);
    }

    /**
     * @param array<string, mixed>       $row     a `tutor_quiz_questions` row
     * @param list<array<string, mixed>> $answers its `tutor_quiz_question_answers` rows, in order
     *
     * @throws \RuntimeException when the row cannot produce a valid question
     */
    public static function map(array $row, array $answers): QuestionDraft
    {
        $type = WordPressSource::text($row['question_type'] ?? null);
        $free = self::TYPES[$type] ?? throw new \RuntimeException(
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            sprintf('question type "%s" has no free equivalent', $type),
        );

        $prompt = WordPressSource::text($row['question_title'] ?? null);

        if ($prompt === '') {
            $prompt = WordPressSource::text($row['question_description'] ?? null);
        }

        if ($prompt === '') {
            throw new \RuntimeException('it asks nothing');
        }

        $points = is_numeric($row['question_mark'] ?? null) ? (float) $row['question_mark'] : 1.0;
        $sourceId = WordPressSource::text($row['question_id'] ?? null);
        $definition = match ($type) {
            'true_false' => self::trueFalse($prompt, $answers),
            'single_choice' => self::single($prompt, $answers),
            'multiple_choice' => self::multiple($prompt, $answers),
            'fill_in_the_blank' => self::blanks($prompt, $answers),
            'ordering' => self::sorted($prompt, $answers),
            // Exhaustive over TYPES: what is left is the two Tutor grades by
            // hand. Neither carries an answer to read, so the accepted list is
            // empty and the intent is stated rather than left to be inferred:
            // a short answer with no matcher is a question for a human grader,
            // and saying so is what stops it being marked wrong by a machine.
            default => ['prompt' => $prompt, 'accepted' => [], 'scoring_intent' => 'human'],
        };

        $note = \in_array($type, self::HUMAN_GRADED, true)
            ? 'a Tutor question graded by hand became a short answer needing a human grader, because Tutor stores no'
                . ' correct answer for one'
            : null;

        return QuestionDraft::of($sourceId, $free, $definition, $points, $note);
    }

    /**
     * @param list<array<string, mixed>> $answers
     *
     * @return array<string, mixed>
     */
    private static function trueFalse(string $prompt, array $answers): array
    {
        foreach ($answers as $answer) {
            if (!self::isCorrect($answer)) {
                continue;
            }

            // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            $word = strtolower(trim(strip_tags(WordPressSource::text($answer['answer_title'] ?? null))));

            if ($word === 'true' || $word === 'false') {
                return ['prompt' => $prompt, 'correct' => $word === 'true'];
            }
        }

        throw new \RuntimeException('it says neither true nor false');
    }

    /**
     * @param list<array<string, mixed>> $answers
     *
     * @return array<string, mixed>
     */
    private static function single(string $prompt, array $answers): array
    {
        $options = self::options($answers);

        foreach ($answers as $index => $answer) {
            if (self::isCorrect($answer)) {
                return ['prompt' => $prompt, 'options' => $options, 'correct' => $options[$index]['key']];
            }
        }

        throw new \RuntimeException('it marks no correct answer');
    }

    /**
     * @param list<array<string, mixed>> $answers
     *
     * @return array<string, mixed>
     */
    private static function multiple(string $prompt, array $answers): array
    {
        $options = self::options($answers);
        $correct = [];

        foreach ($answers as $index => $answer) {
            if (self::isCorrect($answer)) {
                $correct[] = $options[$index]['key'];
            }
        }

        if ($correct === []) {
            throw new \RuntimeException('it marks no correct answer');
        }

        return ['prompt' => $prompt, 'options' => $options, 'correct' => $correct];
    }

    /**
     * A fill in the blank question. Tutor writes the sentence in the answer
     * title with `{dash}` at every gap and the answers, in the same order,
     * separated by a vertical bar.
     *
     * @param list<array<string, mixed>> $answers
     *
     * @return array<string, mixed>
     */
    private static function blanks(string $prompt, array $answers): array
    {
        if ($answers === []) {
            throw new \RuntimeException('it has no sentence to fill in');
        }

        $sentence = WordPressSource::text($answers[0]['answer_title'] ?? null);
        $filled = [];

        foreach (explode('|', WordPressSource::text($answers[0]['answer_two_gap_match'] ?? null)) as $value) {
            // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            $value = trim(strip_tags($value));

            if ($value !== '') {
                $filled[] = $value;
            }
        }

        if ($filled === [] || !str_contains($sentence, self::GAP)) {
            throw new \RuntimeException('it has no gap with an answer in it');
        }

        $blanks = [];
        $index = 0;

        while (str_contains($sentence, self::GAP) && $index < \count($filled)) {
            $key = self::BLANK . ($index + 1);
            $blanks[] = ['key' => $key, 'accepted' => [$filled[$index]]];
            $sentence = self::replaceFirst($sentence, self::GAP, '[[' . $key . ']]');
            $index++;
        }

        if (str_contains($sentence, self::GAP)) {
            throw new \RuntimeException('it has more gaps than answers');
        }

        return [
            'prompt' => trim($prompt . "\n" . $sentence),
            'blanks' => $blanks,
            'case_sensitive' => false,
        ];
    }

    /**
     * @param list<array<string, mixed>> $answers
     *
     * @return array<string, mixed>
     */
    private static function sorted(string $prompt, array $answers): array
    {
        $items = self::options($answers);

        return ['prompt' => $prompt, 'items' => $items, 'correct' => array_column($items, 'key')];
    }

    /**
     * @param list<array<string, mixed>> $answers
     *
     * @return list<array{key: string, label: string}>
     */
    private static function options(array $answers): array
    {
        $options = [];
        $index = 0;

        foreach ($answers as $answer) {
            $index++;
            $label = WordPressSource::text($answer['answer_title'] ?? null);
            $options[] = ['key' => 'o' . $index, 'label' => $label === '' ? 'o' . $index : $label];
        }

        if (\count($options) < 2) {
            throw new \RuntimeException('it has fewer than two options');
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $answer
     */
    private static function isCorrect(array $answer): bool
    {
        $value = WordPressSource::text($answer['is_correct'] ?? null);

        return $value !== '' && $value !== '0';
    }

    private static function replaceFirst(string $subject, string $search, string $replacement): string
    {
        $at = strpos($subject, $search);

        return $at === false
            ? $subject
            : substr($subject, 0, $at) . $replacement . substr($subject, $at + \strlen($search));
    }
}
