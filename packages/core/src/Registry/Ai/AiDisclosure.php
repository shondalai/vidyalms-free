<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * What each of the three audiences is told, and when.
 *
 * Three audiences need three different things at three different moments: a
 * learner about to interact needs the Article 50(1) statement plus a plain
 * sentence about what leaves the site, a learner reading published content
 * needs to know a named person published it, and an author about to accept a
 * draft needs the provenance panel. One value object carrying all three is
 * what makes a newly registered placement unable to forget one, which is the
 * difference between a rule in a document and a rule a placement inherits.
 *
 * All three are message keys rather than sentences, because the core owns no
 * locale.
 */
final class AiDisclosure
{
    private function __construct(
        public readonly AiDisclosureMode $mode,
        public readonly string $beforeInteractionKey,
        public readonly string $atReadTimeKey,
        public readonly string $atReviewKey,
    ) {
    }

    public static function of(
        AiDisclosureMode $mode,
        string $beforeInteractionKey,
        string $atReadTimeKey,
        string $atReviewKey,
    ): self {
        return new self($mode, $beforeInteractionKey, $atReadTimeKey, $atReviewKey);
    }

    public function isDrawn(): bool
    {
        return $this->mode !== AiDisclosureMode::None;
    }
}
