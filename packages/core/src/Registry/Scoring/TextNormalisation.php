<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

/**
 * The named, individually switchable normalisation pipeline text matching
 * runs, and the order matters.
 *
 * 1. Unicode NFC, or NFKC when the author opts in.
 * 2. Optional curly-quote and dash mapping.
 * 3. Optional punctuation replaced by a space, not removed.
 * 4. Trim.
 * 5. Collapse internal whitespace runs to a single space.
 * 6. Unicode full case folding, never byte-wise strtolower, which does not
 *    fold an accented E.
 *
 * Punctuation is replaced rather than stripped and moved ahead of whitespace
 * because the other order rejects a correct answer: with stripping on,
 * "carbon-dioxide" collapses to "carbondioxide" and fails a key of "carbon
 * dioxide", which is how a large share of learners write it.
 *
 * NFKC is opt-in, never the default. It is a compatibility fold: it maps
 * superscript two and subscript two both onto "2", so "H2O" becomes
 * indistinguishable from the correct formula and a key of ten squared
 * accepts "102". Those distinctions carry the construct in science and
 * mathematics items.
 *
 * The identical pipeline with the identical flag set is applied to every
 * matcher pattern as well as to the response. Applying it to one side only
 * silently breaks every pattern containing an affected character.
 */
final class TextNormalisation
{
    public const FLAG_FORM = 'form';
    public const FLAG_MAP_QUOTES = 'map_quotes';
    public const FLAG_PUNCTUATION_TO_SPACE = 'punctuation_to_space';
    public const FLAG_COLLAPSE_WHITESPACE = 'collapse_whitespace';
    public const FLAG_CASE_FOLD = 'case_fold';

    public const FORM_NFC = 'nfc';
    public const FORM_NFKC = 'nfkc';

    private function __construct()
    {
    }

    /**
     * The defaults a definition that names none gets. Case folding is on,
     * but a matcher kind that fixes case for itself (exact, exact_ci) wins
     * over it; that precedence is stated in TextMatcher and is the reason
     * one concept no longer has four controls.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            self::FLAG_FORM => self::FORM_NFC,
            self::FLAG_MAP_QUOTES => false,
            self::FLAG_PUNCTUATION_TO_SPACE => false,
            self::FLAG_COLLAPSE_WHITESPACE => true,
            self::FLAG_CASE_FOLD => true,
        ];
    }

    /**
     * @param array<string, mixed> $flags
     */
    public static function apply(string $value, array $flags): string
    {
        $flags += self::defaults();

        $value = self::normaliseForm($value, \is_string($flags[self::FLAG_FORM]) ? $flags[self::FLAG_FORM] : self::FORM_NFC);

        if ($flags[self::FLAG_MAP_QUOTES] === true) {
            $value = self::mapQuotesAndDashes($value);
        }

        if ($flags[self::FLAG_PUNCTUATION_TO_SPACE] === true) {
            $value = self::punctuationToSpace($value);
        }

        $value = trim($value);

        if ($flags[self::FLAG_COLLAPSE_WHITESPACE] === true) {
            $value = self::collapseWhitespace($value);
        }

        if ($flags[self::FLAG_CASE_FOLD] === true) {
            $value = self::foldCase($value);

            // Full case folding can denormalise, and a single pass is not
            // stable, so a compatibility fold runs NFKC again afterwards.
            // That is the Unicode-recommended sequence for caseless
            // compatibility matching.
            if ($flags[self::FLAG_FORM] === self::FORM_NFKC) {
                $value = self::normaliseForm($value, self::FORM_NFKC);
            }
        }

        return $value;
    }

    /**
     * Trim plus internal whitespace collapse, without touching case. The
     * shared half of every comparison.
     */
    public static function collapse(string $value): string
    {
        return self::collapseWhitespace(trim($value));
    }

    /**
     * Unicode full case folding.
     *
     * mb_convert_case with MB_CASE_FOLD is the only correct implementation;
     * strtolower is byte-wise and leaves an accented E unfolded, which
     * silently fails a correct answer. It is called unconditionally rather
     * than guarded, because a guard would make one learner's mark depend on
     * a host's extension list, and the core already requires mbstring.
     */
    public static function foldCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_FOLD, 'UTF-8');
    }

    private static function normaliseForm(string $value, string $form): string
    {
        if (!class_exists(\Normalizer::class)) {
            return $value;
        }

        $normalised = $form === self::FORM_NFKC
            ? \Normalizer::normalize($value, \Normalizer::FORM_KC)
            : \Normalizer::normalize($value, \Normalizer::FORM_C);

        return \is_string($normalised) ? $normalised : $value;
    }

    private static function mapQuotesAndDashes(string $value): string
    {
        return strtr($value, [
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201A}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{201E}" => '"',
            "\u{2010}" => '-',
            "\u{2011}" => '-',
            "\u{2012}" => '-',
            "\u{2013}" => '-',
            "\u{2014}" => '-',
            "\u{2212}" => '-',
        ]);
    }

    private static function punctuationToSpace(string $value): string
    {
        $replaced = preg_replace('/[\p{P}\p{S}]/u', ' ', $value);

        return \is_string($replaced) ? $replaced : $value;
    }

    private static function collapseWhitespace(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return \is_string($collapsed) ? $collapsed : $value;
    }
}
