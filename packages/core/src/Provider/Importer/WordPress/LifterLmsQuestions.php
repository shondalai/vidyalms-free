<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\QuestionDraft;

/**
 * One LifterLMS question post and its choices, translated into a free question
 * type.
 *
 * ## The schema this file was written from
 *
 * A question is an `llms_question` post parented on its quiz, ordered by
 * `menu_order`, with `_llms_question_type` naming the type, `_llms_points`
 * carrying its marks, `_llms_multi_choices` set when a choice question accepts
 * several answers, and one repeated `_llms_choice` meta row per choice, each a
 * serialised array carrying `id`, `question_id`, `choice`, `choice_type`,
 * `correct` and `marker`. **Unverified against a real install.**
 *
 * ## The one mapping a site owner must check by hand
 *
 * LifterLMS's fill in the blank question keeps the sentence in the post
 * content and the accepted answers in its choices, and the convention for
 * where the gap goes has changed across versions. This importer looks for a
 * run of three or more underscores and, failing that, puts the gap at the end
 * of the sentence, exactly as the shipped Community Quiz importer does with
 * its own uncertain template. The free build's marker is `[[b1]]`, so what the
 * learner sees is visible in the editor and correctable in one place.
 */
final class LifterLmsQuestions
{
    public const TYPES = [
        'choice' => 'single_choice',
        'true_false' => 'true_false',
        'blank' => 'fill_blank',
        'reorder' => 'ordering',
        'short_answer' => 'short_answer',
        'long_answer' => 'short_answer',
    ];

    /** The types LifterLMS grades by hand and stores no answer for. */
    private const HUMAN_GRADED = ['short_answer', 'long_answer'];

    /** The choice fields this reads. */
    private const CHOICE = 'choice';
    private const CORRECT = 'correct';

    /** The key one gap is given. */
    private const BLANK = 'b1';

    private function __construct()
    {
    }

    public static function supports(string $questionType): bool
    {
        return isset(self::TYPES[$questionType]);
    }

    /**
     * @param array<string, mixed>        $row       the `llms_question` post row
     * @param array<string, string>       $meta      its post meta
     * @param list<array<string, string>> $choices   its `_llms_choice` rows, in order
     *
     * @throws \RuntimeException when the row cannot produce a valid question
     */
    public static function map(array $row, array $meta, array $choices): QuestionDraft
    {
        $type = WordPressSource::text($meta[LifterLmsSource::META_QUESTION_TYPE] ?? null);
        $free = self::TYPES[$type] ?? throw new \RuntimeException(
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            sprintf('question type "%s" has no free equivalent', $type),
        );

        $prompt = WordPressSource::text($row['post_title'] ?? null);
        $body = WordPressSource::text($row['post_content'] ?? null);

        if ($prompt === '') {
            $prompt = $body;
        }

        if ($prompt === '') {
            throw new \RuntimeException('it asks nothing');
        }

        $points = WordPressSource::id($meta[LifterLmsSource::META_POINTS] ?? null);
        $sourceId = WordPressSource::text($row['ID'] ?? null);
        $multiple = self::isYes(WordPressSource::text($meta[LifterLmsSource::META_MULTI_CHOICES] ?? null));
        $note = null;

        if ($type === 'choice' && $multiple) {
            $free = 'multiple_choice';
        }

        $definition = match ($type) {
            'choice' => $multiple ? self::multiple($prompt, $choices) : self::single($prompt, $choices),
            'true_false' => self::trueFalse($prompt, $choices),
            'blank' => self::blank($prompt, $body, $choices),
            'reorder' => self::sorted($prompt, $choices),
            // Exhaustive over TYPES: what is left is the two LifterLMS grades
            // by hand. Neither carries an answer to read, so the accepted list
            // is empty and the intent is stated rather than left to be
            // inferred: a short answer with no matcher is a question for a
            // human grader, and saying so is what stops it being marked wrong
            // by a machine.
            default => ['prompt' => $prompt, 'accepted' => [], 'scoring_intent' => 'human'],
        };

        if (\in_array($type, self::HUMAN_GRADED, true)) {
            $note = 'a LifterLMS question graded by hand became a short answer needing a human grader, because'
                . ' LifterLMS stores no correct answer for one';
        }

        if ($type === 'blank') {
            $note = 'a fill in the blank sentence had its gap marked with [[' . self::BLANK . ']], which is worth'
                . ' checking against the original';
        }

        return QuestionDraft::of($sourceId, $free, $definition, $points > 0 ? (float) $points : 1.0, $note);
    }

