import { translate as uiText } from '@/shared/i18n';
export const assessmentCopy = {
    assessment: uiText("vidyalms.ui.d00607cae8c427cf", "ASSESSMENT"),
    backToLesson: uiText("vidyalms.ui.0a5313dfc4122b86", "Back to lesson"),
    beforeYouBegin: uiText("vidyalms.ui.daa1953973c364bf", "BEFORE YOU BEGIN"),
    introduction: uiText("vidyalms.ui.b767822fdce79f08", "A little preparation. A clearer mind."),
    description: uiText("vidyalms.ui.894b27e12f15a649", "Review the details below, then begin when you are ready."),
    answered: (count: number, total: number) => uiText("vidyalms.ui.32f3c17cbb9db730", "{p0} of {p1} answered", { p0: count, p1: total }),
    questionMap: uiText("vidyalms.ui.e389fa621b51c555", "Your questions"),
    reviewKicker: uiText("vidyalms.ui.fd8fe36ce7c0759d", "ONE LAST LOOK"),
    reviewDescription: uiText("vidyalms.ui.55b8a156a41d8bcf", "Check your progress before submitting. You can return to any answer that this assessment allows you to review."),
    results: uiText("vidyalms.ui.8387e31db6b9e781", "YOUR RESULTS"),
    saved: uiText("vidyalms.ui.9e3a5ae7cc34df21", "Answers save automatically as you go."),
} as const;
