<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer;

/** Imported rich text must match the question renderer's plain-text contract. */
final class QuestionText
{
    public static function plain(string $html): string
    {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? '';
        $html = preg_replace_callback('#<img\b[^>]*>#is', static function (array $match): string {
            if (preg_match('/\balt\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $match[0], $alt)) {
                return $alt[1] ?: (($alt[2] ?? '') ?: ($alt[3] ?? ''));
            }
            return '[Image without alternative text]';
        }, $html) ?? '';
        $html = preg_replace('#</?(?:p|div|li|h[1-6]|br)\b[^>]*>#i', "\n", $html) ?? '';
        // Strip recognised HTML only: literal type parameters such as <T>
        // are question content. Every consumer renders this result as text.
        $html = preg_replace('#</?(?:a|abbr|article|aside|b|blockquote|body|caption|code|dd|del|details|dl|dt|em|figcaption|figure|font|footer|header|hr|html|i|ins|kbd|label|main|mark|nav|ol|pre|s|section|small|span|strong|sub|summary|sup|table|tbody|td|th|thead|tr|u|ul)\b[^>]*>#i', '', $html) ?? '';
        return trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public static function definition(array $definition): array
    {
        foreach ($definition as $key => $value) {
            if (is_string($value) && in_array($key, ['prompt', 'label', 'text', 'feedback', 'explanation'], true)) {
                $definition[$key] = self::plain($value);
            } elseif (is_array($value)) {
                $definition[$key] = self::children($value, $key === 'accepted');
            }
        }
        return $definition;
    }

    /** @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private static function children(array $values, bool $answers = false): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::definition($value);
            } elseif ($answers && is_string($value)) {
                $values[$key] = self::plain($value);
            }
        }
        return $values;
    }
}
