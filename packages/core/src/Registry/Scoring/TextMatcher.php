<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

/**
 * One entry of an ordered matcher list. First match wins, and each entry
 * carries its own fraction and feedback id.
 *
 * Case sensitivity is a property of the matcher and of nothing else. One
 * concept previously had four controls with no stated precedence, and both
 * readings were defensible while disagreeing: if a normalisation pipeline
 * folds first, "exact" becomes indistinguishable from "exact_ci" and an item
 * asking for the symbol for lead accepts "PB"; if the matcher kind wins, a
 * question-level case-fold flag has no effect on exact matchers and the flag
 * is a lie. So exact never folds, exact_ci always folds, and a
 * question-level fold applies only to the kinds that do not fix the
 * behaviour themselves.
 *
 * A wildcard is escaped before compilation and never concatenated into a
 * pattern. An author-supplied regex is untrusted input to a grading
 * endpoint: the flavour is pinned to PCRE with the u modifier, anchoring is
 * explicit, and it runs under a backtracking limit, because a
 * catastrophic-backtracking pattern is a denial of service.
 *
 * levenshtein accepts on an absolute edit distance, never a normalised
 * similarity. A normalised similarity tolerates more edits as the key gets
 * longer, and at the threshold an author would pick to forgive a typo it
 * grades a clinically opposite word correct: "hypotension" against a key of
 * "hypertension" is distance 2, similarity 0.833, accepted at a threshold of
 * 0.8. max_edits is capped at 2, the standard spell-correction bound.
 */
final class TextMatcher
{
    public const KIND_EXACT = 'exact';
    public const KIND_EXACT_CI = 'exact_ci';
    public const KIND_CONTAINS = 'contains';
    public const KIND_WILDCARD = 'wildcard';
    public const KIND_REGEX = 'regex';
    public const KIND_LEVENSHTEIN = 'levenshtein';

    public const MAX_EDITS_CAP = 2;

    private const BACKTRACK_LIMIT = 100000;

    /** @var array<string, mixed> */
    public readonly array $normalisation;

    /**
     * @param array<string, mixed> $normalisation
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $pattern,
        public readonly float $fraction = 1.0,
        public readonly ?string $feedbackId = null,
        public readonly int $maxEdits = 1,
        array $normalisation = [],
    ) {
        if (!\in_array($kind, self::kinds(), true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown text matcher kind "%s".', $kind));
        }

        if ($maxEdits < 0 || $maxEdits > self::MAX_EDITS_CAP) {
            throw new \InvalidArgumentException('A levenshtein matcher accepts at most two edits.');
        }

        $this->normalisation = $normalisation + TextNormalisation::defaults();
    }

    /**
     * @return list<string>
     */
    public static function kinds(): array
    {
        return [
            self::KIND_EXACT,
            self::KIND_EXACT_CI,
            self::KIND_CONTAINS,
            self::KIND_WILDCARD,
            self::KIND_REGEX,
            self::KIND_LEVENSHTEIN,
        ];
    }

    /**
     * The free build's accepted-answer list, expressed as matchers. Case
     * sensitivity chooses the kind rather than adding a flag, which is what
     * makes the four controls one.
     *
     * @param list<string> $accepted
     *
     * @return list<self>
     */
    public static function fromAcceptedList(array $accepted, bool $caseSensitive): array
    {
        $kind = $caseSensitive ? self::KIND_EXACT : self::KIND_EXACT_CI;

        return array_map(
            static fn (string $value): self => new self($kind, $value),
            array_values($accepted),
        );
    }

    public function matches(string $response): bool
    {
        $candidate = $this->normaliseResponse($response);
        $pattern = $this->normalisePattern($this->pattern);

        return match ($this->kind) {
            self::KIND_EXACT, self::KIND_EXACT_CI => $candidate === $pattern,
            self::KIND_CONTAINS => $pattern !== '' && str_contains($candidate, $pattern),
            self::KIND_WILDCARD => $this->matchesWildcard($candidate, $pattern),
            self::KIND_REGEX => $this->matchesRegex($candidate),
            self::KIND_LEVENSHTEIN => $this->withinEditDistance($candidate, $pattern),
            default => false,
        };
    }

    /**
     * The flag set actually applied, recorded on the grade row so a regrade
     * is reproducible.
     *
     * @return array<string, mixed>
     */
    public function appliedFlags(): array
    {
        // The matcher kind decides case, so its answer is placed first and
        // wins the union. A question-level fold flag applies only to the
        // kinds that do not fix the behaviour themselves, which is what
        // stops "exact" quietly becoming "exact_ci" and accepting PB for the
        // symbol for lead.
        return [TextNormalisation::FLAG_CASE_FOLD => $this->foldsCase()] + $this->normalisation;
    }

    private function foldsCase(): bool
    {
        return match ($this->kind) {
            self::KIND_EXACT, self::KIND_REGEX => false,
            self::KIND_EXACT_CI => true,
            default => ($this->normalisation[TextNormalisation::FLAG_CASE_FOLD] ?? true) === true,
        };
    }

    private function normaliseResponse(string $value): string
    {
        return TextNormalisation::apply($value, $this->appliedFlags());
    }

    private function normalisePattern(string $value): string
    {
        return TextNormalisation::apply($value, $this->appliedFlags());
    }

    private function matchesWildcard(string $candidate, string $pattern): bool
    {
        // Backslash-asterisk is a literal asterisk; everything else is
        // escaped before a single wildcard token becomes ".*".
        $marker = "\0LITERAL\0";
        $prepared = str_replace('\\*', $marker, $pattern);
        $quoted = preg_quote($prepared, '/');
        $quoted = str_replace('\\*', '.*', $quoted);
        $quoted = str_replace(preg_quote($marker, '/'), '\\*', $quoted);

        return preg_match('/^' . $quoted . '$/u', $candidate) === 1;
    }

    private function matchesRegex(string $candidate): bool
    {
        // PCRE's per-pattern limit cannot raise a stricter host limit and does
        // not change process settings for another plugin or concurrent work.
        $delimited = '/(*LIMIT_MATCH=' . self::BACKTRACK_LIMIT . ')^(?:'
            . str_replace('/', '\\/', $this->pattern) . ')$/u';
        $result = @preg_match($delimited, $candidate);

        return $result === 1;
    }

    private function withinEditDistance(string $candidate, string $pattern): bool
    {
        // levenshtein() is byte-wise, which overstates the distance for
        // multibyte text, so a non-ASCII pair falls back to exact equality
        // rather than accepting a pair that is further apart than it looks.
        if (!self::isAscii($candidate) || !self::isAscii($pattern)) {
            return $candidate === $pattern;
        }

        return levenshtein($candidate, $pattern) <= $this->maxEdits;
    }

    private static function isAscii(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) !== 1;
    }
}
