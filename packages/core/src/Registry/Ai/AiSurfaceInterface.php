<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One screen family a placement can anchor into, with the disclosure and
 * review obligations that family carries.
 *
 * It is a registry rather than an enumeration because a package that adds a
 * screen adds a surface, and a shipped const list would make that a core
 * edit.
 *
 * The default disclosure mode is a property of the surface rather than of the
 * placement, because the audience is a property of the screen: a learner
 * player discloses before every interaction whatever helper is mounted in it,
 * and a placement that could lower that would be a placement able to opt out
 * of Article 50(1) by registration.
 */
interface AiSurfaceInterface extends ProviderInterface
{
    public function label(): string;

    /** site, course, cohort, organisation or item. */
    public function scopeLevel(): string;

    /** The floor every placement on this surface inherits and cannot lower. */
    public function defaultDisclosureMode(): AiDisclosureMode;
}
