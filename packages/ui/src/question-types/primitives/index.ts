/*
 * The eight client interaction primitives, the client counterparts of the
 * eight server interaction primitives. A type composes them; it never ships
 * its own DOM or ARIA.
 *
 *   select     SingleSelectGroup, MultiSelectGroup
 *   order      OrderedList
 *   associate  AssociationSet
 *   point      RegionSet (regions mode and points mode)
 *   scalar     ScalarControl
 *   signal     ScalarControl (a single integer engagement value)
 *   text       TextEntry
 *   artifact   ArtifactCapture
 *
 * This is the structural decision of the whole design. If each type shipped
 * its own DOM, every new type would re-litigate accessibility and a
 * third-party type would get it wrong. It is not inherited "by
 * construction": the client contract hands a module a bare element it may
 * fill with anything, so composing these is what makes passing the
 * conformance kit straightforward, not what makes it automatic.
 */
export { SingleSelectGroup, MultiSelectGroup, type SelectOption } from './select-group';
export { OrderedList, type OrderedItem } from './ordered-list';
export { AssociationSet, type AssociationEnd, type DirectedPair } from './association-set';
export { RegionSet, type Region, type MarkedPoint } from './region-set';
export { ScalarControl } from './scalar-control';
export { TextEntry } from './text-entry';
export { ArtifactCapture, type CapturedArtifact } from './artifact-capture';
export {
    useSelectThenPlace,
    DROP_TARGET_ATTR,
    HELD_ATTR,
    type SelectThenPlace,
    type HeldItem,
} from './select-then-place';
export { InlineError, LinearAlternative, describedBy, type Presentation, type PrimitiveProps } from './common';
export {
    bigChoiceClass,
    buttonTarget,
    checkBoxClass,
    focusRing,
    linearPanelClass,
    movableRowClass,
    optionRowClass,
    radioDotClass,
    textBoxClass,
} from './styles';

/**
 * Which client primitives answer for each server interaction primitive. The
 * conformance kit walks this so a new primitive cannot be added without a
 * three-mode run, and a type author can look up what to compose.
 */
export const CLIENT_PRIMITIVES = {
    select: ['SingleSelectGroup', 'MultiSelectGroup'],
    order: ['OrderedList'],
    associate: ['AssociationSet'],
    point: ['RegionSet'],
    scalar: ['ScalarControl'],
    signal: ['ScalarControl'],
    text: ['TextEntry'],
    artifact: ['ArtifactCapture'],
} as const;
