import { translate as uiText } from '@/shared/i18n';
/*
 * Definition fixtures for the six free types, exactly what the core's
 * presentForLearner emits: no correctness, ever. Shared by the question
 * type suites and by the quiz runner suites, so a change to a learner
 * projection is one edit.
 */

export const singleChoiceDefinition = {
    prompt: 'Which WCAG level does the EU Accessibility Act reference?',
    options: [
        { key: 'a', label: uiText("vidyalms.ui.165d38d4d0bb6d3f", "Level A") },
        { key: 'aa', label: uiText("vidyalms.ui.b46ef28b805598c2", "Level AA") },
        { key: 'aaa', label: uiText("vidyalms.ui.77f1f61ead07f7a6", "Level AAA") },
    ],
};

export const multipleChoiceDefinition = {
    prompt: 'Which of these need text alternatives?',
    options: [
        { key: 'informative', label: uiText("vidyalms.ui.57de8a43a4e846e1", "Informative images") },
        { key: 'decorative', label: uiText("vidyalms.ui.4e0cdb2cac412a43", "Purely decorative images") },
        { key: 'icon', label: uiText("vidyalms.ui.69e69fc1710bca9f", "Icon-only buttons") },
    ],
};

export const trueFalseDefinition = {
    prompt: 'A transcript alone satisfies the captions requirement.',
};

export const shortAnswerDefinition = {
    prompt: 'Why should focus return to the trigger when a dialog closes?',
};

export const fillBlankDefinition = {
    prompt: 'Normal text needs [[ratio]]:1 contrast, large text needs [[large]]:1.',
    blanks: [{ key: 'ratio' }, { key: 'large' }],
};

export const orderingDefinition = {
    prompt: 'Order the steps of an accessibility audit.',
    items: [
        { key: 'sample', label: uiText("vidyalms.ui.cc983ea085845a8b", "Define the page sample") },
        { key: 'auto', label: uiText("vidyalms.ui.af8cb9a75f8feb40", "Run automated checks") },
        { key: 'manual', label: uiText("vidyalms.ui.920526cde0ba9fa3", "Manual keyboard pass") },
        { key: 'report', label: uiText("vidyalms.ui.7cdfad26ecbe5a9b", "Write up findings") },
    ],
};
