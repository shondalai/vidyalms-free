<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * How a type can be operated, and whether anybody has verified it.
 *
 * ADR 0011 states the gate as a property of the registry, not of the browser:
 * "the registry refuses to accept a type that declares pointer dragging
 * without a non-drag pointer mode and a keyboard mode". Implementing it only
 * in the client renderer registry meant it ran in the learner's browser, only
 * for types whose renderer happened to be in that bundle, and never at all for
 * a server-registered type with no client module, which is a registration a
 * third party can perfectly well make.
 *
 * The conformance half is the same shape: "a type declares a kit result
 * identifier, the ledger stores it, and a type with no verified result
 * registers in a visibly flagged state". A flag that lives in a page's memory
 * cannot answer the question a site owner is actually asking, which is which
 * installed types are unverified.
 *
 * The default when a provider does not implement this is the safe one: a type
 * that says nothing is assumed to be operable by pointer and keyboard without
 * dragging, which is what every non-spatial type is, and it is flagged as
 * unverified because it declared no kit result.
 */
interface AccessibilityDeclaringInterface
{
    /** A spatial enhancement, never a mode on its own. */
    public const POINTER_DRAG = 'POINTER_DRAG';

    /** Click, tap or select-then-place, with no drag. */
    public const POINTER_NO_DRAG = 'POINTER_NO_DRAG';

    public const KEYBOARD = 'KEYBOARD';

    /** The parallel single-input presentation a spatial interaction ships beside itself. */
    public const LINEAR_TEXT = 'LINEAR_TEXT';

    /**
     * @return list<string> at least POINTER_NO_DRAG and KEYBOARD; the registry
     *                      refuses less
     */
    public function interactionModes(): array;

    /**
     * Required when POINTER_DRAG is declared without the other two. The
     * ledger stores it and the authoring UI surfaces it.
     */
    public function essentialDraggingJustification(): ?string;

    /**
     * The identifier of a published conformance kit result. A type with none
     * registers in a visibly flagged state rather than being refused.
     */
    public function conformanceResult(): ?string;
}
