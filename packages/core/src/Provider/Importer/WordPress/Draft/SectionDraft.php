<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft;

/**
 * One module of the imported course, in the source's own order.
 *
 * All three WordPress products carry two levels of grouping under a course
 * and the free build carries one, so a section is where the reader has
 * already decided which foreign level becomes a module. LearnDash lessons,
 * Tutor topics and LifterLMS sections all land here; what sat under them
 * lands in items.
 */
final class SectionDraft
{
    /**
     * @param string          $sourceId the product's own identity for this grouping
     * @param string          $title    already trimmed, never empty
     * @param string|null     $summary  null when the product recorded none
     * @param list<ItemDraft> $items    in curriculum order
     */
    private function __construct(
        public readonly string $sourceId,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly array $items,
    ) {
    }

    /**
     * @param list<ItemDraft> $items
     */
    public static function of(string $sourceId, string $title, ?string $summary, array $items): self
    {
        return new self($sourceId, $title, $summary, $items);
    }
}
