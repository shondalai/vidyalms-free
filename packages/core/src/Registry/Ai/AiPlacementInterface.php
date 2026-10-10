<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\PlacementContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Where a task is offered in the product.
 *
 * A placement declares a predicate rather than a list of task keys, so it
 * never names a provider key and adding a task to a shipped panel is not an
 * edit to a free provider. The binding from a task to a placement is the
 * task own declaration and is the only one; the site opt-in list stays in
 * provider_settings.
 *
 * **accepts() replaces the taskKeys() an earlier draft declared**, and the
 * difference is the whole extensibility argument. A placement naming keys
 * would make adding the eighteenth paid task, or any third-party task, to the
 * shipped question bank panel an edit to a free provider inside the free
 * bundle. Over a descriptor, a task reaches a shipped panel with zero edits.
 *
 * **surfaceKey() returns a provider key rather than an enum case**, because
 * as a closed enum the first helper on a certificate designer, a cohort
 * screen, an organisation screen or any screen a third-party package ships
 * would be an enum case inside packages/core.
 *
 * **anchor() and renderMode() are what make one generic mount real.** Four
 * shipped placements share the instructor workbench surface and sit in
 * structurally different places, so a mount reading only the surface would
 * have to map a placement key to a DOM slot by hand. A placement whose anchor
 * the running bundle does not publish is not offered, which degrades rather
 * than 404s when a workbench is newer than its host.
 */
interface AiPlacementInterface extends ProviderInterface
{
    public function label(): string;

    /** The AiSurface provider key this placement anchors into. */
    public function surfaceKey(): string;

    /** The published slot within that surface, versioned alongside the mount contract. */
    public function anchor(): string;

    public function renderMode(): AiRenderMode;

    /**
     * The static half a picker can be built from: whether this placement
     * would ever offer a task of this shape.
     */
    public function accepts(TaskDescriptor $task, PlacementContext $context): bool;

    /**
     * The fields a FieldFill placement may write into, and nothing outside
     * the list. This is where the no-mark redline stops being a screen spec
     * and becomes a contract.
     *
     * @return list<string>
     */
    public function fillableFields(): array;

    /** site, course, cohort, organisation or item. */
    public function scopeLevel(): string;

    public function disclosure(): AiDisclosure;

    /**
     * The per-request half: which task keys are actually offered here now,
     * after permissions, enablement and context have resolved.
     *
     * @return list<string>
     */
    public function offersFor(PlacementContext $context): array;
}
