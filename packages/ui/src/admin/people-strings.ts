import { translate as uiText } from '@/shared/i18n';
/*
 * Stage 2 copy for the two people directories. Kept beside the screens while
 * the shared admin catalogue is being migrated by another slice; every label
 * here is still centralized and ready for the adapter translation bridge.
 */

const plural = (value: number, one: string, many: string) => (value === 1 ? one : many);

export const learnerDirectoryStrings = {
    table: uiText("vidyalms.ui.cb2aeff482af223e", "Learner directory"),
    description: uiText("vidyalms.ui.9fd607b00f787dac", "Search for a person across the exact, server-paged directory of everyone with learning history."),
    searchPlaceholder: uiText("vidyalms.ui.1f42c6d5fad84de0", "Search learner name, username, or email"),
    empty: uiText("vidyalms.ui.7c710e08e212aa3a", "No learners match this search."),
    accountRemoved: uiText("vidyalms.ui.3211542d28560929", "Account removed"),
    removedMeta: (id: number) => uiText("vidyalms.ui.c75b51376a0cd575", "Learning history retained · user #{p0}", { p0: id }),
    open: uiText("vidyalms.ui.7c035f7a130bcea5", "Open record"),
    openNamed: (name: string) => uiText("vidyalms.ui.8e5531bb13602d0a", "Open the record of {p0}", { p0: name }),
    unavailable: uiText("vidyalms.ui.929ae77e7ff0435c", "Record unavailable"),
    columns: {
        learner: uiText("vidyalms.ui.0815286c81c0a2b1", "Learner"),
        courses: uiText("vidyalms.ui.aaad9d92e6b47727", "Courses"),
        active: uiText("vidyalms.ui.92340695899bd2d8", "Active"),
        completed: uiText("vidyalms.ui.22a970d2e5b1cc23", "Completed"),
        progress: uiText("vidyalms.ui.00a4f26b0364dd7c", "Average progress"),
        lastActive: uiText("vidyalms.ui.bcdf701c4dfbaee3", "Last active"),
    },
    sortLabel: uiText("vidyalms.ui.847edc4fa155f156", "Sort learners"),
    sortOptions: {
        lastActive: uiText("vidyalms.ui.6cb44b56336af70b", "Recent activity"),
        progress: uiText("vidyalms.ui.00a4f26b0364dd7c", "Average progress"),
        courses: uiText("vidyalms.ui.0c1bce4f42237b98", "Course count"),
        enrolled: uiText("vidyalms.ui.b7920c4fbcb880df", "Recently enrolled"),
    },
    noActivity: uiText("vidyalms.ui.0a12b92ce7899469", "No activity yet"),
    courseCount: (count: number) => uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: count, p1: plural(count, 'course', 'courses') }),
    progressLabel: (name: string) => uiText("vidyalms.ui.a97039ec5fe7a441", "Average progress for {p0}", { p0: name }),
} as const;

export const enrolmentExperienceStrings = {
    statsLabel: uiText("vidyalms.ui.62fcd2c4cc6f56f1", "Enrolment summary"),
    stats: {
        active: uiText("vidyalms.ui.405e8f025a0904b5", "Active enrolments"),
        expiring: uiText("vidyalms.ui.52ad328468d6dcc7", "Expiring soon"),
        suspended: uiText("vidyalms.ui.e3f9193dda9a4a10", "Suspended access"),
        current: uiText("vidyalms.ui.1d03bc60b4a19e81", "current access"),
        withinDays: (days: number) => uiText("vidyalms.ui.bba16cc8af9aa23a", "within {p0} days", { p0: days }),
        progressSafe: uiText("vidyalms.ui.9fb850e55b83ec4d", "learning history retained"),
    },
    columns: {
        progress: uiText("vidyalms.ui.4664827f8e890192", "Progress"),
        status: uiText("vidyalms.ui.920e413c7d411b61", "Status"),
    },
    progressLabel: (learner: string, course: string) => uiText("vidyalms.ui.484c73f698f3ae02", "{p0}'s progress in {p1}", { p0: learner, p1: course }),
} as const;

export const learnerRecordExperienceStrings = {
    summaryLabel: (name: string) => uiText("vidyalms.ui.1c92c6ac59dafe10", "{p0} summary", { p0: name }),
    activeCourses: uiText("vidyalms.ui.d046902743fde142", "Active courses"),
    totalCourses: (count: string) => uiText("vidyalms.ui.586e31df7440efef", "{p0} total", { p0: count }),
    averageProgress: uiText("vidyalms.ui.00a4f26b0364dd7c", "Average progress"),
    acrossCourses: uiText("vidyalms.ui.b3e5a2990e123932", "across all courses"),
    certificates: uiText("vidyalms.ui.16f637921eba69b0", "Certificates"),
    issuedCredentials: uiText("vidyalms.ui.de99b3eb03c48eff", "issued credentials"),
} as const;
