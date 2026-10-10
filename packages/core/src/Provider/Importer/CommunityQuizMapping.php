<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CompletionRule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;

/**
 * The translation between what Community Quiz stores and what the free
 * VidyaLMS providers accept. It reads rows and returns shapes; it writes
 * nothing and knows nothing about jobs, which is what makes every rule here
 * testable on its own.
 *
 * Two kinds of failure are distinguished, because they mean different things
 * to the person reading the job log:
 *
 * - the source type has no free equivalent (a hotspot question, a live
 *   session). The importer counts these per type and says how many need Pro.
 *   Ask supportsQuestion() / supportsLesson() first.
 * - the source type maps, but this particular row cannot produce a valid
 *   definition (a single-choice question with one option, a reading lesson
 *   with an empty body). map() throws with the reason, and the importer skips
 *   that one row.
 *
 * Where a mapping changes the meaning of something rather than losing it, the
 * returned "note" says so in the job log. Silence would be the bug.
 */
final class CommunityQuizMapping
{
    /** Community Quiz question type to the free type that carries it. */
    public const QUESTION_TYPES = [
        'mcq' => 'single_choice',
        'multi' => 'multiple_choice',
        'tf' => 'true_false',
        'yn' => 'true_false',
        'short' => 'short_answer',
        'essay' => 'short_answer',
        'blank' => 'fill_blank',
        'cloze' => 'fill_blank',
        'order' => 'ordering',
    ];

    /**
     * The legacy type name a row carries, as the preset source alias the rest
     * of the product knows it by.
     *
     * Two vocabularies for one thing was the defect: the rows say "multi" and
     * every preset alias says "cq:multiple", so the documented behaviour ("each
     * maps through a preset source_alias") could not happen and the two tables
     * spelled the same legacy types differently. The alias is the shared word;
     * the table below stays as the free build's own fallback for the six types
     * it can actually fill.
     *
     * The entries with no row in QUESTION_TYPES are the ones whose preset
     * compiles to a type Pro registers. They resolve to an alias, find a
     * preset on a Pro site and a target the free mapping cannot fill, and are
     * counted and named as skipped, which is what the integrations note
     * describes.
     *
     * @var array<string, string>
     */
    public const SOURCE_ALIASES = [
        'mcq' => 'cq:single',
        'single' => 'cq:single',
        'multi' => 'cq:multiple',
        'multiple' => 'cq:multiple',
        'tf' => 'cq:truefalse',
        'yn' => 'cq:truefalse',
        'truefalse' => 'cq:truefalse',
        'short' => 'cq:shortanswer',
        'shortanswer' => 'cq:shortanswer',
        'blank' => 'cq:fillblank',
        'fillblank' => 'cq:fillblank',
        'cloze' => 'cq:fillblank',
        'order' => 'cq:sequence',
        'sequence' => 'cq:sequence',
        'ranking' => 'cq:ranking',
        'essay' => 'cq:essay',
        'match' => 'cq:match',
        'likert' => 'cq:likert',
        'hotspot' => 'cq:hotspot',
        'matrix' => 'cq:matrix',
        'image_label' => 'cq:image_label',
        'draganddrop' => 'cq:draganddrop',
        'recorded_response' => 'cq:recorded_response',
    ];

    /** Community Quiz lesson type to the free lesson type that carries it. */
    public const LESSON_TYPES = [
        'reading' => 'reading',
        'video' => 'video',
        'quiz' => 'quiz',
        'assignment' => 'assignment',
        'resource' => 'reading',
    ];

    private const BLANK_KEY = 'blank1';

    private function __construct()
    {
    }

    public static function supportsQuestion(string $type): bool
    {
        return isset(self::QUESTION_TYPES[$type]);
    }

    /**
     * The preset source alias this legacy type name is known by, or null when
     * the legacy name is one nothing in the product has a word for.
     */
    public static function sourceAlias(string $type): ?string
    {
        return self::SOURCE_ALIASES[strtolower(trim($type))] ?? null;
    }

    public static function supportsLesson(string $type): bool
    {
        return isset(self::LESSON_TYPES[$type]);
    }

