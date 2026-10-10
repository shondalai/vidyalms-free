<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\GradeState;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiResponse;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiVocabulary;
use Shondalai\VidyaLMS\Core\Domain\Events\XapiStatementBuilt;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Cache\CachePort;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;

/**
 * The xAPI statements one attempt produces, built from the frozen manifest
 * and the append-only grade history.
 *
 * This is a free-build obligation rather than a paid feature: it records
 * what a conformant emitter must send. There is no LRS here, no endpoint, no
 * credential and no forwarding. Every statement is published as a domain
 * event, so a package that wants an LRS subscribes and posts what it is
 * handed, and the free build's behaviour is identical with and without one.
 *
 * **Nothing is derived from a live row.** The served set, the item points and
 * the presentation all come from the manifest the attempt froze, and the
 * marks come from attempt_grades. An instructor who reweights a question
 * after submission therefore cannot move what the statements report, which
 * is the same rule regrading already follows.
 *
 * **One statement per grade row, in the order they were written.** That is
 * what makes the design's manual-grading rule fall out rather than being
 * special-cased: an item awaiting a human is written once at submit with no
 * score and no success, and the human's mark is a second row, so it is a
 * second statement. A regrade is a third, for the same reason and with the
 * same honesty.
 */
final class BuildXapiStatements
{
    /** Where the statement ids one attempt has already published are kept. */
    public const PUBLISHED_KEY = 'vidyalms.xapi.published.';

