import { translate as uiText } from '@/shared/i18n';
/*
 * Copy owned by the question type layer: the eight client primitives, the
 * registry shell (the error summary, the placeholder) and the six free
 * types. It lives here rather than in an app because the same modules are
 * registered by the quiz-runner entry and by the instructor entry.
 *
 * Same rules as src/shared/strings.ts: one typed object, no scattered
 * literals, no em-dashes and no en-dashes anywhere.
 */
export const questionTypeStrings = {
    chooseOne: uiText("vidyalms.ui.0b24aed53ab654ea", "Choose one"),
    selectAll: uiText("vidyalms.ui.10d20e340181a52f", "Select all that apply"),
    selectedCount: (n: number) => uiText("vidyalms.ui.79193700a8ebdf47", "{p0} selected", { p0: n }),
    trueFalse: uiText("vidyalms.ui.67c38c7093b9bc37", "True or false"),
    trueLabel: uiText("vidyalms.ui.3cbc87c7681f34db", "True"),
    falseLabel: uiText("vidyalms.ui.60a33e6cf5151f2d", "False"),
    shortAnswer: uiText("vidyalms.ui.40f6ba6b7d426214", "Short answer"),
    answerPlaceholder: uiText("vidyalms.ui.faac31ca129e73c0", "Your answer…"),
    charCount: (n: number, max: number) => uiText("vidyalms.ui.1110bdd690bab3e7", "{p0} / {p1}", { p0: n, p1: max }),
    fillBlanks: (n: number) => uiText("vidyalms.ui.12e2f11d3923e8df", "Fill in the blanks · {p0}", { p0: n === 1 ? '1 gap' : `${n} gaps` }),
    blankLabel: (i: number, n: number) => uiText("vidyalms.ui.d9f5e446f144413d", "Blank {p0} of {p1}", { p0: i, p1: n }),
    ordering: uiText("vidyalms.ui.1a1a55e700707996", "Put in order · top = first"),
    moveUp: (label: string) => uiText("vidyalms.ui.e303db86c57a0d3d", "Move {p0} up", { p0: label }),
    moveDown: (label: string) => uiText("vidyalms.ui.fa202c9e05d096f7", "Move {p0} down", { p0: label }),
    movedTo: (label: string, pos: number, total: number) => uiText("vidyalms.ui.d4c0882fdb7b1bb3", "{p0}, position {p1} of {p2}", { p0: label, p1: pos, p2: total }),
    unsupportedTitle: uiText("vidyalms.ui.e6f9aa817cbafd01", "This question type isn't supported here."),
    unsupportedBody: uiText("vidyalms.ui.b031ca0ea6434360", "You can skip it. The rest of the quiz works normally."),
} as const;

