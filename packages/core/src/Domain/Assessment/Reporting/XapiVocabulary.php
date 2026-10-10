<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting;

/**
 * The fixed xAPI vocabulary the core emits, and nothing else.
 *
 * Every IRI here is either an ADL registry term or a URN in the vidyalms
 * namespace. A URN is used wherever the term is ours, because the core has
 * no site origin to build an http IRI from and inventing one would publish
 * a URL that resolves to nothing on every install.
 *
 * The ten interaction tokens are a closed set declared by the type through
 * ReportingMetadataInterface. They are listed here so a token a provider
 * invents cannot reach a statement: an unrecognised value is reported as
 * "other", which is what the design says a type with no fitting token gets.
 */
final class XapiVocabulary
{
    public const VERB_ATTEMPTED = 'http://adlnet.gov/expapi/verbs/attempted';
    public const VERB_ANSWERED = 'http://adlnet.gov/expapi/verbs/answered';
    public const VERB_COMPLETED = 'http://adlnet.gov/expapi/verbs/completed';
    public const VERB_PASSED = 'http://adlnet.gov/expapi/verbs/passed';
    public const VERB_FAILED = 'http://adlnet.gov/expapi/verbs/failed';

    public const ACTIVITY_ASSESSMENT = 'http://adlnet.gov/expapi/activities/assessment';
    public const ACTIVITY_INTERACTION = 'http://adlnet.gov/expapi/activities/cmi.interaction';
    public const ACTIVITY_COURSE = 'http://adlnet.gov/expapi/activities/course';

    /** The QTI answered status, which xAPI has no equivalent for. */
    public const EXTENSION_ANSWERED_STATUS = 'urn:vidyalms:xapi:extension:answered-status';

    /** Whether the mark is a mark at all, so a registry miss is not read as a wrong answer. */
    public const EXTENSION_SCORE_STATUS = 'urn:vidyalms:xapi:extension:score-status';

    /** The QTI item session status, which is what pendingExternalScoring travels as. */
    public const EXTENSION_SESSION_STATUS = 'urn:vidyalms:xapi:extension:session-status';

    public const EXTENSION_ATTEMPT_NUMBER = 'urn:vidyalms:xapi:extension:attempt-number';

    /** The language tag of the ADL verb display strings, which are vocabulary and not site copy. */
    public const VERB_LANGUAGE = 'en-US';

    /** The account home page used when the site has published no URL of its own. */
    public const ANONYMOUS_HOME_PAGE = 'urn:vidyalms:site';

    public const TOKEN_TRUE_FALSE = 'true-false';
    public const TOKEN_CHOICE = 'choice';
    public const TOKEN_FILL_IN = 'fill-in';
    public const TOKEN_LONG_FILL_IN = 'long-fill-in';
    public const TOKEN_MATCHING = 'matching';
    public const TOKEN_PERFORMANCE = 'performance';
    public const TOKEN_SEQUENCING = 'sequencing';
    public const TOKEN_LIKERT = 'likert';
    public const TOKEN_NUMERIC = 'numeric';
    public const TOKEN_OTHER = 'other';

    private function __construct()
    {
    }

    /**
     * The ten tokens, in the order the design's table lists them.
     *
     * @return list<string>
     */
    public static function interactionTokens(): array
    {
        return [
            self::TOKEN_TRUE_FALSE,
            self::TOKEN_CHOICE,
            self::TOKEN_FILL_IN,
            self::TOKEN_LONG_FILL_IN,
            self::TOKEN_MATCHING,
            self::TOKEN_PERFORMANCE,
            self::TOKEN_SEQUENCING,
            self::TOKEN_LIKERT,
            self::TOKEN_NUMERIC,
            self::TOKEN_OTHER,
        ];
    }

    /**
     * The token, or "other" when a provider declared something outside the
     * closed set. VidyaLMS keys are snake_case and the tokens are hyphenated
     * and differently named, so this is a lookup and never a transformation.
     */
    public static function interactionToken(string $declared): string
    {
        return \in_array($declared, self::interactionTokens(), true) ? $declared : self::TOKEN_OTHER;
    }

    /** The display map of an ADL verb, keyed by its own language. */
    public static function verbDisplay(string $verb): string
    {
        return match ($verb) {
            self::VERB_ATTEMPTED => 'attempted',
            self::VERB_ANSWERED => 'answered',
            self::VERB_COMPLETED => 'completed',
            self::VERB_PASSED => 'passed',
            self::VERB_FAILED => 'failed',
            default => 'experienced',
        };
    }

    public static function quizActivity(int $quizId): string
    {
        return 'urn:vidyalms:quiz:' . $quizId;
    }

    public static function questionActivity(int $questionId): string
    {
        return 'urn:vidyalms:question:' . $questionId;
    }

    public static function courseActivity(int $courseId): string
    {
        return 'urn:vidyalms:course:' . $courseId;
    }

    /**
     * A statement id and a registration are both UUIDs, and both have to be
     * the same UUID every time the same occurrence is rebuilt: an LRS
     * deduplicates on the statement id, so a rebuild that invented a new one
     * would store the same fact twice.
     *
     * Version 5 over a fixed namespace string, which is deterministic,
     * needs no randomness port and cannot collide across two occurrences
     * that differ by a single character.
     */
    public static function uuid(string $seed): string
    {
        $hash = sha1('urn:vidyalms:xapi:' . $seed);

        return sprintf(
            '%s-%s-5%s-%04x-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            (hexdec(substr($hash, 16, 4)) & 0x3FFF) | 0x8000,
            substr($hash, 20, 12),
        );
    }

    /**
     * An ISO 8601 duration in whole seconds, which is the precision the
     * attempt row stores. Negative and absent are both "no duration".
     */
    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds < 0) {
            return null;
        }

        return 'PT' . $seconds . 'S';
    }

    /**
     * The xAPI timestamp format: ISO 8601 in UTC with a Z designator.
     */
    public static function timestamp(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
