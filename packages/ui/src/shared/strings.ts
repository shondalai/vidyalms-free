import { translate as uiText } from '@/shared/i18n';
import { localizedStrings } from '@/shared/i18n';
/*
 * Every user-facing string of the shared layer lives here, typed, so
 * localisation can land later without hunting literals. Each app keeps its
 * own strings module beside this one (src/player/strings.ts,
 * src/quiz-runner/strings.ts) following the same shape.
 *
 * Copy tone per the Foundations artboard: plain, neutral, factual. No
 * em-dashes, no en-dashes, anywhere.
 *
 * The table is built through localizedStrings because this module is in the
 * shared chunk, which learner-navigation.js evaluates on pages that carry no
 * catalogue; see src/shared/i18n.ts.
 */
export const sharedStrings = localizedStrings(() => ({
    loading: uiText("vidyalms.ui.dc380888c4e2c776", "Loading"),
    retry: uiText("vidyalms.ui.d8b8392e2c542950", "Try again"),
    close: uiText("vidyalms.ui.7d9eb7acb13e2462", "Close"),
    save: {
        saving: uiText("vidyalms.ui.23e39291d6135814", "Saving…"),
        saved: uiText("vidyalms.ui.b5c120b316c237a0", "Saved"),
        savedJustNow: uiText("vidyalms.ui.87a3343c58d6d818", "Saved · just now"),
        savedAgo: (ago: string) => uiText("vidyalms.ui.bc9d266058a6a5f2", "Saved · {p0}", { p0: ago }),
        failed: uiText("vidyalms.ui.c03c674a807e8eb1", "Not saved · retrying"),
        retryNow: uiText("vidyalms.ui.af52ced97d6c70d7", "retry now"),
        paused: uiText("vidyalms.ui.a836895b63939fee", "Sync paused"),
    },
    time: {
        justNow: uiText("vidyalms.ui.7ddb44d8a533a753", "just now"),
        secondsAgo: (n: number) => uiText("vidyalms.ui.5f836e5f5b8a61a5", "{p0}s ago", { p0: n }),
        minutesAgo: (n: number) => uiText("vidyalms.ui.9be3a96fc228f2c7", "{p0}m ago", { p0: n }),
        hoursAgo: (n: number) => uiText("vidyalms.ui.a62d013be846c4cc", "{p0}h ago", { p0: n }),
    },
    pagination: {
        previous: uiText("vidyalms.ui.ad218904c8503e6a", "‹ Prev"),
        next: uiText("vidyalms.ui.868d16703db4cee3", "Next ›"),
        page: (n: number) => uiText("vidyalms.ui.dfeb8707a5c4f210", "Page {p0}", { p0: n }),
    },
    courseCategory: {
        searchPlaceholder: uiText("vidyalms.ui.00da3740e3878b82", "Search course categories"),
        searching: uiText("vidyalms.ui.77c9f1a64efccdaa", "Searching categories"),
        results: uiText("vidyalms.ui.da8e83b7838c2aa8", "Course category results"),
        pages: uiText("vidyalms.ui.be720065003cd11f", "Course category pages"),
        noMatches: uiText("vidyalms.ui.d04472f7cbfa376b", "No categories match this search."),
        none: uiText("vidyalms.ui.b91b9cac5041cc5e", "No category"),
        change: uiText("vidyalms.ui.c0bf75bd78bf9572", "Change"),
        clear: uiText("vidyalms.ui.83b12c2216efb4fd", "Clear"),
        manage: uiText("vidyalms.ui.49cf9ec62cef55f5", "Manage categories"),
        edit: uiText("vidyalms.ui.21610dd6f1db38ba", "Edit category"),
        choose: uiText("vidyalms.ui.1eb0412bee6d9349", "Choose category"),
        opensInNewTab: uiText("vidyalms.ui.1d1833565142090c", "(opens in a new tab)"),
        missing: (id: number) => uiText("vidyalms.ui.b70de2be6d97cd8b", "Category {p0} is no longer available. Choose another category or clear it.", { p0: id }),
        unavailable: uiText("vidyalms.ui.c337cbb9c65564d8", "Categories could not be loaded."),
        states: {
            unpublished: uiText("vidyalms.ui.b6e07310433505db", "Unpublished"),
            archived: uiText("vidyalms.ui.bdb86505f8062d15", "Archived"),
            trashed: uiText("vidyalms.ui.4ec0b99444af10a1", "Trashed"),
        },
    },
    errors: {
        couldNotLoad: uiText("vidyalms.ui.a050175f094b8b8a", "Couldn't load."),
        noResponse: uiText("vidyalms.ui.b637121c32e792f6", "The server didn't respond."),
        somethingWrong: uiText("vidyalms.ui.0c953ab32c603ec4", "Something went wrong."),
        signInRequired: uiText("vidyalms.ui.73c27c81d39848a0", "Sign in to continue."),
        notAllowed: uiText("vidyalms.ui.571aec4398307005", "You don't have access to this."),
        notFound: uiText("vidyalms.ui.ef519a684ec81462", "This wasn't found. It may have been removed."),
        conflict: uiText("vidyalms.ui.5a63b298060f4eea", "This conflicts with the current state. Reload and try again."),
        validation: uiText("vidyalms.ui.48719b518591a0d5", "Some fields need attention."),
        offlineTitle: uiText("vidyalms.ui.4fbda14953066a0c", "Offline."),
        offlineBody: uiText("vidyalms.ui.8db571aadd660bc3", "Your work is kept on this device and will sync when you reconnect."),
    },
    session: {
        expiredTitle: uiText("vidyalms.ui.f7bcf7483dbdb97f", "Your session has expired."),
        expiredBody:
            uiText("vidyalms.ui.bea5b8ace7d01b74", "The page was open long enough for the sign-in token to time out. ") +
            uiText("vidyalms.ui.abc8bdc191be5d53", "Everything you did is saved. Reload to sign in again and continue where you left off."),
        reload: uiText("vidyalms.ui.374fa2815b5d0b9e", "Reload and sign in again"),
    },
    upload: {
        uploading: uiText("vidyalms.ui.8b898ca34ce71371", "Uploading"),
        progress: uiText("vidyalms.ui.11a8bfc000fce25a", "Upload progress"),
        uploaded: (name: string) => uiText("vidyalms.ui.0027b1836bb863af", "{p0} uploaded.", { p0: name }),
        limit: (size: string) => uiText("vidyalms.ui.8f2f968f577e927c", "Up to {p0}.", { p0: size }),
        limitTyped: (size: string, types: string) => uiText("vidyalms.ui.7afde026e73a87ab", "Up to {p0}. Accepted: {p1}.", { p0: size, p1: types }),
        empty: uiText("vidyalms.ui.df8ae7fb82013ee8", "That file is empty."),
        tooLarge: (limit: string) => uiText("vidyalms.ui.bc98a5c92daf78ed", "That file is larger than {p0}. Choose a smaller one.", { p0: limit }),
        wrongType: (types: string) => uiText("vidyalms.ui.b96cea9325032b0b", "That file type is not accepted here. Accepted: {p0}.", { p0: types }),
        failed: uiText("vidyalms.ui.10085db7e0762832", "The upload didn't finish. Nothing was stored."),
        cancel: uiText("vidyalms.ui.326d9c957d5a6cd9", "Cancel upload"),
        cancelled: uiText("vidyalms.ui.c3359ea46d4768d4", "The upload was cancelled. Nothing was stored."),
        malformed: uiText("vidyalms.ui.edce64cd0c979608", "The upload answered something this app could not read. Nothing was stored."),
        noAddress: uiText("vidyalms.ui.e2fb99afcf7b0f2a", "The file was stored, but the site answered no address for it. Nothing was filled in."),
        refused: (status: number) => uiText("vidyalms.ui.051f500c54c54a55", "The site refused the upload (status {p0}).", { p0: status }),
    },
    config: {
        malformedTitle: uiText("vidyalms.ui.5d53377ba6aa28b2", "VidyaLMS could not start."),
        malformedBody: uiText("vidyalms.ui.206d1d7fae60ef40", "The page embedded this app with a missing or invalid configuration."),
    },
    boundary: {
        title: uiText("vidyalms.ui.0c953ab32c603ec4", "Something went wrong."),
        body: uiText("vidyalms.ui.c45d515a3fb84304", "The app hit an unexpected error."),
    },
} as const));

export type SharedStrings = typeof sharedStrings;
