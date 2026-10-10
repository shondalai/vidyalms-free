<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor;

/**
 * The shape of the payload this anchor reads.
 *
 * Absent means the anchor takes no payload at all, so a builder offering it
 * renders no delay form and an author has nothing to guess. Before this
 * existed the catalogue published `params_schema => []` as a literal for every
 * anchor, which is indistinguishable from "takes nothing" and was wrong for
 * four of the five shipped anchors, so an author composing a delay had to read
 * the provider's source.
 *
 * It is a capability rather than a method on ScheduleAnchorInterface because
 * that seam is shipped and an interface never grows a method after it ships.
 */
interface DeclaresAnchorParams
{
    /**
     * The payload schema, rendered by the form and interpreted by the core
     * not at all, exactly as a condition's params schema is.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;
}
