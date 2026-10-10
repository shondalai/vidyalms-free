<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Moodle;

use Shondalai\VidyaLMS\Core\Infrastructure\Xml\XmlNode;

/**
 * Translates one Moodle question element into a VidyaLMS question type and
 * definition.
 *
 * ## What maps, and what does not
 *
 * | Moodle       | VidyaLMS        | cost                                    |
 * |--------------|-----------------|-----------------------------------------|
 * | multichoice  | single_choice   | when single=1                           |
 * | multichoice  | multiple_choice | when single=0                           |
 * | truefalse    | true_false      | none                                    |
 * | shortanswer  | short_answer    | answer wildcards stay literal text      |
 * | numerical    | short_answer    | numeric tolerance is dropped            |
 * | essay        | short_answer    | graded by hand, as in Moodle            |
 *
 * Everything else (match, cloze, drag and drop, calculated, gapselect,
 * description, random) is refused with the qtype named, because a wrong
 * import of a question is worse than a missing one: a learner would be graded
 * against it.
 *
 * Option keys are the Moodle answer ids. They are meaningless to a learner
 * (the UI shows labels) and stable across re-exports of the same question,
 * which is what a second import needs to update rather than duplicate.
 */
final class MoodleQuestionMapper
{
    private const TYPE_SINGLE_CHOICE = 'single_choice';
    private const TYPE_MULTIPLE_CHOICE = 'multiple_choice';
    private const TYPE_TRUE_FALSE = 'true_false';
    private const TYPE_SHORT_ANSWER = 'short_answer';

    /** Moodle's "any other answer" catch-all, which has no equivalent here. */
    private const CATCH_ALL = '*';

    public function map(XmlNode $question): MoodleQuestionMapping
    {
        $qtype = $question->text('qtype');
        $prompt = $question->text('questiontext') ?? $question->text('name');

        if ($prompt === null) {
            return MoodleQuestionMapping::skip('it carries no question text');
        }

        $feedback = $question->text('generalfeedback');

        return match ($qtype) {
            'multichoice' => $this->multichoice($question, $prompt, $feedback),
            'truefalse' => $this->trueFalse($question, $prompt, $feedback),
            'shortanswer' => $this->shortAnswer($question, $prompt, $feedback),
            'numerical' => $this->numerical($question, $prompt, $feedback),
            'essay' => $this->essay($prompt, $feedback),
            default => MoodleQuestionMapping::skip(sprintf(
                'the Moodle question type "%s" has no VidyaLMS equivalent',
                $qtype ?? 'unknown',
            )),
        };
    }

    /**
     * The mark Moodle gave the question itself, used when the quiz slot does
     * not override it.
     */
    public function defaultMark(XmlNode $question): ?float
    {
        $mark = $question->float('defaultmark');

        return $mark !== null && $mark > 0.0 ? $mark : null;
    }

    private function multichoice(XmlNode $question, string $prompt, ?string $feedback): MoodleQuestionMapping
    {
        $plugin = $question->child('plugin_qtype_multichoice_question');
        $answers = $this->answers($plugin);

        if ($answers === []) {
            return MoodleQuestionMapping::skip('it has no answers');
        }

        $options = [];
        $correct = [];
        $best = null;

        foreach ($answers as $answer) {
            $options[] = ['key' => $answer['key'], 'label' => $answer['text']];

            if ($answer['fraction'] <= 0.0) {
                continue;
            }

            $correct[] = $answer['key'];

            if ($best === null || $answer['fraction'] > $best['fraction']) {
                $best = $answer;
            }
        }

        if ($correct === [] || $best === null) {
            return MoodleQuestionMapping::skip('none of its answers is marked correct');
        }

        $single = ($plugin?->child('multichoice')?->int('single') ?? 1) === 1;

        if (!$single) {
            return MoodleQuestionMapping::of(
                self::TYPE_MULTIPLE_CHOICE,
                $this->withFeedback(['prompt' => $prompt, 'options' => $options, 'correct' => $correct], $feedback),
            );
        }

        return MoodleQuestionMapping::of(
            self::TYPE_SINGLE_CHOICE,
            $this->withFeedback(['prompt' => $prompt, 'options' => $options, 'correct' => $best['key']], $feedback),
            \count($correct) > 1
                ? 'several answers carried partial credit, so only the highest scoring one is correct here'
                : null,
        );
    }

