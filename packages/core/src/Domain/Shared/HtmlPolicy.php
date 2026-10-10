<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * The one server-side answer to "what markup may a stored body carry".
 *
 * The player renders exactly one field as markup, the stimulus body, and it
 * sanitises it in the browser with DOMPurify. A client-side sanitiser is the
 * right last line and the wrong only line: it protects the browser that runs
 * it and nothing else, so a body stored from an interchange import reached the
 * database unexamined and every other consumer of that column (a report, a
 * PDF, an export, a second front end) inherited whatever the foreign document
 * carried.
 *
 * So the allow-list is here, once, and matches the profile the player's own
 * sanitiser applies: structural and inline text elements, lists, tables,
 * images and links, and no script, no style, no event handler, no
 * javascript: URL and no embedded object.
 *
 * `plain()` is the other half of the decision. Every other authored string in
 * the catalogue (a prompt, an option's text, a gap's label) is rendered as
 * text by every renderer in the product, so a foreign document's markup in one
 * of those fields is not formatting: it is tags the learner reads. Those
 * fields are reduced at the boundary that read them, and the importer says so.
 */
final class HtmlPolicy
{
    /**
     * The elements a stored body may carry.
     *
     * @var list<string>
     */
    public const ELEMENTS = [
        'p', 'br', 'span', 'div', 'blockquote', 'pre', 'code',
        'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'mark', 'small',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'a', 'img', 'figure', 'figcaption', 'hr',
        'ruby', 'rt', 'rp',
    ];

    /**
     * The attributes those elements may carry.
     *
     * @var list<string>
     */
    public const ATTRIBUTES = [
        'href', 'src', 'alt', 'title', 'class', 'lang', 'dir',
        'width', 'height', 'colspan', 'rowspan', 'scope', 'headers',
        'aria-label', 'aria-describedby', 'aria-hidden', 'role', 'id',
    ];

    /** The URL schemes a href or src may name. */
    public const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private function __construct()
    {
    }

    /**
     * The body with everything outside the allow-list removed.
     *
     * An element that is not allowed loses its tags and keeps its text, which
     * is what a reader wants: a paragraph inside a refused wrapper is still the
     * author's paragraph. An attribute that is not allowed is dropped, and so
     * is any href or src naming a scheme outside the four.
     */
    public static function safe(string $body): string
    {
        if (trim($body) === '' || !str_contains($body, '<')) {
            return $body;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        // No network, no entities, no doctype resolution: the same posture the
        // import readers take, because this runs on the same foreign bytes.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"?><body>' . $body . '</body>',
            \LIBXML_NONET | \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            return self::plain($body);
        }

        $root = $document->getElementsByTagName('body')->item(0);

        if (!$root instanceof \DOMElement) {
            return self::plain($body);
        }

        self::clean($root);

        $html = '';

        foreach (iterator_to_array($root->childNodes) as $child) {
            $html .= (string) $document->saveHTML($child);
        }

        return trim($html);
    }

    /**
     * The body as the text inside it, with every tag gone and every entity
     * resolved once.
     */
    public static function plain(string $body): string
    {
        // The content of these goes with the tags. Everything else keeps its
        // text, because that text is the author's; a script's is its payload,
        // and strip_tags alone would leave "alert(1)" sitting in the prompt.
        $stripped = (string) preg_replace(
            '#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1\s*>#is',
            '',
            $body,
        );

        // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
        $text = html_entity_decode(strip_tags($stripped), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[ \t]*\R[ \t]*/u', "\n", $text));
    }

    private static function clean(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                self::clean($child);
                continue;
            }

            // A comment can carry a conditional block, and nothing in a stored
            // body needs one.
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $element->removeChild($child);
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if (!\in_array(strtolower($child->nodeName), self::ELEMENTS, true)) {
                self::unwrap($child);
                continue;
            }

            self::cleanAttributes($child);
        }
    }

    private static function cleanAttributes(\DOMElement $element): void
    {
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            if (!$attribute instanceof \DOMAttr) {
                continue;
            }

            $name = strtolower($attribute->nodeName);

            if (!\in_array($name, self::ATTRIBUTES, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if (($name === 'href' || $name === 'src') && !self::isAllowedUrl($attribute->nodeValue ?? '')) {
                $element->removeAttribute($attribute->nodeName);
            }
        }
    }

    /**
     * An element outside the allow-list, replaced by its own children.
     *
     * A script or a style is removed with its content, because its content is
     * the payload; anything else keeps its text.
     */
    private static function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        if (\in_array(strtolower($element->nodeName), ['script', 'style', 'iframe', 'object', 'embed'], true)) {
            $parent->removeChild($element);

            return;
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            $parent->insertBefore($child, $element);
        }

        $parent->removeChild($element);
    }

    private static function isAllowedUrl(string $url): bool
    {
        $trimmed = trim($url);

        if ($trimmed === '' || preg_match('/[\x00-\x20\x7f]/', $trimmed) === 1 || str_contains($trimmed, '\\')) {
            return false;
        }

        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $trimmed, $matches) !== 1) {
            // A relative URL, a fragment or a protocol-relative one. The last
            // of those inherits the page's scheme and is refused with the rest
            // of the absolute forms.
            return !str_starts_with($trimmed, '//');
        }

        return \in_array(strtolower($matches[1]), self::SCHEMES, true);
    }
}
