import { translate as uiText } from '@/shared/i18n';
/*
 * Quiz runner copy. Same rules as src/shared/strings.ts: one typed object,
 * no scattered literals, no em-dashes and no en-dashes anywhere. Plain and
 * factual tone per the Foundations artboard.
 */
import { questionTypeStrings } from '@/question-types/strings';

export const quizRunnerStrings = {
    region: uiText("vidyalms.ui.03aa7dc16797a82b", "Quiz"),
    loading: uiText("vidyalms.ui.cf25813d35a74d0a", "Loading quiz"),

    boot: {
        noLessonTitle: uiText("vidyalms.ui.100f42476680d91b", "No quiz selected"),
        noLessonBody: uiText("vidyalms.ui.6949afa0f837c3a5", "The page did not tell the quiz runner which lesson to open."),
        notAQuizTitle: uiText("vidyalms.ui.7fcf86e9dcadbd4c", "No quiz here"),
        notAQuizBody: uiText("vidyalms.ui.30eaad699650de71", "This lesson does not contain a quiz."),
    },

    permission: {
        title: uiText("vidyalms.ui.30794e5236dbc9af", "This quiz isn't available to you."),
        forbiddenBody: uiText("vidyalms.ui.c6eaa7d349225491", "It belongs to a course you're not enrolled in."),
        signInBody: uiText("vidyalms.ui.73c27c81d39848a0", "Sign in to continue."),
        viewCourse: uiText("vidyalms.ui.b1a12dfcf7bee4c8", "View the course"),
    },

    /*
     * A restriction on the quiz lesson. The runner honours it before it
     * offers a start, because the server refuses the attempt anyway and an
     * invitation the product already knows will be refused is worse than a
     * plain sentence. The reason sentences live in shared/availability.ts,
     * so this surface and the player say the same thing about one key.
     */
    availability: {
        authoritative: (when: string, zone: string) => uiText("vidyalms.ui.bacc17b4d71b35f4", "{p0} in {p1}", { p0: when, p1: zone }),
        kept: uiText("vidyalms.ui.3ed388694c536cd2", "Your previous attempts and scores are saved. Nothing is lost while this quiz is closed."),
        backToCourse: uiText("vidyalms.ui.b1a12dfcf7bee4c8", "View the course"),
    },

    summary: {
        chip: uiText("vidyalms.ui.f1f26dd1bf4fb3ee", "QUIZ"),
        completesOnPass: (pct: number) => uiText("vidyalms.ui.21d2925e8f545004", "Completes when you pass with {p0}% or more", { p0: pct }),
        completesOnSubmit: uiText("vidyalms.ui.e6858136a9b6f0b2", "Completes when you submit an attempt"),
        statQuestions: uiText("vidyalms.ui.17d5efa695de036a", "questions"),
        statTimeLimit: uiText("vidyalms.ui.28f331be680e3c92", "time limit"),
        statPassMark: uiText("vidyalms.ui.b9d6987b975d95b8", "pass mark"),
        statAttemptsLeft: uiText("vidyalms.ui.9a059192bda5c036", "attempts left"),
        none: uiText("vidyalms.ui.dc937b59892604f5", "None"),
        unlimited: uiText("vidyalms.ui.11dde17d6c3e2098", "Unlimited"),
        attemptsOf: (left: number, max: number) => uiText("vidyalms.ui.7667c1971df2d65e", "{p0} of {p1}", { p0: left, p1: max }),
        previousAttempts: uiText("vidyalms.ui.10840fda5e49bdc9", "Previous attempts"),
        colScore: uiText("vidyalms.ui.38e5a46cbc5ad328", "Score"),
        colResult: uiText("vidyalms.ui.6e7d50e84f4731ef", "Result"),
        attemptRow: (n: number) => uiText("vidyalms.ui.c9766a11e688c06b", "Attempt {p0}", { p0: n }),
        passed: uiText("vidyalms.ui.436fe71bb9561f05", "Passed"),
        notPassed: uiText("vidyalms.ui.519157af297e57fa", "Not passed"),
        pendingGrade: uiText("vidyalms.ui.48519d60760ba2c9", "Pending grade"),
        recorded: uiText("vidyalms.ui.c7175fa7a0db0d3c", "Recorded"),
        scoreWithheld: '·',
        resultsHidden: uiText("vidyalms.ui.f74b165704c2fbd0", "This quiz does not reveal scores. Attempts are recorded and count toward completion."),
        bestScore: (pct: number) => uiText("vidyalms.ui.cbdc05e6d435ce1c", "Best score: {p0}%", { p0: pct }),
        timerStartsWhenYouDo: uiText("vidyalms.ui.479ee91a48d95bc5", "The timer starts when you do"),
        start: (n: number) => uiText("vidyalms.ui.f379fb89f9de8b0f", "Start attempt {p0} ›", { p0: n }),
        resume: uiText("vidyalms.ui.12d45555ff834e46", "Resume attempt ›"),
        resumeHint: uiText("vidyalms.ui.6d21ed6aae44d89e", "An attempt is in progress. The timer continues where it left off."),
        backToCourse: uiText("vidyalms.ui.fa7dcce95a411284", "Back to course"),
    },

    noAttempts: {
        title: uiText("vidyalms.ui.e0053507ebc9937f", "No attempts remaining"),
        bodyWithBest: (max: number, best: number) =>
            uiText("vidyalms.ui.c358597edfbbaa6d", "You've used all {p0} attempts. Your best score, {p1}%, is recorded. ", { p0: max, p1: best }) +
            uiText("vidyalms.ui.8ae896d25a461f97", "If you think you need another attempt, your instructor can grant one."),
        body: (max: number) =>
            uiText("vidyalms.ui.106415eaafbf8c67", "You've used all {p0} attempts. ", { p0: max }) +
            uiText("vidyalms.ui.8ae896d25a461f97", "If you think you need another attempt, your instructor can grant one."),
        reviewLast: uiText("vidyalms.ui.2966caa6907ac389", "Review last attempt"),
    },

    attempt: {
        attemptOf: (n: number, max: number | null) => (max === null ? uiText("vidyalms.ui.c9766a11e688c06b", "Attempt {p0}", { p0: n }) : uiText("vidyalms.ui.cfb93f1bd5f4e0e1", "Attempt {p0} of {p1}", { p0: n, p1: max })),
        passMark: (pct: number) => uiText("vidyalms.ui.30dd15e8b5604fba", "pass mark {p0}%", { p0: pct }),
        questionOf: (n: number, total: number) => uiText("vidyalms.ui.1378eb43dac24c8e", "Question {p0} of {p1}", { p0: n, p1: total }),
        questionsOf: (from: number, to: number, total: number) => uiText("vidyalms.ui.8146f5e8485c9633", "Questions {p0} to {p1} of {p2}", { p0: from, p1: to, p2: total }),
        stimulus: uiText("vidyalms.ui.30ef88556ad5aa01", "Passage"),
        newerFormat:
            uiText("vidyalms.ui.9a01c4edae1164f3", "This question is stored in a newer format than this page can show, so it could not be displayed. It will not be counted for or against you."),
        longDescription: uiText("vidyalms.ui.3bae97ded74025e6", "Long description"),
        points: (p: number) => (p === 1 ? uiText("vidyalms.ui.863fa0f57870c1ea", "1 point") : uiText("vidyalms.ui.cba84808e76e19f5", "{p0} points", { p0: p })),
        answeredCount: (n: number) => uiText("vidyalms.ui.c50e97608bc66d2c", "answered {p0}", { p0: n }),
        previous: uiText("vidyalms.ui.5d026d14cd555394", "‹ Previous"),
        skip: uiText("vidyalms.ui.b58eb52c8810b978", "Skip for now"),
        next: uiText("vidyalms.ui.65a9d952cfa5eedf", "Next question ›"),
        toReview: uiText("vidyalms.ui.82d2122d2abbce48", "Review answers ›"),
        progressLabel: uiText("vidyalms.ui.b0ab352ac58d9860", "Question progress"),
        paletteCurrent: uiText("vidyalms.ui.97b0560280ed60a5", "current"),
        paletteAnswered: uiText("vidyalms.ui.68c780cd132a0299", "answered"),
        paletteUnanswered: uiText("vidyalms.ui.09a7ad964232f792", "not answered"),
        paletteItem: (n: number, state: string) => uiText("vidyalms.ui.de89b883e361c596", "Question {p0}: {p1}", { p0: n, p1: state }),
    },

    /* The learner-facing copy of the six free types moved beside the
       modules that register them, so the instructor bundle can use the same
       strings without importing the runner. */
    questions: questionTypeStrings,

    timer: {
        pillOf: (total: string) => uiText("vidyalms.ui.c552c01a0ce3f37f", "of {p0}", { p0: total }),
        remainingLabel: (remaining: string, total: string) => uiText("vidyalms.ui.230734d7d837dd8c", "{p0} remaining of {p1}", { p0: remaining, p1: total }),
        remainingOnly: (remaining: string) => uiText("vidyalms.ui.c953bb867866a4ec", "{p0} remaining", { p0: remaining }),
        left: uiText("vidyalms.ui.360f84035942243c", "left"),
        fiveMinutes: uiText("vidyalms.ui.9c954442682edc41", "5 minutes remaining."),
        twoMinutes: uiText("vidyalms.ui.9f7330b62abd9804", "Two minutes left."),
        warningBody:
            uiText("vidyalms.ui.8e4b410c96b643cf", "Your answers are already saved. The time limit of this quiz is fixed and cannot be extended; ") +
            uiText("vidyalms.ui.36a12da264a6ef5b", "if time runs out, the attempt is submitted as it stands. Nothing is lost."),
        submitNow: uiText("vidyalms.ui.ebec0faee4dd85f6", "Submit now"),
        timeUp: uiText("vidyalms.ui.b2f4ea6239679452", "Time is up. Your attempt is being submitted with the answers you saved."),
        expiredNote: uiText("vidyalms.ui.e6baed90f616cedc", "Time ran out. The attempt was submitted with the answers you had saved."),
    },

    offline: {
        lostTitle: uiText("vidyalms.ui.610d5eb0f98b7731", "Connection lost."),
        lostBody: uiText("vidyalms.ui.422ad28e68b1cd92", "Keep going. Answers are stored on this device and sent when you're back online."),
        waitingToSync: (n: number) => (n === 1 ? uiText("vidyalms.ui.e4136b0aa25e9a83", "1 answer waiting to sync") : uiText("vidyalms.ui.0cb87f9210d52f5a", "{p0} answers waiting to sync", { p0: n })),
        reconnecting: uiText("vidyalms.ui.27b80374e1151af6", "Reconnecting…"),
    },

    review: {
        title: uiText("vidyalms.ui.2a37895023daf20b", "Review your answers"),
        meta: (quiz: string, n: number, max: number | null) =>
            max === null ? uiText("vidyalms.ui.3ddf3f983c173205", "{p0} · attempt {p1}", { p0: quiz, p1: n }) : uiText("vidyalms.ui.4bb42967e88c3a4d", "{p0} · attempt {p1} of {p2}", { p0: quiz, p1: n, p2: max }),
        timeLeft: (clock: string) => uiText("vidyalms.ui.ac5015292eec4d9e", "◷ {p0} left", { p0: clock }),
        answered: uiText("vidyalms.ui.68c780cd132a0299", "answered"),
        skipped: uiText("vidyalms.ui.389595a44dc9dce7", "skipped"),
        unansweredWarning: (n: number) => (n === 1 ? uiText("vidyalms.ui.aeb5f74a3aab615e", "1 question unanswered.") : uiText("vidyalms.ui.dd136665a198a159", "{p0} questions unanswered.", { p0: n })),
        unansweredBody: uiText("vidyalms.ui.abaac01acfd9dc46", "Unanswered questions score zero. Tap a number to go back to it."),
        goToQuestion: (n: number, state: string) => uiText("vidyalms.ui.1ba42b16a0e1c40d", "Go to question {p0}, {p1}", { p0: n, p1: state }),
        keepAnswering: uiText("vidyalms.ui.c7a8a31d8fb95e5a", "Keep answering"),
        submit: uiText("vidyalms.ui.d5c2512f8c3d634a", "Submit attempt"),
        submitting: uiText("vidyalms.ui.49195f559e4a9f5b", "Submitting…"),
    },

    conflict: {
        title: uiText("vidyalms.ui.bfac39843a440d80", "This attempt hit a conflict."),
        body: uiText("vidyalms.ui.885b68453d120d4f", "Your answers are saved with the attempt and nothing was lost. Reload the attempt to see where it stands."),
        retry: uiText("vidyalms.ui.044b599bf9c5dfd2", "Reload attempt"),
    },

    submitError: {
        title: uiText("vidyalms.ui.93576d09f4ec8f94", "Couldn't submit."),
        body:
            uiText("vidyalms.ui.5bd479bb7a0af618", "The server didn't respond. Your answers are safe on this device and nothing was lost. ") +
            uiText("vidyalms.ui.2a1c5e0b11b0be34", "We'll retry automatically; you can also retry yourself."),
        retry: uiText("vidyalms.ui.296c5cf0897e4ae4", "Retry submit"),
        autoRetry: (s: number, k: number, max: number) => uiText("vidyalms.ui.19b088595c264ccd", "Auto-retry in {p0}s (attempt {p1} of {p2})", { p0: s, p1: k, p2: max }),
        autoRetryStopped: uiText("vidyalms.ui.f0146923e2c8f90b", "Automatic retries stopped. Use the button when you are ready."),
    },

    result: {
        passedTitle: uiText("vidyalms.ui.436fe71bb9561f05", "Passed"),
        failedTitle: uiText("vidyalms.ui.519157af297e57fa", "Not passed"),
        submittedTitle: uiText("vidyalms.ui.c433200e7bc48840", "Attempt submitted"),
        markedSoFar: (pct: number) => uiText("vidyalms.ui.3779039012bae5c4", "Marked so far: {p0}%", { p0: pct }),
        meta: (parts: string[]) => parts.join(' · '),
        percent: (pct: number) => uiText("vidyalms.ui.d4bdf581de91640f", "{p0}%", { p0: pct }),
        passMark: (pct: number) => uiText("vidyalms.ui.30dd15e8b5604fba", "pass mark {p0}%", { p0: pct }),
        attemptOf: (n: number, max: number | null) => (max === null ? uiText("vidyalms.ui.882bc5506ff4becd", "attempt {p0}", { p0: n }) : uiText("vidyalms.ui.75152881fbf19908", "attempt {p0} of {p1}", { p0: n, p1: max })),
        tryAgain: (left: number | null) => (left === null ? uiText("vidyalms.ui.d8b8392e2c542950", "Try again") : uiText("vidyalms.ui.37ed0baacaff588a", "Try again ({p0} left)", { p0: left })),
        backToCourse: uiText("vidyalms.ui.fa7dcce95a411284", "Back to course"),
        reviewAnswers: uiText("vidyalms.ui.decd394b995b6ec5", "Review answers"),
        hideAnswers: uiText("vidyalms.ui.abeb825780f0c189", "Hide answers"),
        pendingBody: (count: number, sharePct: number) =>
            count === 1
                ? uiText("vidyalms.ui.af03dd64ce178fc0", "One answer ({p0}% of the total) needs your instructor. ", { p0: sharePct }) +
                  uiText("vidyalms.ui.d2c5d8363c7ce304", "Your final result and pass or fail will appear here once it's marked.")
                : uiText("vidyalms.ui.2c2eabd666ba6567", "{p0} answers ({p1}% of the total) need your instructor. ", { p0: count, p1: sharePct }) +
                  uiText("vidyalms.ui.974f1ca0611b30ec", "Your final result and pass or fail will appear here once marking is complete."),
        pendingHiddenBody:
            uiText("vidyalms.ui.1b197dfe9b0d6fb9", "At least one answer is marked by a person. Your final result will appear here once marking is complete."),
        hiddenResultsBody: uiText("vidyalms.ui.af41796a52a5e734", "This quiz does not show detailed results. Your submission was recorded."),
        questionShort: (n: number) => uiText("vidyalms.ui.a0bf1d3de77bc074", "Q{p0}", { p0: n }),
        autoMarkedRow: (ranges: string) => uiText("vidyalms.ui.d4e5c0da3694a9d2", "{p0} · auto-marked", { p0: ranges }),
        awaitingRow: (n: number, typeLabel: string) => uiText("vidyalms.ui.5356c2648b1809db", "Q{p0} · {p1} · awaiting instructor", { p0: n, p1: typeLabel }),
        pendingPts: (max: string) => uiText("vidyalms.ui.2607ee38ca25314d", "? / {p0} pt", { p0: max }),
        pts: (awarded: string, max: string) => uiText("vidyalms.ui.17659f2824c43f7a", "{p0} / {p1} pts", { p0: awarded, p1: max }),
        rowPts: (awarded: string, max: string) => uiText("vidyalms.ui.d833987afd69125f", "{p0}/{p1}", { p0: awarded, p1: max }),
        yourAnswer: uiText("vidyalms.ui.adceb15c665f2cad", "Your answer:"),
        feedbackLabel: uiText("vidyalms.ui.c266ec8162232fcf", "Feedback:"),
        noAnswer: uiText("vidyalms.ui.7e49c68db30e39dc", "No answer"),
        correct: uiText("vidyalms.ui.aca01a1a3a27d35c", "Correct"),
        incorrect: uiText("vidyalms.ui.a8a57dbc076774bf", "Incorrect"),
        partlyCorrect: uiText("vidyalms.ui.153271edeadaebd7", "Partly correct"),
        awaitingInstructor: uiText("vidyalms.ui.0e621d946cf7c146", "Awaiting instructor"),
    },

    typeLabels: {
        single_choice: uiText("vidyalms.ui.f8be04e38aee367a", "single choice"),
        multiple_choice: uiText("vidyalms.ui.adca531e572f2b74", "multiple choice"),
        true_false: uiText("vidyalms.ui.ed50d4017aafc9d8", "true or false"),
        short_answer: uiText("vidyalms.ui.185644b4c4cbf61e", "short answer"),
        fill_blank: uiText("vidyalms.ui.2540f530a86552f2", "fill in the blank"),
        ordering: uiText("vidyalms.ui.eee949668040f46c", "ordering"),
    } as Record<string, string>,
} as const;

export type QuizRunnerStrings = typeof quizRunnerStrings;