    private function trueFalse(XmlNode $question, string $prompt, ?string $feedback): MoodleQuestionMapping
    {
        $plugin = $question->child('plugin_qtype_truefalse_question');
        $answers = $this->answers($plugin);
        $trueId = $plugin?->child('truefalse')?->text('trueanswer');
        $correct = null;

        foreach ($answers as $answer) {
            if ($trueId !== null && $answer['id'] === $trueId) {
                $correct = $answer['fraction'] > 0.0;
                break;
            }
        }

        if ($correct === null) {
            foreach ($answers as $answer) {
                if (strcasecmp($this->plain($answer['text']), 'true') === 0) {
                    $correct = $answer['fraction'] > 0.0;
                    break;
                }
            }
        }

        if ($correct === null) {
            return MoodleQuestionMapping::skip('its true and false answers could not be told apart');
        }

        return MoodleQuestionMapping::of(
            self::TYPE_TRUE_FALSE,
            $this->withFeedback(['prompt' => $prompt, 'correct' => $correct], $feedback),
        );
    }

    private function shortAnswer(XmlNode $question, string $prompt, ?string $feedback): MoodleQuestionMapping
    {
        $plugin = $question->child('plugin_qtype_shortanswer_question');
        $accepted = $this->acceptedAnswers($plugin);

        if ($accepted === []) {
            return MoodleQuestionMapping::skip('it accepts no answer');
        }

        $definition = [
            'prompt' => $prompt,
            'accepted' => $accepted,
            'case_sensitive' => ($plugin?->child('shortanswer')?->int('usecase') ?? 0) === 1,
        ];

        $wildcards = false;

        foreach ($accepted as $answer) {
            $wildcards = $wildcards || str_contains($answer, self::CATCH_ALL);
        }

        return MoodleQuestionMapping::of(
            self::TYPE_SHORT_ANSWER,
            $this->withFeedback($definition, $feedback),
            $wildcards ? 'a Moodle answer wildcard was kept as literal text and will not match as it did there' : null,
        );
    }

    private function numerical(XmlNode $question, string $prompt, ?string $feedback): MoodleQuestionMapping
    {
        $accepted = $this->acceptedAnswers($question->child('plugin_qtype_numerical_question'));

        if ($accepted === []) {
            return MoodleQuestionMapping::skip('it accepts no answer');
        }

        $definition = ['prompt' => $prompt, 'accepted' => $accepted, 'case_sensitive' => false];

        return MoodleQuestionMapping::of(
            self::TYPE_SHORT_ANSWER,
            $this->withFeedback($definition, $feedback),
            'a Moodle numerical question became a short answer, so its accepted tolerance is gone',
        );
    }

    /**
     * An essay has no machine answer in Moodle either. A short answer with an
     * empty accepted list grades manually here, which is the same contract.
     */
    private function essay(string $prompt, ?string $feedback): MoodleQuestionMapping
    {
        return MoodleQuestionMapping::of(
            self::TYPE_SHORT_ANSWER,
            $this->withFeedback(['prompt' => $prompt, 'accepted' => [], 'case_sensitive' => false], $feedback),
            'a Moodle essay became a short answer awaiting manual grading',
        );
    }

    /**
     * @return list<string>
     */
    private function acceptedAnswers(?XmlNode $plugin): array
    {
        $accepted = [];

        foreach ($this->answers($plugin) as $answer) {
            $text = $this->plain($answer['text']);

            if ($answer['fraction'] <= 0.0 || $text === '' || $text === self::CATCH_ALL) {
                continue;
            }

            if (!\in_array($text, $accepted, true)) {
                $accepted[] = $text;
            }
        }

        return $accepted;
    }

    /**
     * @return list<array{id: string, key: string, text: string, fraction: float}>
     */
    private function answers(?XmlNode $plugin): array
    {
        $answers = [];

        if ($plugin === null) {
            return $answers;
        }

        foreach ($plugin->child('answers')?->children('answer') ?? [] as $index => $answer) {
            $id = $answer->attribute('id');
            $answers[] = [
                'id' => $id ?? '',
                'key' => $id !== null ? 'a' . $id : 'o' . $index,
                'text' => $answer->text('answertext') ?? '',
                'fraction' => $answer->float('fraction') ?? 0.0,
            ];
        }

        return $answers;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withFeedback(array $definition, ?string $feedback): array
    {
        if ($feedback !== null) {
            $definition['feedback'] = $feedback;
        }

        return $definition;
    }

    private function plain(string $html): string
    {
        // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
