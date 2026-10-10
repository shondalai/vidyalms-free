import { translate as uiText } from '@/shared/i18n';
/*
 * Workbench copy for wave 3b: the registry-driven policy form, the slot
 * tree, the question bank, rubrics, regrade and item analysis.
 *
 * Kept in its own catalogue beside strings.ts so this wave adds copy without
 * touching a file every other wave is also writing to. Same rules: one typed
 * object, no scattered literals, no em-dashes and no en-dashes anywhere.
 *
 * Several sentences here state a consequence rather than name a control,
 * because that is the point of the screen they belong to: a regrade changes
 * marks people have already seen, an edit to a question that has been
 * answered writes a new version, and a statistic below its response count is
 * noise rather than evidence.
 */
export const assessmentStrings = {
    tabs: {
        questions: uiText("vidyalms.ui.9a72221a27476f3e", "Questions"),
        slots: uiText("vidyalms.ui.7203c2b3dad364dc", "Structure"),
        bank: uiText("vidyalms.ui.d8c022e8a4f80e72", "Question bank"),
        rubrics: uiText("vidyalms.ui.6bbe30b8b3bcbcb5", "Rubrics"),
        subTabsLabel: uiText("vidyalms.ui.8ce2bb844efb8eac", "Quiz authoring sections"),
        reports: uiText("vidyalms.ui.dacca3cba3f346a4", "Reports"),
    },

    common: {
        loading: uiText("vidyalms.ui.dc380888c4e2c776", "Loading"),
        save: uiText("vidyalms.ui.1509f561f2416598", "Save"),
        saving: uiText("vidyalms.ui.23e39291d6135814", "Saving…"),
        cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
        close: uiText("vidyalms.ui.7d9eb7acb13e2462", "Close"),
        add: uiText("vidyalms.ui.9fd728c66c9a256b", "Add"),
        remove: uiText("vidyalms.ui.c3812fc4acb861d5", "Remove"),
        edit: uiText("vidyalms.ui.464c4ffd019e1e96", "Edit"),
        name: uiText("vidyalms.ui.dcd1d5223f73b3a9", "Name"),
        search: uiText("vidyalms.ui.49c266baaaa70981", "Search"),
        none: uiText("vidyalms.ui.dc937b59892604f5", "None"),
        unlimited: uiText("vidyalms.ui.11dde17d6c3e2098", "Unlimited"),
        default: uiText("vidyalms.ui.21b111cbfe6e8fca", "Default"),
        notSet: uiText("vidyalms.ui.4895f73177ab5d67", "Not set"),
        yes: uiText("vidyalms.ui.85a39ab345d672ff", "Yes"),
        no: uiText("vidyalms.ui.1ea442a134b2a184", "No"),
        retry: uiText("vidyalms.ui.d8b8392e2c542950", "Try again"),
        errorTitle: uiText("vidyalms.ui.a050175f094b8b8a", "Couldn't load."),
        deniedTitle: uiText("vidyalms.ui.d89253d68597f06c", "You don't manage this course."),
        deniedBody: uiText("vidyalms.ui.3de17cd01934a633", "Ask a site administrator to add you as an instructor."),
    },

    policy: {
        button: uiText("vidyalms.ui.4d20d06ea9926e27", "Marking and timing"),
        title: uiText("vidyalms.ui.4d20d06ea9926e27", "Marking and timing"),
        description:
            uiText("vidyalms.ui.2e05a8f13e5c0d08", "Everything below is resolved once when a learner starts an attempt and frozen on that ") +
            uiText("vidyalms.ui.b4391ce82a641848", "attempt, so changing a setting never moves someone who has already started."),
        emptyTitle: uiText("vidyalms.ui.1875bdf976b158b7", "No policy settings"),
        emptyBody: uiText("vidyalms.ui.3580eacdfdaf69e7", "This site offers no quiz policy fields beyond the ones on the settings form."),
        errorTitle: uiText("vidyalms.ui.b2dce7a780e35e5a", "Couldn't load the settings this site can offer."),
        unavailable: (reason: string | null) =>
            reason === null
                ? uiText("vidyalms.ui.b2a93e1c61bf162a", "This quiz is set to something this site cannot run. It is running under the default instead.")
                : reason,
        storedValue: (value: string) => uiText("vidyalms.ui.a25a523eac4f9e09", "Stored value: {p0}", { p0: value }),
        keepStored: (value: string) => uiText("vidyalms.ui.60ddc77b171258cc", "{p0} (not available)", { p0: value }),
        optionParams: uiText("vidyalms.ui.78323eb416f6d929", "Options for this choice"),
        aggregationRule: (counted: string, aggregation: string) =>
            counted === uiText("vidyalms.ui.a7937b64b8caa58f", "first")
                ? uiText("vidyalms.ui.015ddf5a0a7d912e", "The reportable grade is the first attempt. The reduction below has nothing left to choose between.")
                : counted === uiText("vidyalms.ui.3547cb112ac4489a", "last")
                  ? uiText("vidyalms.ui.216c6a3b1a4043b1", "The reportable grade is the last attempt. The reduction below has nothing left to choose between.")
                  : uiText("vidyalms.ui.f9ce21cd53aa0680", "The reportable grade is the {p0} of every attempt.", { p0: aggregation }),
        negativeOff: uiText("vidyalms.ui.06a45e4bd72517e2", "Off. An item is never worth less than zero and a wrong answer costs nothing."),
        negativeOn:
            uiText("vidyalms.ui.27afa0dc5413ebb9", "The learner instructions say so before the attempt starts, every item is floored at its ") +
            uiText("vidyalms.ui.be7493975e01b681", "own rule, and the resolved rule is recorded on the attempt."),
        saved: uiText("vidyalms.ui.fca046ccd586c67f", "Saved."),
    },

    slots: {
        title: uiText("vidyalms.ui.7203c2b3dad364dc", "Structure"),
        description:
            uiText("vidyalms.ui.e087f18b315cd291", "Sections, fixed questions and random draws, in the order a learner meets them. ") +
            uiText("vidyalms.ui.e3ba0f996327560b", "A draw resolves when an attempt starts and never moves again for that attempt."),
        emptyTitle: uiText("vidyalms.ui.169546f49ef308f4", "No structure yet"),
        emptyBody:
            uiText("vidyalms.ui.f395ce16d408ab3c", "This quiz serves its questions in the order they are listed. Add a section or a random ") +
            uiText("vidyalms.ui.06044ede6ed4866e", "draw to change that."),
        errorTitle: uiText("vidyalms.ui.94c6de251b4bb7c6", "Couldn't load the structure of this quiz."),
        addSection: uiText("vidyalms.ui.7c3690997f72c2ea", "Add section"),
        addQuestion: uiText("vidyalms.ui.1fdd53c711ff5c16", "Add question from bank"),
        addPool: uiText("vidyalms.ui.b368b129ab724ad2", "Add random draw"),
        poolCount: uiText("vidyalms.ui.aea08bc1260935ee", "Questions to draw"),
        poolCountHint: uiText("vidyalms.ui.424955c04ec6fa13", "Each learner receives this many questions matching the conditions."),
        poolCreateIntro:
            uiText("vidyalms.ui.7c5a352901dbc7de", "Choose the number of questions and the conditions they must meet. The draw is saved only when you add it."),
        poolConditions: uiText("vidyalms.ui.78216ead6090b380", "Question conditions"),
        section: uiText("vidyalms.ui.dfca5da56b8cdb62", "Section"),
        question: uiText("vidyalms.ui.289aff12b04274cb", "Question"),
        pool: uiText("vidyalms.ui.89f03f71f3d570bf", "Random draw"),
        untitledSection: uiText("vidyalms.ui.fe7fd4374fd637cb", "Untitled section"),
        pinnedVersion: (version: number) => uiText("vidyalms.ui.63df9391e43fb274", "Pinned to version {p0}", { p0: version }),
        latestVersion: uiText("vidyalms.ui.ec9692966dc8c557", "Latest version at attempt start"),
        points: uiText("vidyalms.ui.77deabe238de2f6d", "Points"),
        weight: uiText("vidyalms.ui.81d27ef6d5033c3e", "Weight"),
        required: uiText("vidyalms.ui.93fd1574ad636d1f", "Always included"),
        requiredHint: uiText("vidyalms.ui.f76eb8dae240f8bb", "Drawn before the random remainder. Separate from whether it can move."),
        fixed: uiText("vidyalms.ui.52cc9cd2ee32ca4a", "Never moves"),
        fixedHint: uiText("vidyalms.ui.afd1692f48b8b761", "Stays at this position when the section is shuffled."),
        visible: uiText("vidyalms.ui.c1736a8a1dd7e915", "Shown as a section"),
        keepTogether: uiText("vidyalms.ui.142c19dc5348275f", "Keep together"),
        keepTogetherHint:
            uiText("vidyalms.ui.da66d3e262aace50", "Shuffling never splits this section, which is what keeps a passage with its questions."),
        select: uiText("vidyalms.ui.d49e218f2ff33ffd", "Draw this many"),
        selectHint: uiText("vidyalms.ui.af0b59407e84fb83", "Leave empty to serve every child. Above the child count it is clamped and you are told."),
        withReplacement: uiText("vidyalms.ui.43f4f2e55a956f98", "Allow the same child twice"),
        shuffle: uiText("vidyalms.ui.bf1f489fc0be1cb6", "Shuffle the children"),
        strategy: uiText("vidyalms.ui.935a1830bbea983a", "Selection strategy"),
        strategyHint:
            uiText("vidyalms.ui.87b6880692f7db71", "A uniform draw from a mixed pool makes forms of unequal difficulty, which is a fairness problem."),
        exposure: uiText("vidyalms.ui.509971fe3b609a47", "Exposure control"),
        exposureNone: uiText("vidyalms.ui.6036e00bdd2df367", "None: draw from the whole pool"),
        // A stored key stays selected rather than being silently replaced on
        // the next save, and says why it cannot be resolved.
        providerUnavailable: (key: string) => uiText("vidyalms.ui.03a01bb64af2231b", "{p0} (no installed package provides it)", { p0: key }),
        excludeTypes: uiText("vidyalms.ui.6d493517e9a8d9fc", "Question types to leave out"),
        allowManual: uiText("vidyalms.ui.1a4f30f703c1ec32", "Allow question types a human has to mark"),
        allowManualHint:
            uiText("vidyalms.ui.be29567ecaff8b16", "A randomly drawn essay is legitimate and makes the marking queue non-comparable across ") +
            uiText("vidyalms.ui.c7988b8786a0e227", "learners, so it is off unless you say otherwise."),
        policyKey: uiText("vidyalms.ui.c8320430123d6b26", "Marking rule for this placement"),
        policyKeyHint:
            uiText("vidyalms.ui.db821f98ca3062a3", "Overrides the question and its definition. Responses scored under an override are reported ") +
            uiText("vidyalms.ui.74ec80c7dcff49e7", "separately, because one question scored under two rules is two items to a facility index."),
        query: uiText("vidyalms.ui.55948723f3cdb1e9", "Which questions can be drawn"),
        queryKind: uiText("vidyalms.ui.03c0e806becb1425", "Match"),
        queryOperator: uiText("vidyalms.ui.571527339d0e3e34", "Comparison"),
        queryValue: uiText("vidyalms.ui.8e37953d23daca5f", "Value"),
        queryAddLeaf: uiText("vidyalms.ui.e7c81a8053b8ee87", "Add a condition"),
        queryAddGroup: uiText("vidyalms.ui.2466db329c6826a9", "Add a group"),
        queryRemove: uiText("vidyalms.ui.3b23e189bb691b68", "Remove this condition"),
        queryAll: uiText("vidyalms.ui.d85693fc129a055b", "All of"),
        queryAny: uiText("vidyalms.ui.f44e288e5e41de4a", "Any of"),
        queryNot: uiText("vidyalms.ui.a3cb7fba638e2366", "Not"),
        queryUnknownKind: (kind: string) => uiText("vidyalms.ui.65fde77c8874d65c", "Contributed condition: {p0}", { p0: kind }),
        moveUp: (position: number) => uiText("vidyalms.ui.6f1dcc925f61b59f", "Move item {p0} up", { p0: position }),
        moveDown: (position: number) => uiText("vidyalms.ui.da60716c89589577", "Move item {p0} down", { p0: position }),
        removeNode: (label: string) => uiText("vidyalms.ui.55fbe401207d0466", "Remove {p0}", { p0: label }),
        removeSectionWarning: uiText("vidyalms.ui.28d3f65321526c36", "Removing a section removes everything inside it."),
        warningsTitle: uiText("vidyalms.ui.7b22b5d284b0e028", "Publication checks"),
        warningsBody:
            uiText("vidyalms.ui.4b367b1e939fc072", "These run when a draft becomes ready. A warning never blocks a save; a blocking check ") +
            uiText("vidyalms.ui.9311034b3e31e2ed", "refuses the transition it guards."),
        warningBlocking: uiText("vidyalms.ui.dfaf2ee4b81b5b5c", "Blocks publishing"),
        warningAdvisory: uiText("vidyalms.ui.e981ddae45d8f4ca", "Warning"),
        warningAtItem: uiText("vidyalms.ui.44693c0bed554489", "On this item"),
        noWarnings: uiText("vidyalms.ui.1e73abd4b390db8a", "Nothing to report on this structure."),
    },

    bank: {
        title: uiText("vidyalms.ui.d8c022e8a4f80e72", "Question bank"),
        description:
            uiText("vidyalms.ui.a8b8d88ff1a5da78", "Questions are owned by a course or a category, never by the person who wrote them, so a ") +
            uiText("vidyalms.ui.eeed71d871f329cd", "colleague can reuse one without being added to anything."),
        scope: uiText("vidyalms.ui.b073f6c68ef87211", "Scope"),
        scopes: {
            site: uiText("vidyalms.ui.99205df3a34f5dca", "Whole site"),
            category: uiText("vidyalms.ui.9c21203d06f3d313", "Course category"),
            course: uiText("vidyalms.ui.3a33065e05956ee4", "This course"),
            quiz: uiText("vidyalms.ui.cc985c06a1ac986d", "One quiz"),
        },
        folders: uiText("vidyalms.ui.c4d6bb200f4c058a", "Folders"),
        allFolders: uiText("vidyalms.ui.226fd0e51f770b0b", "All folders"),
        addFolder: uiText("vidyalms.ui.cf28f49ec597a6ed", "New folder"),
        renameFolder: uiText("vidyalms.ui.7249f19c9e4f482a", "Rename folder"),
        removeFolder: uiText("vidyalms.ui.f2a987fb119bf4ba", "Remove folder"),
        folderName: uiText("vidyalms.ui.14d34edf50ef6c87", "Folder name"),
        folderParent: uiText("vidyalms.ui.123a3ebc57e39958", "Inside"),
        folderRoot: uiText("vidyalms.ui.f61dd2542ac99ed5", "Top level"),
        entryCount: (n: number) => (n === 1 ? uiText("vidyalms.ui.d69c3b1839e70205", "1 question") : uiText("vidyalms.ui.7ae3e0f54ca8460e", "{p0} questions", { p0: n })),
        searchPlaceholder: uiText("vidyalms.ui.72160fce86479c04", "Search prompts and tags"),
        filterStatus: uiText("vidyalms.ui.920e413c7d411b61", "Status"),
        filterType: uiText("vidyalms.ui.baaddf70fb5d432b", "Type"),
        anyStatus: uiText("vidyalms.ui.ac78229d6b822ce5", "Any status"),
        anyType: uiText("vidyalms.ui.b05fc1fe6984e8b6", "Any type"),
        status: {
            draft: uiText("vidyalms.ui.ebf12ef47cf575b3", "Draft"),
            ready: uiText("vidyalms.ui.5fa7aac5375c5815", "Ready"),
            archived: uiText("vidyalms.ui.bdb86505f8062d15", "Archived"),
        },
        draftCannotBeUsed: uiText("vidyalms.ui.1ddefb0ec752e918", "A draft cannot be added to a quiz."),
        emptyTitle: uiText("vidyalms.ui.3316d8553be22c0a", "Nothing in this folder"),
        emptyBody: uiText("vidyalms.ui.61f62d53933e622e", "Questions you add to the bank appear here and can be placed in any quiz of this scope."),
        emptyFilteredTitle: uiText("vidyalms.ui.5aaeb6d03e827e62", "No questions match"),
        emptyFilteredBody: uiText("vidyalms.ui.a46ba8c72100fd30", "Clear the search or the filters to see the rest of the folder."),
        errorTitle: uiText("vidyalms.ui.7b40f2f268868cc5", "Couldn't load the question bank."),
        columns: {
            question: uiText("vidyalms.ui.1c5cb9921cbececa", "QUESTION"),
            type: uiText("vidyalms.ui.a90bab6ff81b3d09", "TYPE"),
            status: uiText("vidyalms.ui.8c2e4a035f5f3d21", "STATUS"),
            version: uiText("vidyalms.ui.7b60b8e351cbb80c", "VERSION"),
            used: uiText("vidyalms.ui.f101c435ebd121c7", "USED IN"),
            updated: uiText("vidyalms.ui.3a6e351b6aa6629d", "UPDATED"),
        },
        version: (n: number) => uiText("vidyalms.ui.aa7c5ba4306fc018", "v{p0}", { p0: n }),
        versions: uiText("vidyalms.ui.f89ea27036ac1d31", "Versions"),
        versionsOf: (n: number) => (n === 1 ? uiText("vidyalms.ui.18349b4a0613c828", "1 version") : uiText("vidyalms.ui.4399f41d2aef40d3", "{p0} versions", { p0: n })),
        usedIn: (n: number) => (n === 0 ? uiText("vidyalms.ui.d3682aa27b567f55", "Not placed") : n === 1 ? uiText("vidyalms.ui.357ccfd89ebce8e2", "1 placement") : uiText("vidyalms.ui.aec9abb31a9da54e", "{p0} placements", { p0: n })),
        attemptsServed: (n: number) => (n === 1 ? uiText("vidyalms.ui.b192eff0016adc03", "Served to 1 attempt") : uiText("vidyalms.ui.d2c92b4b84e4b2b9", "Served to {p0} attempts", { p0: n })),
        editWritesVersion:
            uiText("vidyalms.ui.8fc8369280923f08", "This question has been answered. Saving writes a new version; attempts already begun keep ") +
            uiText("vidyalms.ui.1ce787fa8bd5fe60", "the version they were served."),
        pin: uiText("vidyalms.ui.e929b6a5d8dba733", "Pin a version"),
        pinned: (version: number) => uiText("vidyalms.ui.82d7436181099a0e", "Pinned to v{p0}", { p0: version }),
        pinLatest: uiText("vidyalms.ui.c29db5942d656301", "Always the latest at attempt start"),
        tags: uiText("vidyalms.ui.1331275bc537b4c2", "Tags"),
        tagsHint:
            uiText("vidyalms.ui.7268893c6f5bf5f3", "One per line. Tags belong to the entry, so they survive an edit that writes a new version."),
        objectives: uiText("vidyalms.ui.d38eb0fca794558a", "Learning objectives"),
        objectivesHint:
            uiText("vidyalms.ui.7be728e20405a8dd", "Aligned to a framework identifier, stored exactly as the framework writes it, so importing ") +
            uiText("vidyalms.ui.70367f12e34773bb", "the published framework later matches rather than duplicates."),
        objectiveSearch: uiText("vidyalms.ui.cebf7a651880095b", "Search objectives"),
        objectiveNone: uiText("vidyalms.ui.78a428adada6b1f5", "No objectives aligned."),
        difficulty: uiText("vidyalms.ui.70a61f8f1600e557", "Difficulty band"),
        difficultyHint:
            uiText("vidyalms.ui.97d38ca71e570efb", "An author's hint, deliberately not treated as truth. It exists to seed a stratified draw ") +
            uiText("vidyalms.ui.5ebcda59fc5164f7", "before response data arrives."),
        estimatedTime: uiText("vidyalms.ui.163e705a925f6c16", "Estimated time"),
        estimatedTimeHint: uiText("vidyalms.ui.9f5315db00b718b8", "In seconds. Used by a strategy that balances a draw, never shown to a learner."),
        archive: uiText("vidyalms.ui.66f4804ee23ddc09", "Archive"),
        archiveConfirm:
            uiText("vidyalms.ui.f544ef2ed0ab126d", "An archived question leaves every search and every draw. Quizzes that already place it keep ") +
            uiText("vidyalms.ui.d21d9c10eaebd59c", "serving it, and attempts already taken are untouched."),
        archived: uiText("vidyalms.ui.727cbf31af204ff9", "Archived."),
        usageTitle: uiText("vidyalms.ui.89784537be259755", "Where this question is used"),
        usageEmpty: uiText("vidyalms.ui.8d6e49509928bf3e", "This question is not placed in any quiz."),
        usagePlacement: (quiz: string, kind: string) =>
            kind === uiText("vidyalms.ui.27cac5503836765c", "pool") ? uiText("vidyalms.ui.949c686ae98992ef", "{p0} (eligible for a draw)", { p0: quiz }) : uiText("vidyalms.ui.9aba30272d3aa256", "{p0} (placed)", { p0: quiz }),
    },

    rubrics: {
        title: uiText("vidyalms.ui.6bbe30b8b3bcbcb5", "Rubrics"),
        description:
            uiText("vidyalms.ui.8a110df01ca078b9", "A rubric is versioned. Editing criteria or levels writes a new version, because a grade ") +
            uiText("vidyalms.ui.7b07a89c21793c7a", "records the level a rater chose and historic grades must keep meaning what they meant."),
        emptyTitle: uiText("vidyalms.ui.367f6b6a083ed700", "No rubrics yet"),
        emptyBody: uiText("vidyalms.ui.dbeae71c19d16e9a", "A rubric turns a marking judgement into named levels a second rater can repeat."),
        errorTitle: uiText("vidyalms.ui.ca518427816dc19f", "Couldn't load the rubrics."),
        create: uiText("vidyalms.ui.2188b3aa35662012", "New rubric"),
        editTitle: uiText("vidyalms.ui.4f1560c29c78ceb8", "Edit rubric"),
        createTitle: uiText("vidyalms.ui.2188b3aa35662012", "New rubric"),
        name: uiText("vidyalms.ui.dcd1d5223f73b3a9", "Name"),
        kind: uiText("vidyalms.ui.f5387f9bb6ed7031", "Kind"),
        kindAnalytic: uiText("vidyalms.ui.ea8dec161bac6486", "Analytic (one judgement per criterion)"),
        kindHolistic: uiText("vidyalms.ui.9e8e6433cb04046b", "Holistic (one judgement overall)"),
        kindHint:
            uiText("vidyalms.ui.be27d67d2355e4e8", "Both ship, and analytic is the default; the reliability evidence does not favour one outright."),
        minScoreIsZero: uiText("vidyalms.ui.8ca7e0077bbb925a", "The lowest level is worth zero"),
        criteria: uiText("vidyalms.ui.71708699acf8f3b3", "Criteria"),
        criterion: uiText("vidyalms.ui.3162a17a51df9fb7", "Criterion"),
        criterionDescription: uiText("vidyalms.ui.d12ded8d37d1a2f6", "What this criterion judges"),
        criterionWeight: uiText("vidyalms.ui.81d27ef6d5033c3e", "Weight"),
        levels: uiText("vidyalms.ui.b14821319ef8b550", "Levels"),
        levelDescriptor: uiText("vidyalms.ui.580f3e1df03066f3", "What this level looks like"),
        levelPoints: uiText("vidyalms.ui.77deabe238de2f6d", "Points"),
        addCriterion: uiText("vidyalms.ui.1efb7935d7995b36", "Add criterion"),
        addLevel: uiText("vidyalms.ui.d55a99ac1912686d", "Add level"),
        removeCriterion: (position: number) => uiText("vidyalms.ui.9f97ae18eb196524", "Remove criterion {p0}", { p0: position }),
        removeLevel: (position: number) => uiText("vidyalms.ui.86e6be7baa72570e", "Remove level {p0}", { p0: position }),
        maxPoints: (points: number) => uiText("vidyalms.ui.f61ccbcd2b359d54", "Maximum {p0}", { p0: points }),
        versionNote: uiText("vidyalms.ui.08983c1acdc4db0b", "Saving criteria writes a new version and leaves every grade already given alone."),
        needsCriterion: uiText("vidyalms.ui.bafa387d459ea230", "Add at least one criterion."),
        needsLevel: uiText("vidyalms.ui.af185e4b15dcf8b0", "Every criterion needs at least one level."),
        versionsTitle: uiText("vidyalms.ui.f89ea27036ac1d31", "Versions"),
        versionRow: (version: number, when: string) => uiText("vidyalms.ui.44375875743b68dc", "Version {p0}, saved {p1}", { p0: version, p1: when }),
    },

    grading: {
        rubricHeading: uiText("vidyalms.ui.f13a785009892b1f", "Rubric"),
        rubricVersion: (version: number) => uiText("vidyalms.ui.a77cf0dec4d711dc", "Version {p0}", { p0: version }),
        criterionLevel: (criterion: string) => uiText("vidyalms.ui.18a8922511871c93", "Level for {p0}", { p0: criterion }),
        criterionComment: (criterion: string) => uiText("vidyalms.ui.61666cb5b616b6eb", "Comment on {p0}", { p0: criterion }),
        runningTotal: (awarded: number, max: number) => uiText("vidyalms.ui.7667c1971df2d65e", "{p0} of {p1}", { p0: awarded, p1: max }),
        runningTotalLabel: uiText("vidyalms.ui.5039cd0c5c4253e7", "Running total"),
        pickLevel: uiText("vidyalms.ui.441530f46aea910e", "Choose a level"),
        noLevel: uiText("vidyalms.ui.bd31ab95f4f3544f", "No level chosen"),
        levelPoints: (points: number) => (points === 1 ? uiText("vidyalms.ui.863fa0f57870c1ea", "1 point") : uiText("vidyalms.ui.cba84808e76e19f5", "{p0} points", { p0: points })),
        overridePoints: uiText("vidyalms.ui.77deabe238de2f6d", "Points"),
        overrideHint:
            uiText("vidyalms.ui.ff9d932074b72cbd", "Inside the level you chose, for a half judgement. Leave it to take the level as it stands."),
        everyCriterion: uiText("vidyalms.ui.662c06c9b27a160e", "Choose a level for every criterion before grading."),
        gradeWithRubric: uiText("vidyalms.ui.a3aa7ad322f8e4ce", "Grade with this rubric"),
        rubricSum:
            uiText("vidyalms.ui.e30015eb18144cfa", "The score is the sum of the levels you chose. The level is stored beside the points, which ") +
            uiText("vidyalms.ui.bd29a2f62ab5bd4f", "is what makes rater agreement and a safe rubric revision possible later."),
    },

    regrade: {
        button: uiText("vidyalms.ui.2eed0eef09035c5b", "Regrade"),
        title: uiText("vidyalms.ui.3d3ac502ba0754e9", "Regrade this quiz"),
        description:
            uiText("vidyalms.ui.8247eeaf0b6b79ee", "A regrade re-runs marking against what each learner was actually served and appends new ") +
            uiText("vidyalms.ui.e40da2590136ae7a", "grade rows. Nothing is overwritten and the history stays intact."),
        policy: uiText("vidyalms.ui.f0d25441d3f23e91", "What to award"),
        policies: {
            corrected_key_only: uiText("vidyalms.ui.cd50d88b9b9239d4", "The corrected answer only"),
            both_keys: uiText("vidyalms.ui.bf9da13722d4b3da", "The corrected answer and the previous one"),
            any_answer: uiText("vidyalms.ui.6bcaa13b9cd337e2", "Any answer that was given"),
            full_credit: uiText("vidyalms.ui.fab7082ed3961b77", "Full marks to everyone"),
        },
        consequences: {
            corrected_key_only:
                uiText("vidyalms.ui.92b1c6b33165fede", "Scores can go down. A learner who matched the old answer and not the new one loses those marks."),
            both_keys: uiText("vidyalms.ui.bd48d2b134c731f2", "No score goes down. A learner who matched either answer keeps the marks."),
            any_answer:
                uiText("vidyalms.ui.bcd138f34e60e014", "Everyone who answered gets the marks, whatever they wrote. Blank answers still score zero."),
            full_credit: uiText("vidyalms.ui.51f7938be6e65809", "Everyone gets the marks, including learners who left the question blank."),
        },
        questions: uiText("vidyalms.ui.9a72221a27476f3e", "Questions"),
        questionsAll: uiText("vidyalms.ui.51f175bf22b06bfc", "Every question of this quiz"),
        questionsSome: uiText("vidyalms.ui.d7a54117e8f1dae0", "Only the questions I choose"),
        reason: uiText("vidyalms.ui.45d2642eb64415b9", "Why (recorded with the new grades)"),
        reasonPlaceholder: uiText("vidyalms.ui.69f088cab63df357", "The key on question 4 was wrong."),
        dryRun: uiText("vidyalms.ui.fe910bf8815a40d2", "Show me what would change"),
        apply: uiText("vidyalms.ui.c06d2bf584309ff5", "Regrade now"),
        applying: uiText("vidyalms.ui.825e409515145629", "Regrading…"),
        resultTitle: uiText("vidyalms.ui.ab5dd5b1417ce1bb", "What would change"),
        resultAppliedTitle: uiText("vidyalms.ui.1bcbf1dcad4ffe49", "What changed"),
        examined: (n: number) => (n === 1 ? uiText("vidyalms.ui.7bf752ea6736a76b", "1 attempt examined") : uiText("vidyalms.ui.ad420503d21c4f7e", "{p0} attempts examined", { p0: n })),
        changed: (n: number) => (n === 1 ? uiText("vidyalms.ui.9155fc260cd9b9ad", "1 attempt moved") : uiText("vidyalms.ui.b5197519226cbdc4", "{p0} attempts moved", { p0: n })),
        appended: (n: number) => (n === 1 ? uiText("vidyalms.ui.2ade8a6bf7ce61c7", "1 grade row appended") : uiText("vidyalms.ui.8a53a2c21e1cf410", "{p0} grade rows appended", { p0: n })),
        nothingChanged: uiText("vidyalms.ui.537a9378f1aa0ab9", "No attempt would move under this rule."),
        nothingChangedApplied: uiText("vidyalms.ui.a4a8b675e8a010d4", "No attempt moved."),
        columns: {
            learner: uiText("vidyalms.ui.5bd30e46da2eb644", "LEARNER"),
            attempt: uiText("vidyalms.ui.4af308f2bf99cb23", "ATTEMPT"),
            before: uiText("vidyalms.ui.c341c7fe1027f948", "BEFORE"),
            after: uiText("vidyalms.ui.94c3f1415d2061d0", "AFTER"),
            result: uiText("vidyalms.ui.a67d2a6c9087c1bc", "RESULT"),
        },
        attemptNumber: (n: number) => uiText("vidyalms.ui.c9766a11e688c06b", "Attempt {p0}", { p0: n }),
        passed: uiText("vidyalms.ui.436fe71bb9561f05", "Passed"),
        notPassed: uiText("vidyalms.ui.519157af297e57fa", "Not passed"),
        noScore: uiText("vidyalms.ui.97fa618e9415c384", "No score"),
        stillDryRun: uiText("vidyalms.ui.24706adcc7d558ce", "Nothing has been written yet."),
        appliedNote: uiText("vidyalms.ui.ea3248077d372ba3", "The new grades are recorded. The previous rows are still there."),
        confirm: uiText("vidyalms.ui.c06d2bf584309ff5", "Regrade now"),
        confirmWarning: (attempts: number) =>
            attempts === 1
                ? uiText("vidyalms.ui.897a8383e354d245", "This changes the recorded mark of 1 attempt a learner may already have seen.")
                : uiText("vidyalms.ui.092e9e8866c61326", "This changes the recorded mark of {p0} attempts learners may already have seen.", { p0: attempts }),
    },

    reports: {
        title: uiText("vidyalms.ui.148e8da9f84c9830", "Item analysis"),
        description:
            uiText("vidyalms.ui.3c9568cadf6cf164", "How each question behaved: how many got it right, how well it separated stronger from ") +
            uiText("vidyalms.ui.ecd1778e99eccbd6", "weaker learners, and which options attracted whom."),
        quiz: uiText("vidyalms.ui.03aa7dc16797a82b", "Quiz"),
        allQuizzes: uiText("vidyalms.ui.99051efc730ad061", "Every quiz of this course"),
        errorTitle: uiText("vidyalms.ui.f30539f3391cc3f2", "Couldn't load the item analysis."),
        notComputedTitle: uiText("vidyalms.ui.887ca1b681d24837", "No statistics on this site yet"),
        notComputedBody:
            uiText("vidyalms.ui.97bb7a636732e11a", "Every build records what was served, at which version, with the raw answers and the marks. ") +
            uiText("vidyalms.ui.650d3f35a94e1110", "Nothing on this site computes the statistics from them yet, and installing something that ") +
            uiText("vidyalms.ui.cceb679ca62f4bfe", "does will report on every attempt already taken."),
        emptyTitle: uiText("vidyalms.ui.53b414cb533df519", "Nothing to report yet"),
        emptyBody: uiText("vidyalms.ui.7323d385d3c24b87", "Statistics appear once learners have answered these questions."),
        minResponses: (n: number) =>
            uiText("vidyalms.ui.7744ea009f4e9c06", "Discrimination and option rates are withheld below {p0} responses for a version, because ", { p0: n }) +
            uiText("vidyalms.ui.0e6cc4a8fa0b7d87", "the index is noise below that. Facility is shown earlier with its confidence interval."),
        internalConsistency: (value: number) => uiText("vidyalms.ui.2cce7f768d369cac", "Internal consistency: {p0}", { p0: value }),
        columns: {
            question: uiText("vidyalms.ui.1c5cb9921cbececa", "QUESTION"),
            n: uiText("vidyalms.ui.8ce86a6ae65d3692", "N"),
            facility: uiText("vidyalms.ui.3c116527cf8c1dc0", "FACILITY"),
            sd: uiText("vidyalms.ui.495496f0156b1a6c", "SD"),
            discrimination: uiText("vidyalms.ui.73b445112f9ee049", "DISCRIMINATION"),
            weight: uiText("vidyalms.ui.754bc03a1c16d902", "WEIGHT"),
        },
        version: (version: number) => uiText("vidyalms.ui.aa7c5ba4306fc018", "v{p0}", { p0: version }),
        policyKey: (key: string) => uiText("vidyalms.ui.21eaaab604591802", "marked by {p0}", { p0: key }),
        belowThreshold: (n: number) => uiText("vidyalms.ui.4c47bc44be12bbce", "{p0} responses so far", { p0: n }),
        confidence: (low: number, high: number) => uiText("vidyalms.ui.db67227395e1fcd8", "{p0} to {p1}", { p0: low, p1: high }),
        bands: {
            excellent: uiText("vidyalms.ui.0dc8ee555c28ec1b", "Excellent"),
            good: uiText("vidyalms.ui.c939327ca16dcf97", "Good"),
            review: uiText("vidyalms.ui.aff0766a5290e117", "Review"),
            revise: uiText("vidyalms.ui.f1323f09f63c867d", "Revise"),
            negative: uiText("vidyalms.ui.24777a8fd61cc501", "Key is probably wrong"),
        },
        bandsHint:
            uiText("vidyalms.ui.ddceaa6c04462d17", "0.40 and above is excellent, 0.30 to 0.39 good, 0.20 to 0.29 worth a review, below 0.20 ") +
            uiText("vidyalms.ui.be1b666364fa1f76", "worth revising. A negative value means the answer key is probably wrong."),
        distractorsTitle: uiText("vidyalms.ui.d0db8b5e364b6989", "Options"),
        distractorColumns: {
            option: uiText("vidyalms.ui.3e57b132a4b258aa", "OPTION"),
            upper: uiText("vidyalms.ui.ce7a7f50ccef90bb", "TOP 27%"),
            lower: uiText("vidyalms.ui.4005c4bef203eeed", "BOTTOM 27%"),
            biserial: uiText("vidyalms.ui.bb19a167664e5760", "CORRELATION"),
        },
        keyed: uiText("vidyalms.ui.aca01a1a3a27d35c", "Correct"),
        flagged: uiText("vidyalms.ui.2f1978c6166e8980", "Flagged"),
        showDistractors: (label: string) => uiText("vidyalms.ui.4854d8a64afd9bc2", "Show the option breakdown for {p0}", { p0: label }),
        weights: (intended: number | null, effective: number | null) =>
            intended === null || effective === null
                ? uiText("vidyalms.ui.9292f2b0825d4fe5", "Not computed")
                : uiText("vidyalms.ui.fd9e4bc3440ce3fd", "intended {p0}, effective {p1}", { p0: intended, p1: effective }),
    },

    form: {
        required: uiText("vidyalms.ui.f0cc895dbe49439a", "This cannot be empty."),
        wholeNumber: uiText("vidyalms.ui.b0a800d6ab5e582d", "Enter a whole number."),
        number: uiText("vidyalms.ui.7b2968fb595b02f1", "Enter a number."),
        atLeast: (minimum: number) => uiText("vidyalms.ui.1d8c9eeba6078911", "Enter {p0} or more.", { p0: minimum }),
        atMost: (maximum: number) => uiText("vidyalms.ui.676f568830e4a630", "Enter {p0} or less.", { p0: maximum }),
        between: (minimum: number, maximum: number) => uiText("vidyalms.ui.a488a2bbc696a22c", "Enter a number between {p0} and {p1}.", { p0: minimum, p1: maximum }),
        datetime: uiText("vidyalms.ui.e148fb3f0e4eb4b9", "Use the format YYYY-MM-DD hh:mm:ss."),
        seconds: uiText("vidyalms.ui.59f006d63bd009b7", "seconds"),
        minutes: uiText("vidyalms.ui.90e63d85fa1ab2c8", "minutes"),
        durationHint: uiText("vidyalms.ui.861ee468919bf24e", "In minutes."),
        notSaved: uiText("vidyalms.ui.6b2ac9b14eefa0b5", "Couldn't save."),
    },
} as const;