    /** Long enough that a run of calls in one session publishes once. */
    public const PUBLISHED_TTL_SECONDS = 86400;

    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly QuizRepository $quizzes,
        private readonly AttemptManifest $manifest,
        private readonly QuestionTypes $types,
        private readonly CourseAccess $access,
        private readonly EventBus $events,
        /**
         * The one URL the core is given about its own site. Its origin is
         * the account home page an actor is identified against; a site that
         * published none is identified against the vidyalms namespace rather
         * than against an invented URL that resolves nowhere.
         */
        private readonly string $siteBaseUrl = '',
        private readonly LocalePort $locale = new FixedLocale(),
        private readonly ?CachePort $cache = null,
    ) {
    }

    /**
     * @param bool $publish whether to emit each statement as a domain event
     *
     * @return array{attempt_id: int, registration: string, statements: list<array<string, mixed>>,
     *               published: int}
     */
    public function execute(Principal $principal, int $attemptId, bool $publish = false): array
    {
        $this->access->requireSignedIn($principal);

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);

        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);

        $this->access->require($principal, $quiz->courseId, Capability::GRADE, Capability::VIEW_REPORTS);

        $statements = $this->statementsFor($attempt, $quiz);
        $documents = [];
        $published = 0;

        foreach ($statements as $statement) {
            if ($publish && $this->publish($statement, $attempt, $quiz, $attemptId)) {
                $published++;
            }

            $documents[] = $statement->toArray();
        }

        return [
            'attempt_id' => $attemptId,
            'registration' => $this->registration($attemptId),
            'statements' => $documents,
            'published' => $published,
        ];
    }

    /**
     * One statement onto the event bridge, at most once per statement id.
     *
     * The route this rides is a read, and it published every statement on
     * every call: a forty-question attempt fired forty-three host events per
     * GET, unthrottled, from a route any reports holder can call in a loop.
     * A deterministic statement id makes a re-post to an LRS harmless and
     * makes nothing about a re-dispatch to every host listener harmless, and
     * the core's own invariant is that a domain event is emitted once with a
     * stable idempotency key.
     *
     * So publishing is asked for rather than assumed, and the ids this
     * attempt has already published are remembered so asking twice emits
     * nothing the second time. The memory is best effort; the statement id is
     * the durable half and travels with every statement.
     */
    private function publish(XapiStatement $statement, Attempt $attempt, Quiz $quiz, int $attemptId): bool
    {
        $key = self::PUBLISHED_KEY . $attemptId;
        $seen = $this->cache?->get($key);
        $seen = \is_array($seen) ? array_filter($seen, '\is_string') : [];

        if (\in_array($statement->id, $seen, true)) {
            return false;
        }

        $this->events->dispatch(new XapiStatementBuilt(
            $statement->occurredAt,
            $attemptId,
            $quiz->id ?? 0,
            $quiz->courseId,
            $attempt->userId,
            $statement,
        ));

        $seen[] = $statement->id;
        $this->cache?->set($key, array_values($seen), self::PUBLISHED_TTL_SECONDS);

        return true;
    }

    /**
     * @return list<XapiStatement>
     */
    private function statementsFor(Attempt $attempt, Quiz $quiz): array
    {
        $attemptId = $attempt->id ?? 0;
        $served = [];

        foreach ($this->manifest->forAttempt($attempt) as $question) {
            $served[$question->questionId] = $question;
        }

        $answers = $this->attempts->answersFor($attemptId);
        $statements = [$this->attemptedStatement($attempt, $quiz)];

        foreach ($this->attempts->gradesFor($attemptId) as $grade) {
            if ($grade->questionId === null || !$grade->isRollUp()) {
                continue;
            }

            $statements[] = $this->answeredStatement(
                $attempt,
                $quiz,
                $grade,
                $served[$grade->questionId] ?? null,
                $answers[$grade->questionId] ?? null,
            );
        }

        return [...$statements, ...$this->closingStatements($attempt, $quiz)];
    }

    private function attemptedStatement(Attempt $attempt, Quiz $quiz): XapiStatement
    {
        $attemptId = $attempt->id ?? 0;

        return $this->statement(
            XapiVocabulary::uuid('attempt:' . $attemptId . ':attempted'),
            XapiVocabulary::VERB_ATTEMPTED,
            null,
            $this->quizActivity($quiz),
            null,
            $attempt->startedAt,
            $attempt,
            $quiz,
            [XapiVocabulary::EXTENSION_ATTEMPT_NUMBER => $attempt->attemptNumber],
        );
    }

    /**
     * The completed statement and the passed or failed one that follow it.
     *
     * Both are absent while the attempt is open, and the verdict is absent
     * while a human grade is outstanding: an attempt whose essay nobody has
     * read has no success to report, and reporting one from the auto-marked
     * half of the paper is exactly the failure the null percentage exists to
     * prevent.
     *
     * @return list<XapiStatement>
     */
    private function closingStatements(Attempt $attempt, Quiz $quiz): array
    {
        $submittedAt = $attempt->submittedAt;

        if (!$attempt->isSubmitted() || $submittedAt === null) {
            return [];
        }

        $attemptId = $attempt->id ?? 0;
        $duration = XapiVocabulary::duration($this->secondsSpent($attempt));
        $result = ['completion' => true];
        $score = $this->attemptScore($attempt);

        if ($score !== null) {
            $result['score'] = $score;
        }

        if ($duration !== null) {
            $result['duration'] = $duration;
        }

        $statements = [
            $this->statement(
                XapiVocabulary::uuid('attempt:' . $attemptId . ':completed'),
                XapiVocabulary::VERB_COMPLETED,
                null,
                $this->quizActivity($quiz),
                $result,
                $submittedAt,
                $attempt,
                $quiz,
            ),
        ];

        $passed = $attempt->passed;

        if ($passed === null) {
            return $statements;
        }

        $verdict = $result;
        $verdict['success'] = $passed;

        $statements[] = $this->statement(
            XapiVocabulary::uuid('attempt:' . $attemptId . ':' . ($passed ? 'passed' : 'failed')),
            $passed ? XapiVocabulary::VERB_PASSED : XapiVocabulary::VERB_FAILED,
            null,
            $this->quizActivity($quiz),
            $verdict,
            $submittedAt,
            $attempt,
            $quiz,
        );

        return $statements;
    }

    private function answeredStatement(
        Attempt $attempt,
        Quiz $quiz,
        AttemptGrade $grade,
        ?ServedQuestion $served,
        mixed $answer,
    ): XapiStatement {
        $questionId = $grade->questionId ?? 0;
        $scored = $served === null ? true : $served->scored;
        $token = $served === null
            ? XapiVocabulary::TOKEN_OTHER
            : $this->types->forServed($served)->xapiInteractionType($scored);

        $definition = [
            'type' => XapiVocabulary::ACTIVITY_INTERACTION,
            'interactionType' => $token,
        ];

        $prompt = $this->promptOf($served);

        if ($prompt !== null) {
            $definition['name'] = [$this->locale->current() => $prompt];
        }

        // The row id rather than the question id: attempt_grades is
        // append-only and a pending item, its human mark and any later
        // regrade are three separate occurrences that must not collapse
        // onto one statement id.
        $seed = 'attempt:' . ($attempt->id ?? 0) . ':answered:' . $questionId . ':' . ($grade->id ?? 0);

        return $this->statement(
            XapiVocabulary::uuid($seed),
            XapiVocabulary::VERB_ANSWERED,
            $questionId,
            [
                'objectType' => 'Activity',
                'id' => XapiVocabulary::questionActivity($questionId),
                'definition' => $definition,
            ],
            $this->itemResult($grade, $answer),
            $grade->gradedAt,
            $attempt,
            $quiz,
            [
                XapiVocabulary::EXTENSION_ANSWERED_STATUS => $grade->answeredStatus->value,
                XapiVocabulary::EXTENSION_SCORE_STATUS => $grade->scoreStatus->value,
                XapiVocabulary::EXTENSION_SESSION_STATUS => $grade->sessionStatus->value,
            ],
            $this->quizActivity($quiz),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function itemResult(AttemptGrade $grade, mixed $answer): array
    {
        $result = ['completion' => $grade->answeredStatus->hasResponse()];
        $response = XapiResponse::of($answer);

        if ($response !== null) {
            $result['response'] = $response;
        }

        // A row awaiting a human and a row that was never scored both report
        // no score and no success. Writing a zero for either would report a
        // learner as having got an item wrong when nobody has marked it, or
        // when the package that could mark it is not installed.
        if (!$grade->counts() || $grade->awaitsHuman()) {
            return $result;
        }

        $minimum = $grade->minScore ?? 0.0;

        $result['score'] = [
            'raw' => round($grade->pointsAwarded, 4),
            'min' => round($minimum, 4),
            'max' => round($grade->maxPoints, 4),
            'scaled' => self::scaled($grade->pointsAwarded, $minimum, $grade->maxPoints),
        ];
        $result['success'] = $grade->state === GradeState::Correct;

        return $result;
    }

    /**
     * @return array<string, float>|null
     */
    private function attemptScore(Attempt $attempt): ?array
    {
        $raw = $attempt->score;
        $maximum = $attempt->maxScore;

        if ($raw === null || $maximum === null) {
            return null;
        }

        return [
            'raw' => round($raw, 4),
            'min' => 0.0,
            'max' => round($maximum, 4),
            'scaled' => self::scaled($raw, 0.0, $maximum),
        ];
    }

    /**
     * The scaled score, which xAPI bounds to [-1, 1].
     *
     * Negative marks scale against the floor rather than against the
     * maximum, which is SCORM 2004's own rule and the only one that puts a
     * mark of minus two out of a floor of minus four at minus a half rather
     * than at some fraction of a maximum it never approached.
     */
    private static function scaled(float $raw, float $minimum, float $maximum): float
    {
        if ($raw < 0.0 && $minimum < 0.0) {
            return round(max(-1.0, $raw / abs($minimum)), 4);
        }

        if ($maximum <= 0.0) {
            return 0.0;
        }

        return round(max(-1.0, min(1.0, $raw / $maximum)), 4);
    }

    private function secondsSpent(Attempt $attempt): ?int
    {
        if ($attempt->timeSpentSeconds !== null) {
            return $attempt->timeSpentSeconds;
        }

        $submittedAt = $attempt->submittedAt;

        if ($submittedAt === null) {
            return null;
        }

        return max(0, $submittedAt->getTimestamp() - $attempt->startedAt->getTimestamp());
    }

    /**
     * @return array<string, mixed>
     */
    private function quizActivity(Quiz $quiz): array
    {
        return [
            'objectType' => 'Activity',
            'id' => XapiVocabulary::quizActivity($quiz->id ?? 0),
            'definition' => [
                'type' => XapiVocabulary::ACTIVITY_ASSESSMENT,
                'name' => [$this->locale->current() => $quiz->title],
            ],
        ];
    }

    /**
     * The prompt, and only the prompt.
     *
     * It is the one field presentable in every type of the catalogue, which
     * is why the registry-miss placeholder carries it too. Nothing else of a
     * definition may cross into a statement, because nothing else is known
     * not to carry correctness.
     */
    private function promptOf(?ServedQuestion $served): ?string
    {
        if ($served === null) {
            return null;
        }

        $prompt = $served->definition['prompt'] ?? null;

        return \is_string($prompt) && $prompt !== '' ? $prompt : null;
    }

    /**
     * @param array<string, mixed>      $object
     * @param array<string, mixed>|null $result
     * @param array<string, mixed>      $extensions
     * @param array<string, mixed>|null $parent
     */
    private function statement(
        string $id,
        string $verb,
        ?int $questionId,
        array $object,
        ?array $result,
        \DateTimeImmutable $timestamp,
        Attempt $attempt,
        Quiz $quiz,
        array $extensions = [],
        ?array $parent = null,
    ): XapiStatement {
        $context = [
            'registration' => $this->registration($attempt->id ?? 0),
            'contextActivities' => array_filter([
                'parent' => $parent === null ? null : [$parent],
                'grouping' => [[
                    'objectType' => 'Activity',
                    'id' => XapiVocabulary::courseActivity($quiz->courseId),
                    'definition' => ['type' => XapiVocabulary::ACTIVITY_COURSE],
                ]],
            ], static fn (mixed $value): bool => $value !== null),
        ];

        if ($extensions !== []) {
            $context['extensions'] = $extensions;
        }

        $document = [
            'id' => $id,
            'actor' => $this->actor($attempt->userId),
            'verb' => [
                'id' => $verb,
                'display' => [XapiVocabulary::VERB_LANGUAGE => XapiVocabulary::verbDisplay($verb)],
            ],
            'object' => $object,
            'context' => $context,
            'timestamp' => XapiVocabulary::timestamp($timestamp),
        ];

        if ($result !== null) {
            $document['result'] = $result;
        }

        return new XapiStatement($id, $verb, $questionId, $timestamp, $document);
    }

    /**
     * The learner, identified by the CMS user id and nothing else.
     *
     * An account rather than an mbox, deliberately: the core never joins
     * into CMS tables and an email address in a statement is personal data
     * leaving the site the moment a listener forwards it.
     *
     * @return array<string, mixed>
     */
    private function actor(int $userId): array
    {
        return [
            'objectType' => 'Agent',
            'account' => [
                'homePage' => $this->homePage(),
                'name' => (string) $userId,
            ],
        ];
    }

    private function homePage(): string
    {
        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $scheme = parse_url($this->siteBaseUrl, PHP_URL_SCHEME);
        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = parse_url($this->siteBaseUrl, PHP_URL_HOST);

        if (!\is_string($scheme) || !\is_string($host) || $scheme === '' || $host === '') {
            return XapiVocabulary::ANONYMOUS_HOME_PAGE;
        }

        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $port = parse_url($this->siteBaseUrl, PHP_URL_PORT);

        return $scheme . '://' . $host . (\is_int($port) ? ':' . $port : '');
    }

    private function registration(int $attemptId): string
    {
        return XapiVocabulary::uuid('attempt:' . $attemptId);
    }
}