export const primitiveStrings = {
    /* Selection limits are announced, never silently enforced: disabling the
       remaining controls reads as broken and provides no error text. */
    maxReached: (max: number) => uiText("vidyalms.ui.97b26990bfbc6177", "Maximum of {p0} reached, deselect one to change your answer.", { p0: max }),
    minNotMet: (min: number) => uiText("vidyalms.ui.cffe02eb7743bf99", "Choose at least {p0}.", { p0: min }),
    invalidPermutation: uiText("vidyalms.ui.41421e3bc0524e57", "Every item must appear exactly once, in one order."),
    requiredGapEmpty: (label: string) => uiText("vidyalms.ui.27bba350064675ad", "{p0} is empty.", { p0: label }),
    selectedOf: (n: number, max: number) => uiText("vidyalms.ui.c4322807edc8a683", "{p0} of {p1} selected", { p0: n, p1: max }),

    /* Select then place: two single-pointer activations, the same state
       machine the keyboard grab and move model uses. */
    grab: (label: string) => uiText("vidyalms.ui.6e553048d0d9007b", "Pick up {p0}", { p0: label }),
    moveUp: (label: string) => uiText("vidyalms.ui.e303db86c57a0d3d", "Move {p0} up", { p0: label }),
    moveDown: (label: string) => uiText("vidyalms.ui.fa202c9e05d096f7", "Move {p0} down", { p0: label }),
    grabbed: (label: string) => uiText("vidyalms.ui.434c671b09e2db0b", "{p0} picked up. Choose where to put it, or press Escape to cancel.", { p0: label }),
    cancelled: (label: string) => uiText("vidyalms.ui.d792b6d7a3e5a462", "{p0} put back.", { p0: label }),
    placed: (label: string, target: string) => uiText("vidyalms.ui.010018f18e6d20e5", "{p0} placed on {p1}.", { p0: label, p1: target }),
    placeHere: (target: string) => uiText("vidyalms.ui.0f091fd19ae43fc7", "Place here: {p0}", { p0: target }),
    holding: (label: string) => uiText("vidyalms.ui.dfaa5c56375d4956", "Holding {p0}", { p0: label }),
    reordered: (label: string, pos: number, total: number) =>
        uiText("vidyalms.ui.5bbbbbfcd9faddfe", "The list has been reordered, {p0} is now {p1} of {p2}.", { p0: label, p1: pos, p2: total }),

    positionLabel: (label: string) => uiText("vidyalms.ui.9d98434872d72398", "Position of {p0}", { p0: label }),
    linearListTitle: uiText("vidyalms.ui.4d1406c8e5391356", "Text alternative"),
    linearListHint: uiText("vidyalms.ui.e22a3fcdcf324cef", "The same choices as a list, usable without the image."),
    regionLabel: (label: string) => label,
    regionSelected: (label: string) => uiText("vidyalms.ui.dc1ad526d8f17c1d", "{p0} selected.", { p0: label }),
    regionCleared: (label: string) => uiText("vidyalms.ui.edf4e02eaa4c50ed", "{p0} cleared.", { p0: label }),
    pointAt: (x: number, y: number) => uiText("vidyalms.ui.bd8f1946df371091", "Point at {p0}, {p1}", { p0: x, p1: y }),
    /*
     * The same line for a mark that stands for something the learner chose
     * to place, which is every graphic association and every placed object.
     * "Point at 30, 40" names the coordinates and not the thing, so a
     * learner reading the list back could not tell which marker they had
     * put where, and the remove button beside it said "Remove point 2" for
     * the same reason (SC 4.1.2 needs the name to be the thing's name).
     */
    namedPointAt: (label: string, x: number, y: number) => uiText("vidyalms.ui.73dc7255de626ca4", "{p0} at {p1}, {p2}", { p0: label, p1: x, p2: y }),
    pointAdded: (x: number, y: number) => uiText("vidyalms.ui.c672fc95d1ff81ac", "Point added at {p0}, {p1}.", { p0: x, p1: y }),
    pointRemoved: (index: number) => uiText("vidyalms.ui.6a181201b7f81846", "Point {p0} removed.", { p0: index }),
    removePoint: (index: number) => uiText("vidyalms.ui.8d226ba8dff5a762", "Remove point {p0}", { p0: index }),
    removeNamedPoint: (label: string) => uiText("vidyalms.ui.55fbe401207d0466", "Remove {p0}", { p0: label }),
    addPoint: uiText("vidyalms.ui.71c21386f37fcf2c", "Add point"),
    noPoints: uiText("vidyalms.ui.30de808f4c33c651", "No points marked yet."),

    stepDown: uiText("vidyalms.ui.991e5ab396c965a1", "Decrease"),
    stepUp: uiText("vidyalms.ui.0c2d3b8713b00333", "Increase"),
    valueLabel: uiText("vidyalms.ui.8e37953d23daca5f", "Value"),

    associationTargetNone: uiText("vidyalms.ui.2395fae627c06b02", "Not matched"),
    /**
     * The empty option of any select that is not an association.
     *
     * Telling a learner their pen colour is "Not matched" is wrong
     * instruction text in the one control that has to be self-explanatory
     * (SC 3.3.2), and the association wording used to be every caller's.
     */
    notChosen: uiText("vidyalms.ui.2f7b5f0c9376a854", "Not chosen"),
    associationFor: (source: string) => uiText("vidyalms.ui.081131f3328b9f65", "Match for {p0}", { p0: source }),
    associationRemove: (source: string, target: string) => uiText("vidyalms.ui.c7c7d81a4f615e1a", "Unmatch {p0} from {p1}", { p0: source, p1: target }),
    associationCleared: (source: string) => uiText("vidyalms.ui.e019bd086ef6996a", "{p0} unmatched.", { p0: source }),

    addFiles: uiText("vidyalms.ui.01792a33c228a70e", "Add files"),
    removeFile: (name: string) => uiText("vidyalms.ui.55fbe401207d0466", "Remove {p0}", { p0: name }),
    fileAdded: (name: string) => uiText("vidyalms.ui.8aaa6f2805f2528c", "{p0} attached.", { p0: name }),
    fileRemoved: (name: string) => uiText("vidyalms.ui.bc61c2681c73bb1b", "{p0} removed.", { p0: name }),
    noFiles: uiText("vidyalms.ui.2699021c682694c5", "Nothing attached yet."),
    unsupportedFileType: (name: string) => uiText("vidyalms.ui.5e182bece202b978", "{p0} is not an accepted file type.", { p0: name }),
} as const;

export const shellStrings = {
    errorSummaryTitle: uiText("vidyalms.ui.3f63f8bb3785f99e", "There is a problem"),
    errorTitlePrefix: uiText("vidyalms.ui.8eecdde079a90927", "Error: "),
    partLabel: (partId: string) => uiText("vidyalms.ui.7295462f8f86d6bc", "Part {p0}", { p0: partId }),

    placeholderTitle: (type: string) => uiText("vidyalms.ui.b6e3062711918179", "{p0} is not available on this page", { p0: type }),
    placeholderBody:
        uiText("vidyalms.ui.a19a0838e6c91e3e", "Your answer is stored and nothing is lost. The package that renders this question type is not installed here."),
    placeholderAnswerTitle: uiText("vidyalms.ui.efd5b686da053545", "Your stored answer"),
    placeholderNoAnswer: uiText("vidyalms.ui.45f39552aa8a9f76", "Nothing answered yet."),
    placeholderReadOnly: uiText("vidyalms.ui.ec7b43c71b8ba12f", "This question cannot be answered until the package is installed."),

    shapePair: (source: string, target: string) => uiText("vidyalms.ui.db67227395e1fcd8", "{p0} to {p1}", { p0: source, p1: target }),
    shapePoint: (x: number, y: number) => uiText("vidyalms.ui.12cafd4e0ceedbff", "{p0}, {p1}", { p0: x, p1: y }),
    shapeEmpty: uiText("vidyalms.ui.c6c094bc0054f9cb", "Empty"),
    shapeYes: uiText("vidyalms.ui.85a39ab345d672ff", "Yes"),
    shapeNo: uiText("vidyalms.ui.1ea442a134b2a184", "No"),

    flaggedRenderer: uiText("vidyalms.ui.57b644cd1e214dc7", "This question type has not passed the accessibility conformance kit."),
} as const;