    /**
     * @param array<string, mixed> $row a cq_questions row
     *
     * @return array{type: string, definition: array<string, mixed>, points: float, note: string|null}
     *
     * @throws \RuntimeException when this row cannot produce a valid definition
     */
    public static function question(array $row, ?float $pointsOverride): array
    {
        $type = self::text($row['type'] ?? null);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $free = self::QUESTION_TYPES[$type] ?? throw new \RuntimeException(sprintf('question type "%s" has no free equivalent', $type));
        $content = self::decode($row['content'] ?? null);
        $prompt = self::text($row['stem'] ?? null);

        if ($prompt === '') {
            $prompt = self::text($row['title'] ?? null);
        }

        if ($prompt === '') {
            throw new \RuntimeException('the question has no stem');
        }

        $points = $pointsOverride ?? (float) ($row['points'] ?? 1.0);

        if ($points <= 0.0) {
            $points = 1.0;
        }

        [$definition, $note] = match ($type) {
            'mcq' => [self::singleChoice($prompt, $content), null],
            'multi' => [self::multipleChoice($prompt, $content), null],
            'tf' => [self::trueFalse($prompt, $content), null],
            'yn' => [
                self::trueFalse($prompt, $content),
                'its yes/no labels become true/false, which is the free build\'s only boolean type',
            ],
            'short' => [self::shortAnswer($prompt, $content), null],
            'essay' => [
                ['prompt' => $prompt, 'accepted' => []],
                'it becomes a manually graded short answer: the free build has no essay editor, word limits or rubric grading',
            ],
            'blank' => self::blank($prompt, $content),
            'cloze' => self::cloze($prompt, $content),
            // Exhaustive over QUESTION_TYPES, which the lookup above already
            // required this type to be a key of.
            'order' => [self::ordering($prompt, $content), null],
        };

        $feedback = self::text($row['feedback'] ?? null);

        if ($feedback !== '') {
            $definition['feedback'] = $feedback;
        }

        return ['type' => $free, 'definition' => QuestionText::definition($definition), 'points' => $points, 'note' => $note];
    }

    /**
     * @param array<string, mixed> $row     a cq_lessons row
     * @param int|null             $quizId  the imported quiz behind a quiz lesson
     *
     * @return array{type: string, content: array<string, mixed>, note: string|null}
     *
     * @throws \RuntimeException when this row cannot produce valid content
     */
    public static function lesson(array $row, ?int $quizId): array
    {
        $type = self::text($row['type'] ?? null);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $free = self::LESSON_TYPES[$type] ?? throw new \RuntimeException(sprintf('lesson type "%s" has no free equivalent', $type));
        $content = self::decode($row['content'] ?? null);

        return match ($type) {
            'reading' => ['type' => $free, 'content' => self::reading($content), 'note' => null],
            'video' => ['type' => $free, 'content' => self::video($content), 'note' => null],
            'assignment' => [
                'type' => $free,
                'content' => ['instructions' => self::text($content['prompt'] ?? null)],
                'note' => null,
            ],
            'quiz' => [
                'type' => $free,
                'content' => ['quiz_id' => $quizId ?? throw new \RuntimeException('its quiz was not imported')],
                'note' => null,
            ],
            // Exhaustive over LESSON_TYPES, which the lookup above already
            // required this type to be a key of.
            'resource' => [
                'type' => $free,
                'content' => self::resource($content, self::text($row['title'] ?? null)),
                'note' => 'it becomes a reading lesson linking to the original file: the free build'
                    . ' serves resources from its own private storage, which an import cannot copy into',
            ],
        };
    }

    /**
     * Community Quiz stores the completion gate as
     * {"rule": "all_lessons|ninety_pct|pass_final", ...}; the free build has
     * the same three shapes, with the percentage spelled as a threshold.
     *
     * @param array<string, mixed> $completion
     *
     * @return array{rule: CompletionRule, threshold: int|null}
     */
    public static function completion(array $completion): array
    {
        return match (self::text($completion['rule'] ?? null)) {
            'ninety_pct' => ['rule' => CompletionRule::Percentage, 'threshold' => 90],
            'pass_final' => ['rule' => CompletionRule::PassFinal, 'threshold' => null],
            default => ['rule' => CompletionRule::AllLessons, 'threshold' => null],
        };
    }

