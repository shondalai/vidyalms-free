<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft;

/**
 * One foreign course, read out of a WordPress LMS and translated into shapes
 * the free build's own use cases accept.
 *
 * It is the whole boundary between a product reader and the shared writer.
 * The reader carries every LearnDash, Tutor or LifterLMS word; the writer
 * carries every use case call and knows none of those words. That is what
 * lets three importers share one writer without the writer growing a branch
 * per product, and it is the same anti-corruption shape the item mapper layer
 * uses on the assessment side.
 *
 * Nothing here is a local id. A draft is built before anything is written and
 * is valid whether the run writes or only counts, which is what makes the dry
 * pass and the real pass one parse rather than two.
 */
final class CourseDraft
{
    /**
     * @param string               $sourceId    the product's own course identity, stable across runs
     * @param string               $title       already trimmed, never empty
     * @param string|null          $slug        the product's own slug, or null
     * @param string|null          $summary     null when the product recorded none
     * @param string|null          $description null when the product recorded none
     * @param list<SectionDraft>   $sections    in curriculum order
     * @param list<MembershipDraft> $memberships every learner the product gave access to
     */
    private function __construct(
        public readonly string $sourceId,
        public readonly string $title,
        public readonly ?string $slug,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly array $sections,
        public readonly array $memberships,
    ) {
    }

    /**
     * @param list<SectionDraft>    $sections
     * @param list<MembershipDraft> $memberships
     */
    public static function of(
        string $sourceId,
        string $title,
        ?string $slug,
        ?string $summary,
        ?string $description,
        array $sections,
        array $memberships,
    ): self {
        return new self($sourceId, $title, $slug, $summary, $description, $sections, $memberships);
    }

    /**
     * Every item of every section, which is what the writer walks when it has
     * to resolve a completion against something it created.
     *
     * @return list<ItemDraft>
     */
    public function items(): array
    {
        $items = [];

        foreach ($this->sections as $section) {
            foreach ($section->items as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
