import { translate as uiText } from '@/shared/i18n';
/*
 * The runner copy of wave 3b: the frozen policy the attempt obeys, the item
 * session control, the availability window and the two Pro panel slots.
 *
 * Kept in its own catalogue beside strings.ts rather than merged into it, so
 * this wave adds copy without touching a file every other wave is also
 * writing to. Same rules: one typed object, no scattered literals, no
 * em-dashes and no en-dashes anywhere.
 */
export const runnerRuntimeStrings = {
    navigation: {
        linearTitle: uiText("vidyalms.ui.29b58230e373daaa", "This quiz moves forward only."),
        linearBody:
            uiText("vidyalms.ui.cb58ee7668734108", "Once you leave a question you cannot return to it, so answer before you continue. ") +
            uiText("vidyalms.ui.00403f9f3c4e78d7", "Your answers are saved as you go."),
        linearNoJump: uiText("vidyalms.ui.a405540dc738721e", "Question progress (forward only)"),
    },

    submission: {
        simultaneousTitle: uiText("vidyalms.ui.dbcb57d15bb7dcf6", "Everything is marked at the end."),
        simultaneousBody:
            uiText("vidyalms.ui.ed31223fac631a75", "Nothing is marked while you work, so you get one answer per question and no feedback ") +
            uiText("vidyalms.ui.fc968a8489fa15f0", "until you submit."),
    },

    session: {
        triesOf: (used: number, max: number) => uiText("vidyalms.ui.abde05337950b29a", "Try {p0} of {p1}", { p0: Math.min(used + 1, max), p1: max }),
        triesUnlimited: (used: number) => uiText("vidyalms.ui.627383aad6e4c6e6", "Try {p0}", { p0: used + 1 }),
        noTriesLeft: uiText("vidyalms.ui.027c3f47112f33ae", "No tries left on this question."),
        check: uiText("vidyalms.ui.59b5b25c3f1225bb", "Check answer"),
        checking: uiText("vidyalms.ui.ec963ffc911b8401", "Checking…"),
        checked: uiText("vidyalms.ui.0060829781e09f3e", "Answer checked."),
        skippingOff: uiText("vidyalms.ui.5e91ac6597bd7c9e", "Every question on this quiz has to be answered before you move on."),
        answerFirst: uiText("vidyalms.ui.aa6b067cc92b5a3a", "Answer this question before you continue."),
        fixFirst:
            uiText("vidyalms.ui.8037c4561b24b861", "This answer does not fit what the question accepts, and this quiz checks before you move on. ") +
            uiText("vidyalms.ui.25a3aa7069cd07a7", "It is saved either way; correct it to continue."),
        reviewOff: uiText("vidyalms.ui.b090bd2b8bfce605", "This question is not shown again in the review step."),
        solutionHeading: uiText("vidyalms.ui.43b0c8f22ee8ac85", "Worked answer"),
        feedbackHeading: uiText("vidyalms.ui.aac77df347205252", "Feedback"),
    },

    expiry: {
        gracePeriodTitle: uiText("vidyalms.ui.1e6dcad96d7251e4", "Time is up."),
        gracePeriodBody: (seconds: number) =>
            uiText("vidyalms.ui.e7710ec8f98d0d4e", "No further answers are accepted. You have about {p0} minute", { p0: Math.round(seconds / 60) }) +
            uiText("vidyalms.ui.c2e283ed6290ee4d", "{p0} to submit what you saved.", { p0: Math.round(seconds / 60) === 1 ? '' : 's' }),
        mustSubmitTitle: uiText("vidyalms.ui.031d3f609809338b", "Submit before the time limit."),
        mustSubmitBody:
            uiText("vidyalms.ui.ca2f5535a78aed65", "This quiz does not count an attempt submitted after its deadline, so submit before the ") +
            uiText("vidyalms.ui.e9f1c3f5b5f4b1e4", "clock reaches zero."),
        manualSubmitTitle: uiText("vidyalms.ui.1e6dcad96d7251e4", "Time is up."),
        manualSubmitBody: uiText("vidyalms.ui.35399700e0927caf", "Submit the attempt to have it marked. Your saved answers are all still there."),
        overdue: uiText("vidyalms.ui.8531a62e9ed0d6f8", "This attempt is past its deadline. It can still be submitted; no further answers are taken."),
        abandoned: uiText("vidyalms.ui.eedc33b95e91f99c", "This attempt passed its deadline without being submitted, so it was not counted."),
    },

    timer: {
        hide: uiText("vidyalms.ui.883636c5ddb8c563", "Hide the countdown"),
        show: uiText("vidyalms.ui.ec918555e00fed91", "Show the countdown"),
        hidden: uiText("vidyalms.ui.3ea49e25a06af525", "The countdown is hidden. The time limit still applies."),
        shown: uiText("vidyalms.ui.66428e22727dfa28", "The countdown is showing again."),
        hiddenNote: uiText("vidyalms.ui.2b0ac16623e25c7e", "Countdown hidden"),
    },

    availability: {
        notOpenTitle: uiText("vidyalms.ui.afe98291a8f5c0dc", "This quiz has not opened yet."),
        opensAt: (when: string) => uiText("vidyalms.ui.6f7c88656a08cc59", "It opens on {p0}.", { p0: when }),
        closedTitle: uiText("vidyalms.ui.9b776e209954e1fa", "This quiz has closed."),
        closedAt: (when: string) => uiText("vidyalms.ui.5334140f5186cf78", "It closed on {p0}.", { p0: when }),
        closesAt: (when: string) => uiText("vidyalms.ui.7c94859ebf230378", "Closes {p0}", { p0: when }),
        cooldownTitle: uiText("vidyalms.ui.87f822174eb7c7ef", "Another attempt is not available yet."),
        cooldownUntil: (when: string) => uiText("vidyalms.ui.b3ed7dba8176aa64", "You can start again on {p0}.", { p0: when }),
        cooldownIn: (clock: string) => uiText("vidyalms.ui.5e7dbc9f6e37ae38", "You can start again in {p0}.", { p0: clock }),
        cooldownWhy:
            uiText("vidyalms.ui.45bc07f12895ba94", "This quiz asks for a gap between attempts. Nothing is lost; your previous attempt is recorded."),
    },

    behaviour: {
        substituted: (asked: string) =>
            uiText("vidyalms.ui.2e57e35d47084ce1", "This quiz asks for the \"{p0}\" marking behaviour, which this site cannot run. ", { p0: asked }) +
            uiText("vidyalms.ui.3496acb1ae366d2f", "The attempt is running under the standard behaviour instead: everything is marked ") +
            uiText("vidyalms.ui.b988d830e9bd50a7", "when you submit."),
    },

    panels: {
        certaintyMissingTitle: uiText("vidyalms.ui.0a82a42ae93f42d7", "The confidence scale is unavailable."),
        certaintyMissingBody:
            uiText("vidyalms.ui.bc0a2130a04954e2", "This quiz asks you to declare how sure you are, and the part of the site that draws that ") +
            uiText("vidyalms.ui.fcada6c382ed1801", "control is not installed. Answer the question as normal; your answer is saved and marked ") +
            uiText("vidyalms.ui.f3e11926f7d7f47e", "without a confidence."),
        certaintyRequiredNote:
            uiText("vidyalms.ui.76a66d13ba257d87", "This quiz normally requires a confidence with each answer. It is not being asked for here."),
        feedbackMissingTitle: uiText("vidyalms.ui.45945da0b2342225", "Per question feedback is unavailable."),
        feedbackMissingBody:
            uiText("vidyalms.ui.6ef4f82eadf1dbb0", "This quiz marks each question as you go, and the part of the site that draws that panel is ") +
            uiText("vidyalms.ui.dc7b38e60fe6b1fb", "not installed. Your answers are saved and marked as normal when you submit."),
        missingPackage: (behaviour: string) => uiText("vidyalms.ui.bb55e1fb09d7dcce", "The behaviour in force is \"{p0}\".", { p0: behaviour }),
    },
} as const;
