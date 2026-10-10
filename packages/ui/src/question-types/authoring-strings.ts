import { translate as uiText } from '@/shared/i18n';
/*
 * The authoring copy the six free question types render: their blocks, form
 * models and validation messages. They used to read it from
 * src/instructor/strings.ts, which put that whole table (about five hundred
 * module-scope translations, nearly all for workbench screens) into the
 * question-types chunk and so onto every quiz page, one shared import away
 * from every player page as well. The instructor table spreads both groups
 * back into instructorStrings.question and instructorStrings.form, so every
 * existing reader, Pro included, sees the same keys, the same text and the
 * same message keys.
 *
 * Same rules as src/shared/strings.ts: one typed object, no scattered
 * literals, no em-dashes and no en-dashes anywhere.
 */
export const questionAuthoringStrings = {
    question: {
        promptRequired: uiText("vidyalms.ui.aedd369236efb930", "A prompt is required."),
        options: uiText("vidyalms.ui.d0db8b5e364b6989", "Options"),
        markCorrectOne: uiText("vidyalms.ui.c78f2d134cfba644", "mark the correct one"),
        markCorrectMany: uiText("vidyalms.ui.57bb05cdf79ee876", "mark every correct one"),
        optionLabel: (n: number) => uiText("vidyalms.ui.ca7387bb97eb459a", "Option {p0}", { p0: n }),
        optionCorrect: (n: number) => uiText("vidyalms.ui.44a74c47495feb9d", "Option {p0} is correct", { p0: n }),
        removeOption: (n: number) => uiText("vidyalms.ui.31a6c531b32e80fe", "Remove option {p0}", { p0: n }),
        addOption: uiText("vidyalms.ui.45aaacba7ea1a668", "Option"),
        trueLabel: uiText("vidyalms.ui.3cbc87c7681f34db", "True"),
        falseLabel: uiText("vidyalms.ui.60a33e6cf5151f2d", "False"),
        correctAnswer: uiText("vidyalms.ui.fb019d338b4305ef", "Correct answer"),
        accepted: uiText("vidyalms.ui.6d0329f8c82505fa", "Accepted answers"),
        acceptedHint: uiText("vidyalms.ui.2f8d5ee1fe62037c", "One per line. Leave empty to mark this question by hand in the Gradebook."),
        acceptedEmptyNote: uiText("vidyalms.ui.d61f4b54f9127721", "With no accepted answer this question waits for a human in the Gradebook."),
        caseSensitive: uiText("vidyalms.ui.30b805ff2f58d7b9", "Match upper and lower case exactly"),
        blanks: uiText("vidyalms.ui.79654f98221029c4", "Blanks"),
        blanksHint: uiText("vidyalms.ui.a0c9d8dd255ca91c", "Every blank needs a [[key]] marker in the prompt and at least one accepted answer."),
        blankKey: (n: number) => uiText("vidyalms.ui.17a840a378414afc", "Blank {p0} key", { p0: n }),
        blankAccepted: (n: number) => uiText("vidyalms.ui.2bf154a96da2bc32", "Blank {p0} accepted answers", { p0: n }),
        addBlank: uiText("vidyalms.ui.2c3d371cbfd31225", "Blank"),
        removeBlank: (n: number) => uiText("vidyalms.ui.a753a27f824b15b6", "Remove blank {p0}", { p0: n }),
        blankMissingMarker: (key: string) => uiText("vidyalms.ui.69fa028fa936c3f9", "The prompt is missing the [[{p0}]] marker.", { p0: key }),
        items: uiText("vidyalms.ui.79c05c2f4524c3a1", "Items in their correct order"),
        itemsHint: uiText("vidyalms.ui.91b2a68b5c708f03", "Learners see them shuffled and put them back in this order."),
        itemLabel: (n: number) => uiText("vidyalms.ui.c455d7700218a286", "Item {p0}", { p0: n }),
        addItem: uiText("vidyalms.ui.652bcc3a47842889", "Item"),
        removeItem: (n: number) => uiText("vidyalms.ui.186dea820a981577", "Remove item {p0}", { p0: n }),
        moveItemUp: (n: number) => uiText("vidyalms.ui.6f1dcc925f61b59f", "Move item {p0} up", { p0: n }),
        moveItemDown: (n: number) => uiText("vidyalms.ui.da60716c89589577", "Move item {p0} down", { p0: n }),
    },
    form: {
        numberRequired: uiText("vidyalms.ui.7b2968fb595b02f1", "Enter a number."),
        positiveNumber: uiText("vidyalms.ui.91ce51d375b55493", "Enter a number greater than zero."),
        atLeastOne: uiText("vidyalms.ui.cefc825883dbfa93", "Add at least one."),
        chooseCorrect: uiText("vidyalms.ui.695bc3c76c6bacba", "Mark the correct answer."),
        chooseCorrectMany: uiText("vidyalms.ui.60287ba3cc501e82", "Mark at least one correct answer."),
        uniqueKeys: uiText("vidyalms.ui.4f3098c9c4d4bdee", "Each key must be different."),
        keyRequired: uiText("vidyalms.ui.20f2fd2fc1852c92", "A key is required."),
        labelRequired: uiText("vidyalms.ui.f7d3ad0dc05ad08e", "A label is required."),
        acceptedRequired: uiText("vidyalms.ui.5589e7e2a05ca9f0", "Add at least one accepted answer."),
    },
};