    /**
     * A course hidden from the Community Quiz catalogue lands unlisted rather
     * than public: it keeps working for anyone holding its link, and stays out
     * of the catalogue exactly as it did.
     *
     * @param array<string, mixed> $row a cq_courses row
     */
    public static function visibility(array $row): CourseVisibility
    {
        $visibility = CourseVisibility::tryFrom(self::text($row['visibility'] ?? null)) ?? CourseVisibility::Public;

        if ($visibility === CourseVisibility::Public && (int) ($row['show_in_catalog'] ?? 1) === 0) {
            return CourseVisibility::Unlisted;
        }

        return $visibility;
    }

    /**
     * Community Quiz durations are free text: "12:40" is minutes and seconds,
     * a bare number is minutes, and anything else is no duration at all.
     *
     * "Anything else" includes a number no clock could mean. The column takes
     * an unsigned 32 bit integer, and multiplying a twenty digit source value
     * by sixty overflows to a float, which a function returning ?int may not
     * return: that threw a TypeError, which no DomainException handler
     * catches, and one nonsense row ended the whole migration. A duration
     * VidyaLMS cannot store is no duration, exactly as "a while" is.
     */
    public static function durationSeconds(mixed $duration): ?int
    {
        $duration = self::text($duration);

        if ($duration === '') {
            return null;
        }

        if (preg_match('/^(\d+):([0-5]?\d)$/', $duration, $parts) === 1) {
            return self::seconds($parts[1], (int) $parts[2]);
        }

        $digits = preg_replace('/\D+/', '', $duration) ?? '';

        return $digits === '' ? null : self::seconds($digits, 0);
    }

    /**
     * A Community Quiz duration held in whole minutes, in seconds. Zero, a
     * negative, anything that is not a number and anything too large to store
     * all mean the same thing here: no limit.
     */
    public static function minutesAsSeconds(mixed $minutes): ?int
    {
        $total = self::seconds(self::text($minutes), 0);

        return $total === null || $total < 1 ? null : $total;
    }

    /**
     * That many minutes and seconds, or null when the minutes are not digits
     * or the total is more than the duration columns hold. The digits are
     * counted before they are multiplied, because the multiplication is what
     * overflows.
     *
     * The bound is Lesson::MAX_DURATION_SECONDS, which is the one the use
     * cases enforce: the lesson duration and the quiz time limit are both the
     * schema's "?uint", so there is one limit for both and the importer and
     * the use case cannot disagree about it.
     */
    private static function seconds(string $minutes, int $seconds): ?int
    {
        if (preg_match('/^\d+$/', $minutes) !== 1) {
            return null;
        }

        $minutes = ltrim($minutes, '0');

        if ($minutes === '') {
            return $seconds;
        }

        if (\strlen($minutes) > 10) {
            return null;
        }

        $total = ((int) $minutes * 60) + $seconds;

        return $total > Lesson::MAX_DURATION_SECONDS ? null : $total;
    }

    /**
     * The pass mark as a whole percentage of the quiz's own scale, which is
     * what the free build stores.
     *
     * @param array<string, mixed> $row a cq_quizzes row
     */
    public static function passMark(array $row): int
    {
        $scale = (float) ($row['scale_max'] ?? 100.0);
        $passMark = (float) ($row['pass_mark'] ?? 0.0);

        if ($scale <= 0.0 || $passMark <= 0.0) {
            return 0;
        }

        return max(0, min(100, (int) round($passMark / $scale * 100)));
    }

    /**
     * The free build knows two answers to "when may a learner see results":
     * on submission, or never. Community Quiz's third answer, "once the exam
     * closes", is scheduled release, and the safe reading of a schedule the
     * free build cannot keep is to withhold rather than to reveal early.
     *
     * @param array<string, mixed> $feedback the decoded cq_quizzes.feedback
     *
     * @return array{value: string, note: string|null}
     */
    public static function showResults(array $feedback): array
    {
        $mode = self::text($feedback['release_mode'] ?? null);

        if ($mode === 'never') {
            return ['value' => Quiz::SHOW_RESULTS_NEVER, 'note' => null];
        }

        if ($mode === 'after_close') {
            return [
                'value' => Quiz::SHOW_RESULTS_NEVER,
                'note' => 'released its results after the close date, which the free build cannot'
                    . ' schedule, so results are withheld instead',
            ];
        }

        if (($feedback['show_correct_answers'] ?? true) === false) {
            return [
                'value' => Quiz::SHOW_RESULTS_AFTER_SUBMIT,
                'note' => 'hid the answer key while still showing the score, a split the free build does'
                    . ' not have, so both are shown after submission',
            ];
        }

        return ['value' => Quiz::SHOW_RESULTS_AFTER_SUBMIT, 'note' => null];
    }

