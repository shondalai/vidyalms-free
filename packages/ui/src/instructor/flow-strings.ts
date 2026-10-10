import { translate as uiText } from '@/shared/i18n';
/*
 * Wave 4a copy of the workbench: restrictions, runs and notification rules.
 *
 * Same rules as instructor/strings.ts. One typed object, no scattered
 * literals, plain and factual tone, and no em-dashes or en-dashes anywhere.
 *
 * The box is labelled "Restrict access" because that is the word an author
 * searches for, and the two controls inside it are "Add restriction" and
 * "Add restriction set" for the same reason. Copying the vocabulary is
 * deliberate: an instructor moving from another product should not have to
 * learn a new name for the thing they already know how to do.
 */
export const flowStrings = {
    availability: {
        title: uiText("vidyalms.ui.0b18fffc41dc1c11", "Restrict access"),
        intro: uiText("vidyalms.ui.363459ed99545c94", "Control when this item opens and who can access it. Restrictions are optional."),
        empty: uiText("vidyalms.ui.f3f0072f2755cb33", "No restrictions added"),
        emptyHint: uiText("vidyalms.ui.d91eae255783c7a3", "Learners can open this item when their course access allows it."),
        emptySet: uiText("vidyalms.ui.1cd3ac610599b887", "Add a restriction to this set, then choose how its conditions combine."),
        addRestriction: uiText("vidyalms.ui.321a591767fafd77", "Add restriction"),
        addSet: uiText("vidyalms.ui.f0eb38bf006b40c3", "Add restriction set"),
        quickSetup: uiText("vidyalms.ui.7cf66ba7dbbb0f48", "Quick setup"),
        advanced: uiText("vidyalms.ui.8f58d324c8c86ae8", "Advanced rules"),
        setHint: uiText("vidyalms.ui.cc3e7344039969f4", "Group restrictions to combine alternatives, such as passing a quiz or completing an assignment."),
        remove: uiText("vidyalms.ui.c3812fc4acb861d5", "Remove"),
        edit: uiText("vidyalms.ui.464c4ffd019e1e96", "Edit"),
        done: uiText("vidyalms.ui.11a6767d5674c7e4", "Done"),
        save: uiText("vidyalms.ui.640c979cc24b5be2", "Save restrictions"),
        saving: uiText("vidyalms.ui.23e39291d6135814", "Saving…"),
        clear: uiText("vidyalms.ui.d311de9a6ccb0e58", "Remove all restrictions"),
        preview: uiText("vidyalms.ui.fd91f4d5bb720361", "Preview as a learner"),
        operators: {
            and: uiText("vidyalms.ui.c0ea8001deebfd63", "All of these must be true"),
            or: uiText("vidyalms.ui.b653c9106aeab187", "Any one of these must be true"),
            not_and: uiText("vidyalms.ui.a6011bbcb17823a7", "Not all of these are true"),
            not_or: uiText("vidyalms.ui.8e7d0d6ec8b82a6d", "None of these are true"),
        } as Record<string, string>,
        operatorLabel: uiText("vidyalms.ui.1a8247aa86a2d1ef", "How these combine"),
        setLabel: (n: number) => uiText("vidyalms.ui.aa3c8429c9f0989f", "Restriction set {p0}", { p0: n }),
        shown: uiText("vidyalms.ui.0976a8c717cc6c16", "Shown with the reason"),
        hidden: uiText("vidyalms.ui.74c698e6c276400f", "Hidden entirely"),
        showToggle: uiText("vidyalms.ui.febffc1144d7ef53", "Show this restriction to learners who do not meet it"),
        showHint: uiText("vidyalms.ui.0905083b28e7bc31", "Show the item with an explanation, or hide it from the course outline until access is allowed."),
        combinedShowHint:
            uiText("vidyalms.ui.84f34c910173a3a0", "Under this operator a failure means every condition failed, so there is one message for the whole set")
            + uiText("vidyalms.ui.0c9cd36abfdd7170", " rather than one per condition."),
        defaultVisibility: uiText("vidyalms.ui.92000ddaae93ea62", "When a learner does not meet this"),
        advisory:
            uiText("vidyalms.ui.490f48f23f34a6d2", "Advisory only. This reads a value the learner can change, so it is not a security control and must not be")
            + uiText("vidyalms.ui.6b48e4e862ab6d3a", " used as a paywall."),
        unavailable: (key: string) => uiText("vidyalms.ui.d09b1a48abf9ebba", "The package that provides \"{p0}\" is not installed. The restriction still applies and is refused rather than ignored.", { p0: key }),
        unavailableAnchor: (key: string) =>
            uiText("vidyalms.ui.5b03341c01036f24", "The schedule anchor \"{p0}\" is not registered, so this restriction stays closed rather than opening for everyone.", { p0: key }),
        rawPayload: uiText("vidyalms.ui.11a9ad37c11275ab", "Stored values"),
        rawHint: uiText("vidyalms.ui.0ee22c4f754949c0", "This condition ships no editor in the installed packages, so its own values are shown as declared."),
        warningsTitle: uiText("vidyalms.ui.23e014214ddb861f", "Saved with warnings"),
        conditionGroups: {
            schedule: uiText("vidyalms.ui.d286b6a52b25a88e", "Dates and schedule"),
            progress: uiText("vidyalms.ui.b5083bef25111bb3", "Progress and grades"),
            audience: uiText("vidyalms.ui.ea85d8f8dcd7fc11", "Who the learner is"),
            gate: uiText("vidyalms.ui.f606e1670dbd4929", "Gates"),
            credential: uiText("vidyalms.ui.d48a617c7da5163c", "Credentials"),
        } as Record<string, string>,
        otherGroup: uiText("vidyalms.ui.f97e9da0e3b879f0", "Other"),
        presetFallback: uiText("vidyalms.ui.ef22bdaa96a4b284", "Choose a restriction…"),
        savedAt: (when: string) => uiText("vidyalms.ui.c53628f4e365c686", "Last changed {p0}", { p0: when }),
        noneStored: uiText("vidyalms.ui.b375f35f4449e460", "Nothing stored yet"),
        /* The five free conditions, whose editors this bundle ships. */
        date: {
            from: uiText("vidyalms.ui.6956ec13a778489f", "Available from"),
            until: uiText("vidyalms.ui.64c3257d27a457c2", "Available until"),
            tz: uiText("vidyalms.ui.2aa2d2ea6eab655f", "Time zone these are read in"),
            tzHint:
                uiText("vidyalms.ui.71948a786ba1078a", "A wall-clock date is resolved against this zone, never the server zone. Learners see their own local")
                + uiText("vidyalms.ui.d066b02372f64cfc", " time with this reading beside it."),
            bothNull: uiText("vidyalms.ui.8d0e5553e1bba371", "Give at least one of the two dates."),
            order: uiText("vidyalms.ui.183d0187f6422276", "The closing date must be after the opening date."),
            untilNote: uiText("vidyalms.ui.f9a3e0bd5d866b65", "After the closing date the item stops counting towards completion, so a closed window cannot make the course impossible to finish."),
        },
        completion: {
            target: uiText("vidyalms.ui.f5ade60eeec9456e", "Item that must be complete"),
            state: uiText("vidyalms.ui.09e0ab409504493a", "Required state"),
            states: {
                complete: uiText("vidyalms.ui.143b270a32602d41", "Complete"),
                incomplete: uiText("vidyalms.ui.6d79fe08a913b757", "Not complete"),
                complete_pass: uiText("vidyalms.ui.6825c0f437338cf0", "Complete and passed"),
                complete_fail: uiText("vidyalms.ui.01bfc9051eee5b63", "Complete and not passed"),
            } as Record<string, string>,
            previous: uiText("vidyalms.ui.09218186084353b1", "The previous item with completion"),
            previousHint:
                uiText("vidyalms.ui.c3b484851e292063", "Survives reordering, duplication and a course copy, where a fixed item does not."),
            passOnly: uiText("vidyalms.ui.fd5b69efd3584076", "Passed and not passed are offered only for an item that carries a pass mark."),
            noTargets: uiText("vidyalms.ui.a026405da6d0e3e6", "This course has nothing to depend on yet."),
        },
        score: {
            target: uiText("vidyalms.ui.4332d0bf850638ca", "Item the score is read from"),
            min: uiText("vidyalms.ui.57421f00d62e796e", "Minimum percentage"),
            max: uiText("vidyalms.ui.a376c4055d9bf3d1", "Maximum percentage"),
            exclusiveMax:
                uiText("vidyalms.ui.db9ae4f298b0dc84", "The minimum is inclusive and the maximum is exclusive. A maximum of 7 means a learner scoring exactly")
                + uiText("vidyalms.ui.189aca8e78878d0c", " 7 does not see the item."),
            order: uiText("vidyalms.ui.b1ca4442f8072543", "The minimum must be below the maximum."),
            bothNull: uiText("vidyalms.ui.19c1083c9262f4c2", "Give at least one of the two percentages."),
        },
        role: {
            capability: uiText("vidyalms.ui.d75b589068174659", "Capability the learner must hold"),
            hint: uiText("vidyalms.ui.a88c9a7d7cdccf80", "Usually authored as a negative and hidden, to keep staff notes out of a learner outline."),
        },
        enrolmentSource: {
            sources: uiText("vidyalms.ui.b947956bd1878c51", "Enrolment sources this is open to"),
            hint: uiText("vidyalms.ui.f985b2881b0f3599", "One or more of the sources an entitlement can have. A learner whose only matching entitlement has expired is blocked rather than excluded for good."),
            empty: uiText("vidyalms.ui.09ea6afbf7901224", "Choose at least one source."),
        },
        negate: uiText("vidyalms.ui.c8a327eb231fde6f", "Reverse this condition"),
        negateHint: uiText("vidyalms.ui.e36f691e8c500de0", "Negation lives in the set operator, so no condition needs an opposite twin."),
    },

    sequencing: {
        title: uiText("vidyalms.ui.d4eb1263eda67cb2", "Order of study"),
        intro:
            uiText("vidyalms.ui.2753b29eebcacba2", "Separate from release. This says whether learners must work through the course in order; the restrictions")
            + uiText("vidyalms.ui.4afb163bf1337c80", " below say when each item opens."),
        label: uiText("vidyalms.ui.ca4ef997a6d5c93c", "Learners work through this course"),
        hint:
            uiText("vidyalms.ui.99a10f9f81eb46b3", "In order is composed in when access is worked out and is written into no stored rule, so switching back")
            + uiText("vidyalms.ui.0fce7d7f04e359e8", " loses nothing an author wrote."),
        modes: {
            free: uiText("vidyalms.ui.6273f043a6b1b609", "In any order"),
            linear: uiText("vidyalms.ui.18c564266c5ecc43", "In order, one item at a time"),
        } as Record<string, string>,
    },

    preview: {
        title: uiText("vidyalms.ui.fd91f4d5bb720361", "Preview as a learner"),
        description:
            uiText("vidyalms.ui.115687126d9e480a", "Evaluates this restriction as one named learner, leaf by leaf. Nothing is saved and nobody is notified."),
        learner: uiText("vidyalms.ui.0815286c81c0a2b1", "Learner"),
        asOf: uiText("vidyalms.ui.23bb3bdac00b6a76", "Evaluate as if the time were"),
        asOfHint: uiText("vidyalms.ui.72bad28ee6598b21", "Leave empty for now. Use it to check what a learner will see next Monday."),
        run: uiText("vidyalms.ui.10f7ab9af7acce02", "Run preview"),
        running: uiText("vidyalms.ui.f46f4682a742e004", "Evaluating…"),
        outcome: uiText("vidyalms.ui.6e7d50e84f4731ef", "Result"),
        states: {
            available: uiText("vidyalms.ui.e674447337e83c13", "Available"),
            blocked: uiText("vidyalms.ui.18f2a0947f9d6523", "Blocked"),
            never: uiText("vidyalms.ui.bcd358d28e2148de", "Never available to this learner"),
        } as Record<string, string>,
        exhausted: uiText("vidyalms.ui.53b966bffed3f1ac", "Closed: neither time nor the learner can reopen this."),
        counts: uiText("vidyalms.ui.078e5bed882cd205", "Counts towards completion"),
        doesNotCount: uiText("vidyalms.ui.ae25305ed06817bb", "Does not count towards completion"),
        countsHint:
            uiText("vidyalms.ui.608ea1671094bbf2", "An item counts when it is available, or blocked and still able to open. Items nobody in this position can")
            + uiText("vidyalms.ui.8210dfa2f0161113", " ever reach, and items whose window has closed, leave the denominator so the course stays finishable."),
        opensAt: (when: string) => uiText("vidyalms.ui.9a04ed881968b783", "Opens {p0}", { p0: when }),
        noOpensAt: uiText("vidyalms.ui.0d0cb7e96018c93a", "No opening instant: at least one condition cannot name one."),
        leafPassed: uiText("vidyalms.ui.36a60559bb018859", "Met"),
        leafFailed: uiText("vidyalms.ui.d210e81cbde067da", "Not met"),
        leafMissing: uiText("vidyalms.ui.f6922b02b4ddd111", "Provider missing"),
        leafMissingHint:
            uiText("vidyalms.ui.099b98f9878ed51d", "The condition is missing, or its stored values were written by a newer version than this site can read.")
            + uiText("vidyalms.ui.f5b0854297d43fa7", " Both are the same miss, and both block rather than open."),
        pickLearner: uiText("vidyalms.ui.c79b5806692a174f", "Choose a learner to preview as."),
        noLeaves: uiText("vidyalms.ui.03665481f46d5a23", "This restriction has no conditions yet."),
    },

    grants: {
        title: uiText("vidyalms.ui.182f92d0cb2c9737", "Individual exceptions"),
        intro:
            uiText("vidyalms.ui.8dd57d1c28b57818", "Access is worked out from current facts every time it is read, so a regrade downward takes access back.")
            + uiText("vidyalms.ui.1f0d2e109593000f", " An exception is the auditable way to override that for one person."),
        add: uiText("vidyalms.ui.7db4a7e7c4ec06c8", "Add an exception"),
        learner: uiText("vidyalms.ui.0815286c81c0a2b1", "Learner"),
        subject: uiText("vidyalms.ui.652bcc3a47842889", "Item"),
        mode: uiText("vidyalms.ui.2252d5cf93767a43", "Effect"),
        modes: {
            grant: uiText("vidyalms.ui.cdca3aa6924dd07c", "Open this item for them"),
            deny: uiText("vidyalms.ui.b2dfedaa7520bff3", "Close this item for them"),
        } as Record<string, string>,
        reason: uiText("vidyalms.ui.69e7c1537a3a2395", "Reason (kept on the record)"),
        expires: uiText("vidyalms.ui.f6725f3af08a06a2", "Expires"),
        expiresHint: uiText("vidyalms.ui.c513b8d9e532103a", "Leave empty for no expiry. Expiry is read at the moment of use, so it stops applying exactly when it says."),
        scopeOrder: (order: string) => uiText("vidyalms.ui.001f979f307356d1", "Order of precedence: {p0}. Within one level, closing beats opening.", { p0: order }),
        denyForever:
            uiText("vidyalms.ui.a1991b9633b7f953", "A closure with no expiry removes this item from the completion total of that learner, which is what excusing")
            + uiText("vidyalms.ui.847021e4c09e8e3e", " somebody from an item means. A closure that should not excuse them is a closure with a date on it."),
        active: uiText("vidyalms.ui.1652cbbbc6d266a3", "In force"),
        expired: uiText("vidyalms.ui.424a2551d356754c", "Expired"),
        revoke: uiText("vidyalms.ui.87e6d00bbf53ec5a", "Revoke"),
        revoked: uiText("vidyalms.ui.2181863a47962938", "Exception revoked"),
        excludes: uiText("vidyalms.ui.a573d0273443efa9", "Excused from completion"),
        none: uiText("vidyalms.ui.4857e0dbfae0ffae", "No exceptions on this course."),
        grantedBy: (who: string) => uiText("vidyalms.ui.2fd7c48788cc2468", "by {p0}", { p0: who }),
        save: uiText("vidyalms.ui.d47cf10ff3464fe5", "Save exception"),
    },

    cohorts: {
        tab: uiText("vidyalms.ui.848f54e89660cea4", "Runs"),
        title: uiText("vidyalms.ui.eee21e3e45f18f3a", "Runs of this course"),
        intro:
            uiText("vidyalms.ui.f932853a66742f0f", "A run is a schedule and a roster over this one course. No lesson, quiz or submission is copied to make")
            + uiText("vidyalms.ui.3814840ca9e81f9d", " one, so a learner moved between runs keeps every bit of their work."),
        unavailable: uiText("vidyalms.ui.38ff171bc707a79f", "Scheduled runs need a package this site does not have."),
        unavailableHint:
            uiText("vidyalms.ui.f305026b8db3185f", "The course still works: without runs it is self paced, and the dates of every learner are measured from their")
            + uiText("vidyalms.ui.f082ffda8536b6f1", " own enrolment."),
        add: uiText("vidyalms.ui.dcb34ce2197c6af8", "New run"),
        create: uiText("vidyalms.ui.5269a24e91191e6c", "Create run"),
        none: uiText("vidyalms.ui.72a85758b69c1d33", "No runs yet."),
        columns: {
            title: uiText("vidyalms.ui.00d60e31a4e6b834", "Run"),
            state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
            pacing: uiText("vidyalms.ui.afb633dc84a06e06", "Pacing"),
            starts: uiText("vidyalms.ui.96dbedeca7dfb7fa", "Starts"),
            members: uiText("vidyalms.ui.1044a4c056d0d685", "Members"),
        },
        states: {
            draft: uiText("vidyalms.ui.ebf12ef47cf575b3", "Draft"),
            open: uiText("vidyalms.ui.c64e01424f67ee19", "Open for enrolment"),
            running: uiText("vidyalms.ui.f4ccae29e1bb0c20", "Running"),
            closed: uiText("vidyalms.ui.c21ead0614e7e1b7", "Closed"),
            archived: uiText("vidyalms.ui.bdb86505f8062d15", "Archived"),
        } as Record<string, string>,
        pacings: {
            fixed: uiText("vidyalms.ui.74207aaeddefdb74", "Fixed dates"),
            relative: uiText("vidyalms.ui.b28bb62f899814df", "Relative to each member"),
            self: uiText("vidyalms.ui.dc3d5f8bf535eef2", "Self paced"),
        } as Record<string, string>,
        pacingHint:
            uiText("vidyalms.ui.0efdeb434b2f50d9", "Fixed measures from the start of the run, relative from the anchor of each member, and self paced has no dates")
            + uiText("vidyalms.ui.80baf45a64521c6e", " at all. Changing it is allowed and rewrites nothing: the previously set dates are kept."),
        fields: {
            title: uiText("vidyalms.ui.7e8cd2056da73a7f", "Title"),
            code: uiText("vidyalms.ui.340f463033e0fd5d", "Code"),
            codeHint: uiText("vidyalms.ui.a405f86f3e3fe77d", "Unique within this course. An import maps onto it."),
            timezone: uiText("vidyalms.ui.b9fe1464783e1c0d", "Time zone"),
            timezoneHint: uiText("vidyalms.ui.99936c09ed819121", "Every wall-clock date of this run is resolved against this zone."),
            starts: uiText("vidyalms.ui.96dbedeca7dfb7fa", "Starts"),
            ends: uiText("vidyalms.ui.e98982c9f2ba3332", "Ends"),
            enrolOpens: uiText("vidyalms.ui.7038e930e88d3745", "Enrolment opens"),
            enrolCloses: uiText("vidyalms.ui.03f3aae478f55238", "Enrolment closes"),
            capacity: uiText("vidyalms.ui.ae65d096550ff4eb", "Capacity"),
            capacityHint: uiText("vidyalms.ui.5b1719812e250000", "Empty means no limit. Over capacity a candidate is waitlisted unless the caller may admit anyway."),
            waitlist: uiText("vidyalms.ui.0d55fab707854b84", "Keep a waitlist"),
            latePolicy: uiText("vidyalms.ui.bece93fbd614a4ed", "Late work"),
            latePolicyHint: uiText("vidyalms.ui.1b27e29e0b5a07a7", "Free sites mark late work and change no score. A package can add deductions and cut-offs."),
        },
        archive: uiText("vidyalms.ui.a4b739e1a5621d05", "Archive run"),
        archiveConfirm:
            uiText("vidyalms.ui.e47bc7f4e9c0eb26", "Archiving keeps every membership row, because \"who was in the March run\" has to stay answerable. Pending")
            + uiText("vidyalms.ui.f18929f50119fca4", " release tasks for this run are cancelled."),
        archived: (kept: number, cancelled: number) =>
            uiText("vidyalms.ui.3660919a362f1452", "Run archived. {p0} membership rows kept, {p1} pending tasks cancelled.", { p0: kept, p1: cancelled }),
        tabs: {
            schedule: uiText("vidyalms.ui.f4830a1dae298044", "Schedule"),
            members: uiText("vidyalms.ui.1044a4c056d0d685", "Members"),
            overrides: uiText("vidyalms.ui.f68cbace5cc20c19", "Date exceptions"),
        },
        schedule: {
            title: uiText("vidyalms.ui.f4830a1dae298044", "Schedule"),
            intro:
                uiText("vidyalms.ui.b33eb744a6358acd", "Authored as offsets from an anchor and resolved when they are read. Materialised dates are a cache and")
                + uiText("vidyalms.ui.0d7da34c6a8a3b74", " never the truth, or editing the schedule leaves a shadow schedule behind."),
            addEntry: uiText("vidyalms.ui.d9442036107e048a", "Add an item"),
            target: uiText("vidyalms.ui.652bcc3a47842889", "Item"),
            anchor: uiText("vidyalms.ui.ecdf538a686b5aae", "Measured from"),
            offset: uiText("vidyalms.ui.767e5b5cf1be0606", "Opens after"),
            due: uiText("vidyalms.ui.dc477f6da23ca5d0", "Due after"),
            cutoff: uiText("vidyalms.ui.3e4bc04feab330c0", "Closes after"),
            unit: uiText("vidyalms.ui.023a638b818a2a46", "Counted in"),
            units: { hours: uiText("vidyalms.ui.21e8492938abc179", "Hours"), days: uiText("vidyalms.ui.e08c0aa8f558f39f", "Days") } as Record<string, string>,
            unitHint:
                uiText("vidyalms.ui.52804a0a5e3ffcec", "Days step the local calendar of the anchor rather than adding blocks of 24 hours, so a daylight-saving")
                + uiText("vidyalms.ui.560156578665ccef", " change does not move day seven by an hour."),
            resolved: uiText("vidyalms.ui.37761daf638d7e21", "Resolved dates"),
            resolvedFrom: {
                override: uiText("vidyalms.ui.d613b19e6bb74450", "From a date exception"),
                schedule: uiText("vidyalms.ui.9046a67d664550e9", "From the schedule of this run"),
                item: uiText("vidyalms.ui.ecb42eab0f944d99", "From the dates on the item itself"),
            } as Record<string, string>,
            dates: { available: uiText("vidyalms.ui.21a71f3e3356eeab", "Opens"), due: uiText("vidyalms.ui.9071738f145962a6", "Due"), cutoff: uiText("vidyalms.ui.74c21a6312ede178", "Closes"), hide: uiText("vidyalms.ui.8fd09755627057f3", "Hidden after") },
            noEntries: uiText("vidyalms.ui.47def519003ed54a", "Nothing scheduled. Every item opens as soon as the run does."),
            save: uiText("vidyalms.ui.387f355f83e2679c", "Save schedule"),
            dueNote: uiText("vidyalms.ui.5b3f553dea0f15cf", "A due date marks work late and does not block it. A closing date blocks the action. They are separate on purpose."),
        },
        members: {
            title: uiText("vidyalms.ui.1044a4c056d0d685", "Members"),
            add: uiText("vidyalms.ui.d600b9239ea24be8", "Add members"),
            addHint: uiText("vidyalms.ui.fcdb1931269fe554", "A learner already active in another run of this course is refused rather than moved, because a move closes one row and opens another and you should say which you meant."),
            columns: {
                learner: uiText("vidyalms.ui.0815286c81c0a2b1", "Learner"),
                state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
                role: uiText("vidyalms.ui.14736a2eb9f4159f", "Role"),
                anchor: uiText("vidyalms.ui.a4ae750b610e875e", "Anchor"),
                progress: uiText("vidyalms.ui.4664827f8e890192", "Progress"),
                joined: uiText("vidyalms.ui.69318b0c6a928e7e", "Joined"),
            },
            states: {
                invited: uiText("vidyalms.ui.63b17becd812dfd3", "Invited"),
                waitlisted: uiText("vidyalms.ui.b4ea85608f4fb17d", "Waitlisted"),
                active: uiText("vidyalms.ui.92340695899bd2d8", "Active"),
                completed: uiText("vidyalms.ui.22a970d2e5b1cc23", "Completed"),
                withdrawn: uiText("vidyalms.ui.00c0b03fcfa2ac02", "Withdrawn"),
                moved: uiText("vidyalms.ui.b11c9047f3512271", "Moved"),
            } as Record<string, string>,
            roles: { learner: uiText("vidyalms.ui.0815286c81c0a2b1", "Learner"), leader: uiText("vidyalms.ui.3f53871b0838bdf3", "Leader"), observer: uiText("vidyalms.ui.f20b6db009508832", "Observer") } as Record<string, string>,
            behind: uiText("vidyalms.ui.2db399b491f53df1", "Behind schedule"),
            anchorHint:
                uiText("vidyalms.ui.71a31beffe5197c8", "Each member carries their own anchor, so a mid-run joiner has their own timeline instead of inheriting")
                + uiText("vidyalms.ui.85115b5c1a27bb88", " one measured from when the run was created."),
            waitlistPosition: (n: number) => uiText("vidyalms.ui.0b39156e73c582d2", "Waitlist position {p0}", { p0: n }),
            none: uiText("vidyalms.ui.f4e903152d8530f2", "Nobody in this run yet."),
            move: uiText("vidyalms.ui.82b7d66b55280aa3", "Move to another run"),
            moveTitle: uiText("vidyalms.ui.82b7d66b55280aa3", "Move to another run"),
            moveTo: uiText("vidyalms.ui.2c775db47da60f93", "Destination run"),
            moveAnchor: uiText("vidyalms.ui.92935b116df99a5e", "Anchor in the destination"),
            moveAnchorHint:
                uiText("vidyalms.ui.9fa18e1099793bf1", "Give the start of the destination run to place them where everybody else is. Leave it empty to anchor")
                + uiText("vidyalms.ui.c7174daa847b81e5", " them at this moment."),
            moveReason: uiText("vidyalms.ui.69e7c1537a3a2395", "Reason (kept on the record)"),
            movePreserves:
                uiText("vidyalms.ui.a6cf4fb413016c32", "No progress row, attempt, submission, grade or certificate is written or deleted by a move. That is what")
                + uiText("vidyalms.ui.a25261bcfff1fe22", " makes it a move and not an unenrol."),
            moveConfirm: uiText("vidyalms.ui.29c81d26e786c8b0", "Move learner"),
            moved: (tasksCancelled: number, tasksScheduled: number) =>
                uiText("vidyalms.ui.f560d4361079264e", "Moved. Progress kept in full. {p0} pending release tasks cancelled, {p1} scheduled.", { p0: tasksCancelled, p1: tasksScheduled }),
            noDestinations: uiText("vidyalms.ui.644803164d71d6b6", "This course has no other run to move into."),
            outcomes: {
                added: uiText("vidyalms.ui.6b02e0d363a4af1c", "Added"),
                waitlisted: uiText("vidyalms.ui.b4ea85608f4fb17d", "Waitlisted"),
                already_member: uiText("vidyalms.ui.1739ed7c922674c4", "Already a member"),
                active_elsewhere: uiText("vidyalms.ui.53551d5f96024b03", "Active in another run"),
                refused: uiText("vidyalms.ui.66b873543aebf01b", "Refused"),
            } as Record<string, string>,
            addResult: (added: number, waitlisted: number, refused: number) =>
                uiText("vidyalms.ui.1333404acae33d59", "{p0} added, {p1} waitlisted, {p2} refused.", { p0: added, p1: waitlisted, p2: refused }),
        },
        overrides: {
            title: uiText("vidyalms.ui.f68cbace5cc20c19", "Date exceptions"),
            intro: uiText("vidyalms.ui.1ba87aaeb96e935c", "One table, one merge rule, printed here so support reads the same order the code applies."),
            mergeOrder: (order: string) =>
                uiText("vidyalms.ui.d5b60f861cd75119", "{p0}. Within one level the most generous value wins: the earliest opening, the latest closing, the", { p0: order })
                + uiText("vidyalms.ui.9f8a04c9bace3d2e", " longest time limit and the most attempts."),
            add: uiText("vidyalms.ui.7db4a7e7c4ec06c8", "Add an exception"),
            scope: uiText("vidyalms.ui.6687458beee57a58", "Applies to"),
            scopes: { cohort: uiText("vidyalms.ui.7dd7486200050803", "The whole run"), group: uiText("vidyalms.ui.0313e1313ebc3105", "A group"), user: uiText("vidyalms.ui.9fdc4e8097faed48", "One learner") } as Record<string, string>,
            target: uiText("vidyalms.ui.652bcc3a47842889", "Item"),
            none: uiText("vidyalms.ui.883858081044e1ae", "No date exceptions."),
            remove: uiText("vidyalms.ui.c3812fc4acb861d5", "Remove"),
            save: uiText("vidyalms.ui.d47cf10ff3464fe5", "Save exception"),
        },
    },

    notifications: {
        tab: uiText("vidyalms.ui.788011833a5a0f22", "Notifications"),
        title: uiText("vidyalms.ui.afd8c121d8c9608b", "Notification rules"),
        intro:
            uiText("vidyalms.ui.d4632fc76fa4f0ac", "A rule is a trigger, an audience, a schedule, one or more channels and a template. Every one of those is a")
            + uiText("vidyalms.ui.936b626cefc98469", " registry, so adding a message is a row and, at most, an installed package."),
        disabledNote:
            uiText("vidyalms.ui.f4015c985a2a91b2", "New rules are created switched off. Light-touch nudges measure small, are context dependent and sometimes")
            + uiText("vidyalms.ui.f5e83fe271479d4f", " negative, so a site turns one on deliberately."),
        add: uiText("vidyalms.ui.99cd24efe9831454", "New rule"),
        none: uiText("vidyalms.ui.f90a935654a735e0", "No rules yet."),
        columns: {
            rule: uiText("vidyalms.ui.62845f31a2fe00be", "Rule"),
            trigger: uiText("vidyalms.ui.8b9c643731c94119", "Trigger"),
            audience: uiText("vidyalms.ui.545c02357695a6ff", "Audience"),
            channels: uiText("vidyalms.ui.4c8906cf76f5740a", "Channels"),
            state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
            counters: uiText("vidyalms.ui.11b36c2603cc9ab1", "Queued / sent / suppressed"),
        },
        states: { enabled: uiText("vidyalms.ui.130011756125313c", "On"), disabled: uiText("vidyalms.ui.ca7981b46ecf2c17", "Off") } as Record<string, string>,
        enable: uiText("vidyalms.ui.5a1f096a0d8d7674", "Turn on"),
        disable: uiText("vidyalms.ui.06f0e210b27d4d62", "Turn off"),
        inert: uiText("vidyalms.ui.2998df582c30c312", "Inert"),
        inertHint: (missing: string) =>
            uiText("vidyalms.ui.90cab8d507e77576", "This rule names {p0}, which nothing registers. It is reported as inert rather than fired with a", { p0: missing })
            + uiText("vidyalms.ui.d5f98aff78e1f5a0", " guessed template."),
        fields: {
            ruleKey: uiText("vidyalms.ui.8e729a481bd3e5d0", "Rule key"),
            ruleKeyHint: uiText("vidyalms.ui.a447cb886f59edc1", "Stable and unique. Other rules name it when they suppress it."),
            trigger: uiText("vidyalms.ui.8b9c643731c94119", "Trigger"),
            audience: uiText("vidyalms.ui.545c02357695a6ff", "Audience"),
            category: uiText("vidyalms.ui.292c06f0045a45d0", "Category"),
            categoryHint:
                uiText("vidyalms.ui.c86f0574d92061b1", "The preference unit. Learners choose per category and not per message, so a new message inherits a")
                + uiText("vidyalms.ui.1c93a9c39f37f3ba", " category and needs no preference migration."),
            messageClass: uiText("vidyalms.ui.e9824af2482c2d80", "Message class"),
            messageClassHint:
                uiText("vidyalms.ui.710baefc1f84685a", "Read from the trigger and never set on a rule. What a reasonable recipient would conclude from the")
                + uiText("vidyalms.ui.ddaaced9a83cad95", " subject line is a property of the message, not of the moment, and it decides whether consent runs")
                + uiText("vidyalms.ui.d878e453d8dfcfaf", " and whether the unsubscribe headers are added."),
            template: uiText("vidyalms.ui.0575f29df888a27e", "Template"),
            channels: uiText("vidyalms.ui.4c8906cf76f5740a", "Channels"),
            schedule: uiText("vidyalms.ui.1bd739e67b5ed4e0", "When it runs"),
            scheduleKinds: {
                immediate: uiText("vidyalms.ui.ced08d0f1934ab96", "As soon as the trigger fires"),
                absolute: uiText("vidyalms.ui.2d5e88b21be316f7", "At a fixed instant"),
                relative: uiText("vidyalms.ui.8ff66a663de37012", "An offset from an anchor"),
                recurring_window: uiText("vidyalms.ui.671b61eb0d78606c", "Every N from an anchor, bounded"),
            } as Record<string, string>,
            anchor: uiText("vidyalms.ui.a4ae750b610e875e", "Anchor"),
            offset: uiText("vidyalms.ui.b1a1e8e3cf7c522e", "Offset"),
            offsetUnit: uiText("vidyalms.ui.023a638b818a2a46", "Counted in"),
            at: uiText("vidyalms.ui.e2861894f065ab75", "Instant"),
            every: uiText("vidyalms.ui.9b8617fdfbba933d", "Every"),
            until: uiText("vidyalms.ui.7caf856e8015823f", "Until"),
            conditions: uiText("vidyalms.ui.3ddcbfe612b2f8d3", "Only when"),
            conditionsHint:
                uiText("vidyalms.ui.2ee60307291077d3", "The same restriction grammar, on the notification surface, and re-checked at send. A seven-day nudge")
                + uiText("vidyalms.ui.b1b702c30f9f4c33", " conditioned on \"has not completed\" must not reach somebody who finished on day two."),
            suppresses: uiText("vidyalms.ui.d884a8c992541f0a", "Silences these rules"),
            suppressesHint:
                uiText("vidyalms.ui.38ad40d253103666", "For the same subject, inside the window. Without cross-rule suppression a site that switches on five")
                + uiText("vidyalms.ui.eee4b29752a5beff", " sensible rules mails a learner five times on completion day."),
            course: uiText("vidyalms.ui.2096cefba06a32ba", "Course"),
            wholeSite: uiText("vidyalms.ui.c02d41ef4943dfee", "Every course"),
        },
        classes: {
            transactional: uiText("vidyalms.ui.2a19281e7fc59b19", "Transactional"),
            operational: uiText("vidyalms.ui.996bf57d15be4ec3", "Operational"),
            optional: uiText("vidyalms.ui.59be71333c960fd0", "Optional"),
            marketing: uiText("vidyalms.ui.f5904cf7a1231a7a", "Marketing"),
        } as Record<string, string>,
        classHint: {
            transactional: uiText("vidyalms.ui.600899996629cb83", "Bypasses preference and consent, and a site may be obliged to send it."),
            operational: uiText("vidyalms.ui.b673adabd17ebe79", "Sent by default; a learner can still turn it off."),
            optional: uiText("vidyalms.ui.87b7c88a48281087", "Off unless a learner asks for it."),
            marketing: uiText("vidyalms.ui.0f25e93d1523f8fa", "Consent only, and an objection can never be overridden."),
        } as Record<string, string>,
        placeholders: uiText("vidyalms.ui.4c1f3821d2497e1c", "Placeholders this trigger offers"),
        placeholdersHint:
            uiText("vidyalms.ui.8898dd8eeb4cecf2", "Published per trigger, so the list is what actually resolves. An unknown placeholder is refused when the")
            + uiText("vidyalms.ui.253ab13ccf8c61de", " template is saved rather than left to fail at send."),
        insertPlaceholder: uiText("vidyalms.ui.8aa318eb7eaa233a", "Insert"),
        unknownPlaceholder: (name: string) => uiText("vidyalms.ui.7849303e0c43e53b", "\"{p0}\" is not offered by this trigger.", { p0: name }),
        templateParts: {
            subject: uiText("vidyalms.ui.68971283841aecdf", "Subject"),
            body_text: uiText("vidyalms.ui.b346d69c361cd19d", "Plain text body"),
            body_html: uiText("vidyalms.ui.0554e3efee15973b", "HTML body"),
            body_short: uiText("vidyalms.ui.5d13ceb58765b538", "Short body"),
        } as Record<string, string>,
        templateHint:
            uiText("vidyalms.ui.45c2333f504762fd", "Every email ships the plain part, and it is ordered before the HTML part. A channel takes the parts it can")
            + uiText("vidyalms.ui.8fb51df05a065b73", " carry and ignores the rest."),
        save: uiText("vidyalms.ui.4e62c225adb2e2aa", "Save rule"),
        saveTemplate: uiText("vidyalms.ui.47f72a2f3e91d32d", "Save template"),
        delete: uiText("vidyalms.ui.075d11395e22e588", "Delete rule"),
        counters: {
            queued: uiText("vidyalms.ui.661ff40a07e037bb", "Queued"),
            sent: uiText("vidyalms.ui.c16bc82bf1f04ede", "Sent"),
            suppressed: uiText("vidyalms.ui.435c78310f60812e", "Suppressed"),
            byReason: uiText("vidyalms.ui.681fb0ad185ef60f", "Suppressed by reason"),
        },
        queue: {
            title: uiText("vidyalms.ui.3b2fe03e36893916", "Queue"),
            intro:
                uiText("vidyalms.ui.2a161019890ed089", "One row per recipient per channel. A row is written inside the same transaction as the change that")
                + uiText("vidyalms.ui.e5cf639b0aab79d3", " caused it, so a committed enrolment cannot lose its mail."),
            none: uiText("vidyalms.ui.cbf36da459459ce2", "Nothing queued."),
            columns: {
                message: uiText("vidyalms.ui.2f77668a9dfbf8d5", "Message"),
                recipient: uiText("vidyalms.ui.51fac985e95345d9", "Recipient"),
                channel: uiText("vidyalms.ui.ce4683e7013a18cd", "Channel"),
                state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
                runAt: uiText("vidyalms.ui.1b2499936a6525b9", "Runs at"),
                attempts: uiText("vidyalms.ui.06e70139fcf5e5c7", "Attempts"),
            },
            states: {
                pending_fanout: uiText("vidyalms.ui.7864ff21c78684ba", "Waiting to fan out"),
                pending: uiText("vidyalms.ui.331551b0de4157c9", "Pending"),
                sending: uiText("vidyalms.ui.e595f17fac1d8384", "Sending"),
                sent: uiText("vidyalms.ui.c16bc82bf1f04ede", "Sent"),
                deferred: uiText("vidyalms.ui.1fc4d2e60ca80e29", "Deferred"),
                suppressed: uiText("vidyalms.ui.435c78310f60812e", "Suppressed"),
                failed_permanent: uiText("vidyalms.ui.031a8f0f659df890", "Failed"),
                cancelled: uiText("vidyalms.ui.d353a99eb4556847", "Cancelled"),
            } as Record<string, string>,
            cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
            cancelHint: uiText("vidyalms.ui.77aa7633dab3257c", "A row already claimed for sending cannot be cancelled, and says so rather than pretending."),
            deferredHint: uiText("vidyalms.ui.562da698b31ff6c9", "Deferred is not dropped. Quiet hours moves a send forward to the next permitted hour and never backwards."),
            occurrence: (key: string) => uiText("vidyalms.ui.456badb6a968e006", "Occurrence {p0}", { p0: key }),
            suppressedReason: (reason: string) => uiText("vidyalms.ui.2d98acce23dda647", "Reason: {p0}", { p0: reason }),
            filterAll: uiText("vidyalms.ui.f189411452ed2860", "All states"),
        },
    },

    providers: {
        title: uiText("vidyalms.ui.d3568092d4d40fa3", "Provider settings"),
        intro:
            uiText("vidyalms.ui.4eb4c0b09bb3d374", "Every extension point is a registry, and every provider in one is configured the same way and at the same")
            + uiText("vidyalms.ui.af0cb0e14db20c69", " four scopes. A provider that takes no configuration says so."),
        registry: uiText("vidyalms.ui.83599485b43a20e7", "Extension point"),
        scope: uiText("vidyalms.ui.42bbf1ba2f53bc9a", "Configured at"),
        scopes: { site: uiText("vidyalms.ui.fa7955814e32aed3", "Site"), course: uiText("vidyalms.ui.14120a05acb25af3", "One course"), cohort: uiText("vidyalms.ui.a4528f80684e12d1", "One run"), rule: uiText("vidyalms.ui.85ebf321a938cdcf", "One rule") } as Record<string, string>,
        scopeHint: uiText("vidyalms.ui.8358d2070647f212", "A narrower setting wins: rule, then run, then course, then site."),
        none: uiText("vidyalms.ui.f0dc83b942694e1d", "Nothing registered here."),
        noSettings: uiText("vidyalms.ui.691655cafd32b6d0", "This provider takes no configuration."),
        unavailable: uiText("vidyalms.ui.d177cdc09a4ea3f3", "Not installed"),
        editions: { free: uiText("vidyalms.ui.f411a1fb62758b4c", "Free"), pro: uiText("vidyalms.ui.957b0b874524d09a", "Pro"), business: uiText("vidyalms.ui.76f68a75f01ed76f", "Business") } as Record<string, string>,
        package: (name: string) => uiText("vidyalms.ui.e18e72daa379b5cb", "from {p0}", { p0: name }),
        save: uiText("vidyalms.ui.7f3a3b142814581a", "Save settings"),
        saved: uiText("vidyalms.ui.74a7f53bad399973", "Settings saved"),
    },

    export: {
        button: uiText("vidyalms.ui.3664895579f0a7e6", "Export"),
        title: uiText("vidyalms.ui.4ba19e9de64948c6", "Export this quiz"),
        /* The design's own wording: the warning goes before the download and
           not after it, because after it is too late. */
        correctResponses:
            uiText("vidyalms.ui.6880e2b8feaefa54", "The export file contains the correct responses. Anyone who can open the file can see the answer key, so")
            + uiText("vidyalms.ui.df3b3e544d2625ce", " store it where learners cannot reach it."),
        format: uiText("vidyalms.ui.2f343666aaa88c44", "Format"),
        download: uiText("vidyalms.ui.d6eafe8235910042", "Download"),
        cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
        unavailable: uiText("vidyalms.ui.4cf77448f2923231", "Exporting needs a newer version of the VidyaLMS host extension."),
    },

    common: {
        cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
        close: uiText("vidyalms.ui.7d9eb7acb13e2462", "Close"),
        loadError: uiText("vidyalms.ui.32ccbd5bb8bde196", "Could not load."),
        retry: uiText("vidyalms.ui.d8b8392e2c542950", "Try again"),
        never: uiText("vidyalms.ui.6300ef800bb88429", "Never"),
        none: uiText("vidyalms.ui.dc937b59892604f5", "None"),
        all: uiText("vidyalms.ui.a52ace420f2175d0", "All"),
        yes: uiText("vidyalms.ui.85a39ab345d672ff", "Yes"),
        no: uiText("vidyalms.ui.1ea442a134b2a184", "No"),
        unavailable: uiText("vidyalms.ui.d177cdc09a4ea3f3", "Not installed"),
    },
} as const;

export type FlowStrings = typeof flowStrings;
