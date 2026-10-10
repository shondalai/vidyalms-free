<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\NegativeMarking;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A negative marking rule: what a wrong answer costs, when a quiz has chosen
 * to charge for one.
 *
 * The free build registers none, and that is a decision with a reason rather
 * than a gap. Frary concluded that formula scoring is generally
 * inappropriate for classroom testing and, being a linear transformation of
 * number-right under average luck, offers no information number-right
 * scoring does not already give. Coffman and Klinowski found that removing
 * the wrong-answer penalty from a national university entrance examination
 * narrowed the gender gap in scores by about nine percent, through
 * differential risk aversion, which is construct-irrelevant variance.
 *
 * So the seam exists, the settings form reports honestly that this site
 * offers no rule, and a site that wants one installs a package rather than
 * having the default quietly changed underneath it.
 */
interface NegativeMarkingRuleInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The lowest fraction an item may fall to under this rule. Null leaves
     * each scoring policy's own floor in force, which is the common case: a
     * rule usually decides whether a penalty applies at all, and the size of
     * it is the marking rule's business.
     */
    public function floorFraction(): ?float;

    /**
     * A JSON Schema fragment for the parameters this rule takes, or null.
     * The settings form renders it; nothing in the core interprets it.
     *
     * @return array<string, mixed>|null
     */
    public function paramsSchema(): ?array;
}