    /**
     * Decodes one of Community Quiz's JSON columns. A column that is null,
     * empty or not an object reads as no configuration at all, which is how
     * Community Quiz itself reads it.
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $value): array
    {
        if (\is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (!\is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    public static function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function reading(array $content): array
    {
        $body = self::text($content['body'] ?? null);

        if ($body === '') {
            // The Community Quiz builder wrote "html" before it wrote "body",
            // and CourseService still reads the older key.
            $body = self::text($content['html'] ?? null);
        }

        if ($body === '') {
            throw new \RuntimeException('the reading has no body');
        }

        return ['body' => $body];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function video(array $content): array
    {
        $url = self::text($content['source_url'] ?? null);

        if ($url === '') {
            $url = self::text($content['url'] ?? null);
        }

        if (preg_match('#^https?://#i', $url) !== 1) {
            throw new \RuntimeException('the video has no http URL');
        }

        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = strtolower((string) (parse_url($url, \PHP_URL_HOST) ?? ''));
        $provider = 'url';

        if (str_contains($host, 'youtube.') || str_contains($host, 'youtu.be')) {
            $provider = 'youtube';
        } elseif (str_contains($host, 'vimeo.')) {
            $provider = 'vimeo';
        }

        return ['provider' => $provider, 'url' => $url];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function resource(array $content, string $title): array
    {
        $url = self::text($content['resource_url'] ?? null);

        if ($url === '') {
            throw new \RuntimeException('the resource has no URL');
        }

        $label = $title !== '' ? $title : $url;

        return ['body' => sprintf(
            '<p><a href="%s" rel="noopener">%s</a></p>',
            htmlspecialchars($url, \ENT_QUOTES),
            htmlspecialchars($label, \ENT_QUOTES),
        )];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function singleChoice(string $prompt, array $content): array
    {
        $options = self::options($content);
        $answer = $content['answer'] ?? null;

        if (!is_numeric($answer) || !isset($options[(int) $answer])) {
            throw new \RuntimeException('its correct option is out of range');
        }

        return [
            'prompt' => $prompt,
            'options' => array_values($options),
            'correct' => $options[(int) $answer]['key'],
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function multipleChoice(string $prompt, array $content): array
    {
        $options = self::options($content);
        $answer = $content['answer'] ?? null;

        if (!\is_array($answer) || $answer === []) {
            throw new \RuntimeException('it marks no correct option');
        }

        $correct = [];

        foreach ($answer as $index) {
            if (!is_numeric($index) || !isset($options[(int) $index])) {
                throw new \RuntimeException('one of its correct options is out of range');
            }

            $key = $options[(int) $index]['key'];

            if (!\in_array($key, $correct, true)) {
                $correct[] = $key;
            }
        }

        return ['prompt' => $prompt, 'options' => array_values($options), 'correct' => $correct];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function trueFalse(string $prompt, array $content): array
    {
        if (!\array_key_exists('answer', $content)) {
            throw new \RuntimeException('it says neither true nor false');
        }

        return ['prompt' => $prompt, 'correct' => self::boolean($content['answer'])];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function shortAnswer(string $prompt, array $content): array
    {
        $accepted = self::acceptedAnswers($content['answers'] ?? null);

        if ($accepted === []) {
            throw new \RuntimeException('it accepts no answer');
        }

        return [
            'prompt' => $prompt,
            'accepted' => $accepted,
            'case_sensitive' => ($content['caseSensitive'] ?? false) === true,
        ];
    }

    /**
     * Community Quiz's single blank is a sentence with a gap in it. The gap is
     * written as underscores or as a {{placeholder}}; whichever it is, the free
     * build's [[key]] marker takes its place.
     *
     * @param array<string, mixed> $content
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function blank(string $prompt, array $content): array
    {
        $accepted = self::acceptedAnswers($content['answers'] ?? null);

        if ($accepted === []) {
            throw new \RuntimeException('it accepts no answer');
        }

        $marker = '[[' . self::BLANK_KEY . ']]';
        $template = self::text($content['template'] ?? null);
        $sentence = $marker;

        if ($template !== '') {
            $replaced = preg_replace('/_{2,}|\{\{\s*[A-Za-z0-9_-]+\s*\}\}/', $marker, $template, 1, $count);
            $sentence = \is_string($replaced) && $count === 1 ? $replaced : $template . ' ' . $marker;
        }

        return [
            [
                'prompt' => $prompt === $sentence ? $sentence : $prompt . "\n" . $sentence,
                'blanks' => [['key' => self::BLANK_KEY, 'accepted' => $accepted]],
                'case_sensitive' => ($content['caseSensitive'] ?? false) === true,
            ],
            null,
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private static function cloze(string $prompt, array $content): array
    {
        $blanks = $content['blanks'] ?? null;

        if (!\is_array($blanks) || $blanks === []) {
            throw new \RuntimeException('it has no blanks');
        }

        $template = self::text($content['template'] ?? null);
        $sentence = $template !== '' ? $template : $prompt;
        $mapped = [];
        $appended = 0;

        /** @var mixed $accepted */
        foreach ($blanks as $key => $accepted) {
            $key = (string) $key;

            if (preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('its blank "%s" is not a usable key', $key));
            }