    /**
     * @param list<array<string, string>> $choices
     *
     * @return array<string, mixed>
     */
    private static function single(string $prompt, array $choices): array
    {
        $options = self::options($choices);

        foreach ($choices as $index => $choice) {
            if (self::isYes($choice[self::CORRECT] ?? '')) {
                return ['prompt' => $prompt, 'options' => $options, 'correct' => $options[$index]['key']];
            }
        }

        throw new \RuntimeException('it marks no correct choice');
    }

    /**
     * @param list<array<string, string>> $choices
     *
     * @return array<string, mixed>
     */
    private static function multiple(string $prompt, array $choices): array
    {
        $options = self::options($choices);
        $correct = [];

        foreach ($choices as $index => $choice) {
            if (self::isYes($choice[self::CORRECT] ?? '')) {
                $correct[] = $options[$index]['key'];
            }
        }

        if ($correct === []) {
            throw new \RuntimeException('it marks no correct choice');
        }

        return ['prompt' => $prompt, 'options' => $options, 'correct' => $correct];
    }

    /**
     * @param list<array<string, string>> $choices
     *
     * @return array<string, mixed>
     */
    private static function trueFalse(string $prompt, array $choices): array
    {
        foreach ($choices as $choice) {
            if (!self::isYes($choice[self::CORRECT] ?? '')) {
                continue;
            }

            // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            $word = strtolower(trim(strip_tags($choice[self::CHOICE] ?? '')));

            if ($word === 'true' || $word === 'false') {
                return ['prompt' => $prompt, 'correct' => $word === 'true'];
            }
        }

        throw new \RuntimeException('it says neither true nor false');
    }

    /**
     * @param list<array<string, string>> $choices
     *
     * @return array<string, mixed>
     */
    private static function blank(string $prompt, string $body, array $choices): array
    {
        $accepted = [];

        foreach ($choices as $choice) {
            // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
            $text = trim(strip_tags($choice[self::CHOICE] ?? ''));

            if ($text !== '' && !\in_array($text, $accepted, true)) {
                $accepted[] = $text;
            }
        }

        if ($accepted === []) {
            throw new \RuntimeException('it accepts no answer');
        }

        $marker = '[[' . self::BLANK . ']]';
        $sentence = $body === '' ? $prompt : $body;
        $replaced = preg_replace('/_{3,}/', $marker, $sentence, 1, $count);
        $sentence = \is_string($replaced) && $count === 1 ? $replaced : rtrim($sentence) . ' ' . $marker;

        return [
            'prompt' => $prompt === $sentence ? $sentence : trim($prompt . "\n" . $sentence),
            'blanks' => [['key' => self::BLANK, 'accepted' => $accepted]],
            'case_sensitive' => false,
        ];
    }

    /**
     * @param list<array<string, string>> $choices
     *
     * @return array<string, mixed>
     */
    private static function sorted(string $prompt, array $choices): array
    {
        $items = self::options($choices);

        return ['prompt' => $prompt, 'items' => $items, 'correct' => array_column($items, 'key')];
    }

    /**
     * @param list<array<string, string>> $choices
     *
     * @return list<array{key: string, label: string}>
     */
    private static function options(array $choices): array
    {
        $options = [];
        $index = 0;

        foreach ($choices as $choice) {
            $index++;
            $label = trim($choice[self::CHOICE] ?? '');

            if ($label === '') {
                throw new \RuntimeException('one of its choices is a picture rather than text');
            }

            $options[] = ['key' => 'o' . $index, 'label' => $label];
        }

        if (\count($options) < 2) {
            throw new \RuntimeException('it has fewer than two choices');
        }

        return $options;
    }

    private static function isYes(string $value): bool
    {
        $value = strtolower(trim($value));

        return $value === 'yes' || $value === '1' || $value === 'true';
    }
}
