<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Xml;

/**
 * A typed reader over one Moodle backup XML document.
 *
 * Moodle writes untyped text: every value is a string, a missing value is the
 * literal "$@NULL@$", and numbers arrive as "2.0000000". Wrapping SimpleXML
 * once keeps that dialect in a single place instead of spreading casts and
 * null checks through the importer.
 *
 * Parsing refuses a document carrying a doctype. A course backup has no use
 * for one, and refusing it closes the entity expansion door rather than
 * trusting a libxml default.
 *
 * ## Why the doctype is refused after decoding and not before
 *
 * The refusal used to be a search for the bytes "<!DOCTYPE" in the entry. An
 * entry encoded as UTF-16 walks straight past that: its doctype is spelled
 * "<\0!\0D\0O\0..." and no ASCII search finds it, while libxml reads the byte
 * order mark and parses the document exactly as its author intended. Whether
 * a string looks like a declaration is a question about text, and the bytes
 * are not text until something has decided their encoding.
 *
 * So the parser decides it, and the question is asked of the parsed document
 * instead: DOMDocument::$doctype is the same answer in every encoding. Two
 * locks hold while that happens, because the document is hostile until it has
 * been refused: an external entity loader that answers nothing for every
 * SYSTEM and PUBLIC identifier, so no file or URL is ever fetched, and
 * LIBXML_NONET behind it. LIBXML_NOENT is not passed, so an entity reference
 * is never substituted either.
 */
final class XmlNode
{
    private const MOODLE_NULL = '$@NULL@$';

    private function __construct(
        private readonly \SimpleXMLElement $element,
    ) {
    }

    /**
     * @param string $what the entry name, so a parse failure names the file
     */
    public static function parse(string $xml, string $what): self
    {
        // An empty entry is an unreadable document, and saying so here keeps
        // it one: loadXML() raises a ValueError for it rather than answering
        // false, and the importer reads a RuntimeException as the entry's
        // problem rather than the run's.
        if ($xml === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('%s is not readable XML.', $what));
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // Installed before the parse rather than after it, because the fetch
        // an external entity provokes happens during the parse.
        libxml_set_external_entity_loader(static fn (): ?string => null);

        $document = new \DOMDocument();

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            // libxml's own loader again. PHP has no getter for the one that
            // was installed before 8.4, so the default is what is restored.
            libxml_set_external_entity_loader(null);
        }

        if ($loaded === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('%s is not readable XML.', $what));
        }

        if ($document->doctype !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('%s carries a doctype declaration and was refused.', $what));
        }

        $element = simplexml_import_dom($document, \SimpleXMLElement::class);

        if (!$element instanceof \SimpleXMLElement) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('%s is not readable XML.', $what));
        }

        return new self($element);
    }

    public function child(string $name): ?self
    {
        $children = $this->children($name);

        return $children[0] ?? null;
    }

    /**
     * @return list<self>
     */
    public function children(string $name): array
    {
        $list = $this->element->{$name};

        if (!$list instanceof \SimpleXMLElement) {
            return [];
        }

        $nodes = [];

        foreach ($list as $child) {
            $nodes[] = new self($child);
        }

        return $nodes;
    }

    /** The element's own local name, without any namespace prefix. */
    public function name(): string
    {
        return $this->element->getName();
    }

    /**
     * Every element child, in document order, whatever namespace each is in.
     *
     * children() answers for one namespace at a time, which is the wrong
     * question for a document that mixes them: a content package manifest
     * carries its metadata in a second namespace beside its own. This walks
     * the already parsed document instead of reparsing anything, so every
     * refusal parse() made still holds over what it returns.
     *
     * @return list<self>
     */
    public function elements(): array
    {
        $nodes = [];

        foreach (dom_import_simplexml($this->element)->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $imported = simplexml_import_dom($child, \SimpleXMLElement::class);

            if ($imported instanceof \SimpleXMLElement) {
                $nodes[] = new self($imported);
            }
        }

        return $nodes;
    }

    /**
     * Every attribute of this element, as name to value.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];
        $list = $this->element->attributes();

        if ($list === null) {
            return [];
        }

        foreach ($list as $name => $value) {
            $attributes[(string) $name] = (string) $value;
        }

        return $attributes;
    }

    /**
     * The markup between this element's own tags, which is what carries a
     * prompt: an interchange format puts formatted text there and losing it
     * would flatten every emphasis, list and image in an imported item.
     */
    public function inner(): string
    {
        $element = dom_import_simplexml($this->element);
        $document = $element->ownerDocument;

        if ($document === null) {
            return '';
        }

        $inner = '';

        foreach ($element->childNodes as $child) {
            $inner .= (string) $document->saveXML($child);
        }

        return $inner;
    }

    /**
     * This element and its markup, tags included.
     *
     * It is what lets a caller take one element back out of its parent's
     * markup by string: both come from saveXML over the same document, so the
     * element's serialisation is exactly a substring of its parent's, and an
     * interaction can be replaced by a placeholder in the passage that held it
     * without editing a tree.
     */
    public function outer(): string
    {
        $element = dom_import_simplexml($this->element);
        $document = $element->ownerDocument;

        return $document === null ? '' : (string) $document->saveXML($element);
    }

    /**
     * The text of a child element, with Moodle's null marker and the empty
     * string both reported as null.
     */
    public function text(string $name): ?string
    {
        $child = $this->child($name);

        return $child === null ? null : $child->value();
    }

    public function value(): ?string
    {
        $value = trim((string) $this->element);

        return $value === '' || $value === self::MOODLE_NULL ? null : $value;
    }

    /** Decoded XML character data, including CDATA and descendant text. */
    public function textContent(): string
    {
        return dom_import_simplexml($this->element)->textContent;
    }

    public function int(string $name): ?int
    {
        $value = $this->text($name);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }

    public function float(string $name): ?float
    {
        $value = $this->text($name);

        return $value !== null && is_numeric($value) ? (float) $value : null;
    }

    public function attribute(string $name): ?string
    {
        $attributes = $this->element->attributes();

        if ($attributes === null || !isset($attributes[$name])) {
            return null;
        }

        $value = trim((string) $attributes[$name]);

        return $value === '' || $value === self::MOODLE_NULL ? null : $value;
    }

    public function attributeInt(string $name): ?int
    {
        $value = $this->attribute($name);

        return $value !== null && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }
}