            $answer = self::text($accepted);

            if ($answer === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('its blank "%s" accepts no answer', $key));
            }

            $marker = '[[' . $key . ']]';
            $placeholder = '/\{\{\s*' . preg_quote($key, '/') . '\s*\}\}/';
            $replaced = preg_replace($placeholder, $marker, $sentence, 1, $count);
            $sentence = \is_string($replaced) ? $replaced : $sentence;

            if ($count === 0 && !str_contains($sentence, $marker)) {
                $sentence .= ' ' . $marker;
                $appended++;
            }

            $mapped[] = ['key' => $key, 'accepted' => [$answer]];
        }

        return [
            [
                'prompt' => $sentence,
                'blanks' => $mapped,
                'case_sensitive' => false,
            ],
            $appended === 0 ? null : sprintf(
                '%d of its blanks had no placeholder in the passage and were appended to the end of it',
                $appended,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function ordering(string $prompt, array $content): array
    {
        $items = $content['items'] ?? null;

        if (!\is_array($items) || \count($items) < 2) {
            throw new \RuntimeException('it has fewer than two items');
        }

        $keyed = [];
        $index = 0;

        /** @var mixed $label */
        foreach ($items as $label) {
            $index++;
            $keyed[] = ['key' => 'i' . $index, 'label' => self::text($label)];
        }

        $answer = $content['answer'] ?? null;

        if (!\is_array($answer) || \count($answer) !== \count($keyed)) {
            throw new \RuntimeException('its correct order does not list every item');
        }

        $correct = [];

        foreach ($answer as $position) {
            if (!is_numeric($position) || !isset($keyed[(int) $position])) {
                throw new \RuntimeException('its correct order points at an item that is not there');
            }

            $key = $keyed[(int) $position]['key'];

            if (\in_array($key, $correct, true)) {
                throw new \RuntimeException('its correct order lists an item twice');
            }

            $correct[] = $key;
        }

        return ['prompt' => $prompt, 'items' => $keyed, 'correct' => $correct];
    }

    /**
     * Community Quiz options are a bare list, positional by nature. The keys
     * the free types need are derived from that position, which is stable for
     * as long as the source rows are: a reordered option list re-imports as a
     * changed question, exactly as it would in the authoring UI.
     *
     * @param array<string, mixed> $content
     *
     * @return list<array{key: string, label: string}>
     */
    private static function options(array $content): array
    {
        $options = $content['options'] ?? null;

        if (!\is_array($options) || \count($options) < 2) {
            throw new \RuntimeException('it has fewer than two options');
        }

        $keyed = [];
        $index = 0;

        /** @var mixed $label */
        foreach ($options as $label) {
            $index++;
            $keyed[] = ['key' => 'o' . $index, 'label' => self::text($label)];
        }

        return $keyed;
    }

    /**
     * @return list<string>
     */
    private static function acceptedAnswers(mixed $answers): array
    {
        if (!\is_array($answers)) {
            return [];
        }

        $accepted = [];

        /** @var mixed $answer */
        foreach ($answers as $answer) {
            $text = self::text($answer);

            if ($text !== '') {
                $accepted[] = $text;
            }
        }

        return $accepted;
    }

    /**
     * The same coercion Community Quiz grades with, so a "yes" stored as the
     * string "1" survives the move.
     */
    private static function boolean(mixed $value): bool
    {
        if (\is_string($value)) {
            return \in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
