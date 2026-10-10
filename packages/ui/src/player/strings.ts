import { translate as uiText } from '@/shared/i18n';
/*
 * Player copy. Same rules as src/shared/strings.ts: one typed object, no
 * scattered literals, so localisation can land later. Tone per the
 * Foundations artboard: plain, neutral, factual. No em-dashes and no
 * en-dashes anywhere.
 */
export const playerStrings = {
    app: {
        region: uiText("vidyalms.ui.ae9b818bf6df331d", "Course player"),
    },
    header: {
        progressLabel: uiText("vidyalms.ui.0b6ace10ea9d8386", "Course progress"),
        lessonsComplete: (done: number, total: number) => uiText("vidyalms.ui.a7a3a26077fb1c7a", "{p0} of {p1} lessons complete", { p0: done, p1: total }),
        courseMap: uiText("vidyalms.ui.7e058a3e21174417", "Course map"),
        courseMapDescription: uiText("vidyalms.ui.ad6e0612cd269a86", "All modules and lessons of this course."),
        rule: {
            allLessons: uiText("vidyalms.ui.14ec948a25ff77fb", "completes when all lessons are done"),
            percentage: (threshold: number) => uiText("vidyalms.ui.dafa8efc7bc0cb43", "completes at {p0}% progress", { p0: threshold }),
            passFinal: uiText("vidyalms.ui.125505ec42a2ccdd", "completes when you pass the final quiz"),
        },
    },
    rail: {
        label: uiText("vidyalms.ui.7cd3e13eddc3538a", "Course curriculum"),
        module: (n: number, title: string) => uiText("vidyalms.ui.957cf8cbf6014abe", "Module {p0} · {p1}", { p0: n, p1: title }),
        empty: {
            title: uiText("vidyalms.ui.10389e2bc90f00da", "No lessons yet"),
            body: uiText("vidyalms.ui.467bf242469fe574", "This course has no published lessons."),
            action: uiText("vidyalms.ui.4fb333ad156d4b35", "View course page"),
        },
    },
    chips: {
        /* Short rail labels (artboard 1a) and the FILE extension fallback. */
        rail: {
            reading: uiText("vidyalms.ui.3f5637410846d545", "READ"),
            video: uiText("vidyalms.ui.65b2f9ec331fc726", "VIDEO"),
            resource: uiText("vidyalms.ui.9ef2faffd23e7fdd", "FILE"),
            quiz: uiText("vidyalms.ui.f1f26dd1bf4fb3ee", "QUIZ"),
            assignment: uiText("vidyalms.ui.e3091486247e1aa0", "TASK"),
        } as Record<string, string>,
        fileFallback: uiText("vidyalms.ui.9ef2faffd23e7fdd", "FILE"),
    },
    lessonState: {
        notStarted: uiText("vidyalms.ui.ba35f0c47d862763", "Not started"),
        inProgress: uiText("vidyalms.ui.c1f88e9d6c4145cf", "In progress"),
        completed: uiText("vidyalms.ui.143b270a32602d41", "Complete"),
        awaitingGrade: uiText("vidyalms.ui.a999bd6486b3cff4", "Awaiting grade"),
        blocked: uiText("vidyalms.ui.787406ddea2a10a4", "Not available yet"),
    },
    /*
     * Availability copy. The reason sentences themselves live in
     * src/shared/availability.ts because the quiz runner renders the same
     * keys, and two copies of one sentence is how two surfaces start
     * disagreeing about what a blocked item says.
     */
    availability: {
        authoritative: (when: string, zone: string) => uiText("vidyalms.ui.bacc17b4d71b35f4", "{p0} in {p1}", { p0: when, p1: zone }),
        kept: uiText("vidyalms.ui.20441b24315ff803", "Everything you have done so far is saved. Nothing is lost while this item is closed."),
        backToCourse: uiText("vidyalms.ui.4fb333ad156d4b35", "View course page"),
        moduleBlocked: uiText("vidyalms.ui.2542c175763d1dcf", "This module is not open yet"),
        notCounted: uiText("vidyalms.ui.b72860fd518d7ead", "Some items are not counted towards completion."),
    },
    meta: {
        lesson: (moduleNo: number, lessonNo: number) => uiText("vidyalms.ui.3708d3b2bea262c9", "Lesson {p0}.{p1}", { p0: moduleNo, p1: lessonNo }),
        minutes: (n: number) => uiText("vidyalms.ui.618d152970777caa", "{p0} min", { p0: n }),
        minutesRead: (n: number) => uiText("vidyalms.ui.b11a260b3423f8e3", "{p0} min read", { p0: n }),
        completes: {
            reading: uiText("vidyalms.ui.b9d8e2c5b4349eff", "completes when you reach the end"),
            video: uiText("vidyalms.ui.ecd9458915790cae", "completes when 90% watched"),
            resource: uiText("vidyalms.ui.918980a71ad27637", "completes when you download the file"),
            assignment: uiText("vidyalms.ui.f99ae460a6fcc0ff", "completes when approved by your instructor"),
            quizPass: (mark: number) => uiText("vidyalms.ui.8d588248ddb740ea", "completes when you pass with {p0}% or more", { p0: mark }),
            quizAny: uiText("vidyalms.ui.026bd1def6700f91", "completes when you finish the quiz"),
        },
    },
    nav: {
        previous: uiText("vidyalms.ui.a57b08a480b822a0", "Previous"),
        continue: uiText("vidyalms.ui.31fbef162594de01", "Continue"),
        continueAnyway: uiText("vidyalms.ui.873567be309f124e", "Continue anyway"),
        lessonComplete: uiText("vidyalms.ui.fd8ea0a29a653cac", "Lesson complete"),
        offlineVideoHint: uiText("vidyalms.ui.ddca957f912da03d", "Video lessons need a connection"),
    },
    reading: {
        positionSaved: uiText("vidyalms.ui.5912a184e66761a2", "Your progress saves automatically as you learn."),
        completeHint: uiText("vidyalms.ui.12598a866166dfb8", "Marks complete at the end of the page"),
        noContent: {
            title: uiText("vidyalms.ui.7ba6d7fe18743390", "Nothing to read yet"),
            body: uiText("vidyalms.ui.86b8c8ca0bfb993e", "This lesson has no content."),
        },
    },
    video: {
        watchedHint: (percent: number) => uiText("vidyalms.ui.43d92be25d3d5abb", "{p0}% watched, keep going to complete", { p0: percent }),
        openExternally: uiText("vidyalms.ui.707ef9e091c10454", "Open the video in a new tab"),
        unavailable: {
            title: uiText("vidyalms.ui.09656ba7e709d9ff", "This video cannot be embedded"),
            body: uiText("vidyalms.ui.9b9c5262a7a8dda2", "The video address is not one this player can show."),
        },
        tabs: {
            label: uiText("vidyalms.ui.278522ce00cd00ad", "Lesson details"),
            transcript: uiText("vidyalms.ui.721164f0dc7096a8", "Transcript"),
            about: uiText("vidyalms.ui.bcf9d86049ebf27a", "About this lesson"),
            downloads: (n: number) => uiText("vidyalms.ui.691da1b2c7465d47", "Downloads ({p0})", { p0: n }),
        },
        transcriptEmpty: {
            title: uiText("vidyalms.ui.5ae4bb109db7971e", "No transcript yet"),
            body: uiText("vidyalms.ui.df5192aaddeb3617", "This lesson has no transcript."),
        },
        captionNote: uiText("vidyalms.ui.e96c42f0f0345b18", "This video has captions; turn them on with the CC control in the player."),
        aboutEmpty: {
            title: uiText("vidyalms.ui.e5f2eea1f7651295", "No description yet"),
            body: uiText("vidyalms.ui.863ed94ca01215f4", "This lesson has no description."),
        },
        downloadsEmpty: {
            title: uiText("vidyalms.ui.c650d0d48eb81c95", "No downloads"),
            body: uiText("vidyalms.ui.7c976751c1fb4c54", "This lesson has no downloadable files."),
        },
    },
    resource: {
        download: uiText("vidyalms.ui.d6eafe8235910042", "Download"),
        fileMeta: (size: string) => size,
        downloadedTitle: uiText("vidyalms.ui.dfa19d8efdc25735", "Downloaded ✓"),
        downloadedBody: uiText("vidyalms.ui.0a3284ea3bc10231", "Lesson complete. The file stays available here any time."),
        noAccess: uiText("vidyalms.ui.87183a96c8967760", "Files unlock when you enrol."),
        noFiles: {
            title: uiText("vidyalms.ui.cb922b5840ae9d83", "No files yet"),
            body: uiText("vidyalms.ui.7c976751c1fb4c54", "This lesson has no downloadable files."),
        },
    },
    assignment: {
        stageSubmitted: uiText("vidyalms.ui.64900440a8c9a5ed", "Submitted"),
        stageAwaiting: uiText("vidyalms.ui.a999bd6486b3cff4", "Awaiting grade"),
        stageGraded: uiText("vidyalms.ui.54bd179e950cef81", "Graded"),
        stagesLabel: uiText("vidyalms.ui.8be6349ed215b71d", "Assignment progress"),
        currentStage: uiText("vidyalms.ui.6845ab1f78b654f8", "current stage"),
        openNote:
            uiText("vidyalms.ui.c0e7c594e154743a", "Submit your work below. Your instructor must approve it before this lesson is complete."),
        awaitingNote:
            uiText("vidyalms.ui.1de7b3d1efcee0ff", "Your submission is with your instructor. Steps that require this assignment stay locked until approval."),
        gradedNote: uiText("vidyalms.ui.da526e89e112874b", "Your submission is approved and this lesson is complete."),
        noInstructions: {
            title: uiText("vidyalms.ui.3a6170c5480d43f6", "No instructions yet"),
            body: uiText("vidyalms.ui.f079f89c6d2345d9", "This assignment has no instructions."),
        },
    },
    quiz: {
        statQuestions: uiText("vidyalms.ui.17d5efa695de036a", "questions"),
        statTimeLimit: uiText("vidyalms.ui.28f331be680e3c92", "time limit"),
        statPassMark: uiText("vidyalms.ui.b9d6987b975d95b8", "pass mark"),
        statAttemptsLeft: uiText("vidyalms.ui.9a059192bda5c036", "attempts left"),
        statNone: uiText("vidyalms.ui.dc937b59892604f5", "None"),
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
        bestScore: (percent: number) => uiText("vidyalms.ui.cbdc05e6d435ce1c", "Best score: {p0}%", { p0: percent }),
        timerHint: uiText("vidyalms.ui.479ee91a48d95bc5", "The timer starts when you do"),
        start: (n: number) => uiText("vidyalms.ui.69329c6ed02b262e", "Start attempt {p0}", { p0: n }),
        resume: uiText("vidyalms.ui.04a268ba1c8724c7", "Resume attempt"),
        inProgressNote: uiText("vidyalms.ui.4219d03f8b0de401", "You have an attempt in progress. Your answers are saved."),
        noAttemptsLeft: uiText("vidyalms.ui.c3e9d9af942d450d", "No attempts left. Your best score stands."),
        noQuiz: {
            title: uiText("vidyalms.ui.b06d9932d5fd89d1", "No quiz yet"),
            body: uiText("vidyalms.ui.2df84a688c81d12a", "This lesson has no quiz linked to it."),
        },
    },
    pro: {
        title: (type: string) => uiText("vidyalms.ui.8c0ff6cc1d3169c6", "This lesson uses a {p0} add-on", { p0: type }),
        body: uiText("vidyalms.ui.f32060a69c8c6ceb", "Playing it needs VidyaLMS Pro, which isn't installed on this site. The lesson data is intact."),
    },
    unknownType: {
        title: uiText("vidyalms.ui.42fa5de58e0c61f6", "This lesson type is not available"),
        body: uiText("vidyalms.ui.1e11289f83b10ce2", "This player cannot show this lesson type yet. The lesson data is intact."),
    },
    states: {
        loading: uiText("vidyalms.ui.f80e2bbeac817f7d", "Loading the course"),
        error: {
            title: uiText("vidyalms.ui.b8ced0e44757c07a", "This course couldn't load"),
            body: uiText("vidyalms.ui.bf5f1b833bbe869b", "The server didn't respond. Your progress is safe on the server, nothing is lost."),
            bodyWithCode: (code: number) =>
                uiText("vidyalms.ui.836a88ccaf9148e8", "The server didn't respond (error {p0}). Your progress is safe on the server, nothing is lost.", { p0: code }),
            backToMyLearning: uiText("vidyalms.ui.e67643ca0fb90ee6", "Back to my learning"),
        },
        notEnrolled: {
            title: uiText("vidyalms.ui.71883382d1d226a4", "You're not enrolled in this course"),
            body: uiText("vidyalms.ui.7e33777617c8cc3b", "If you enrolled with a different account, sign in with that one. Otherwise the course page has enrolment options."),
            viewCourse: uiText("vidyalms.ui.4fb333ad156d4b35", "View course page"),
            backToMyLearning: uiText("vidyalms.ui.e67643ca0fb90ee6", "Back to my learning"),
        },
        suspended: {
            title: uiText("vidyalms.ui.46faf37d9262b6e3", "Your access has ended"),
            kept: (done: number, total: number) =>
                uiText("vidyalms.ui.bf99e96bab46bb67", "Everything you did is saved: {p0} of {p1} lessons, your quiz scores and your submissions. It picks up exactly here when access is renewed.", { p0: done, p1: total }),
            keptNoCounts:
                uiText("vidyalms.ui.ff42ee6e8ab27cb6", "Everything you did is saved and picks up exactly here when access is renewed."),
            renew: uiText("vidyalms.ui.e4e4c5cde5895e17", "Renew access"),
            viewCourse: uiText("vidyalms.ui.4fb333ad156d4b35", "View course page"),
        },
        offline: {
            title: uiText("vidyalms.ui.d552c02931681233", "You're offline."),
            body: uiText("vidyalms.ui.eacaed237b158622", "You can keep reading this lesson. Progress is kept on this device and syncs when you reconnect."),
        },
        complete: {
            title: uiText("vidyalms.ui.13ffd6a664d681f0", "Course complete"),
            finished: (total: number) => uiText("vidyalms.ui.6cfb3f71af6c3d57", "All {p0} lessons done", { p0: total }),
            certificateTitle: uiText("vidyalms.ui.b7a3ca502be869a7", "Certificate of completion"),
            certificateCode: (code: string) => uiText("vidyalms.ui.05110f395a212bca", "Verification code {p0} · anyone can check it", { p0: code }),
            reviewLessons: uiText("vidyalms.ui.6bfadc4732eb79ac", "Review lessons"),
            browseMore: uiText("vidyalms.ui.c51e33617aae0866", "Browse more courses"),
        },
        paneError: {
            title: uiText("vidyalms.ui.6f59c0c8fa7bdd6a", "This lesson couldn't load"),
        },
        /* The quiz runner's permission card, worded for a lesson. */
        permission: {
            title: uiText("vidyalms.ui.962ecf8d94fb7c14", "This lesson isn't available to you."),
            signInBody: uiText("vidyalms.ui.73c27c81d39848a0", "Sign in to continue."),
            forbiddenBody: uiText("vidyalms.ui.c6eaa7d349225491", "It belongs to a course you're not enrolled in."),
            viewCourse: uiText("vidyalms.ui.b1a12dfcf7bee4c8", "View the course"),
        },
    },
    /*
     * A visitor previewing without an enrolment sees the lesson; what the
     * player must not do is promise a completion, a submission or an
     * attempt the server will refuse.
     */
    preview: {
        hint: uiText("vidyalms.ui.b7e6ab6158d6c530", "Free preview. Your progress is not saved."),
        assignment: uiText("vidyalms.ui.513de7fea25401ab", "This is a free preview. Enrol in the course to submit your work."),
        quiz: uiText("vidyalms.ui.c8df68650a4e107e", "This is a free preview. Enrol in the course to take this quiz."),
    },
    announce: {
        lessonOpened: (title: string) => uiText("vidyalms.ui.b06115a0b4f7ca57", "Lesson opened: {p0}", { p0: title }),
        lessonComplete: (title: string) => uiText("vidyalms.ui.e82a91b4b69de669", "Lesson complete: {p0}", { p0: title }),
        courseComplete: uiText("vidyalms.ui.13ffd6a664d681f0", "Course complete"),
        /* Polite, and it never navigates: an unlock is news, not a command. */
        unlocked: (title: string) => uiText("vidyalms.ui.50df8c54c882287b", "{p0} is now available.", { p0: title }),
    },
} as const;

export type PlayerStrings = typeof playerStrings;
