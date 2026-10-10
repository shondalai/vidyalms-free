import { translate as uiText } from '@/shared/i18n';
/*
 * The AI screen's copy. Same rules as the rest of the admin: one typed object,
 * no scattered literals, plain and factual, and no em-dashes or en-dashes.
 *
 * Two tones are deliberate. The enablement and stop copy is operational, because
 * a person reading it is deciding whether to switch something on. The oversight
 * copy states the measurement rather than a verdict: a task accepted unedited
 * every time is either excellent or unreviewed, and the screen asks that as a
 * question rather than answering it.
 */
export const aiStrings = {
    screen: uiText("vidyalms.ui.11fb682be0a0233d", "AI"),
    region: uiText("vidyalms.ui.f8d6dfe216695155", "AI administration"),
    tabs: {
        label: uiText("vidyalms.ui.2026b40ca5142e88", "AI sections"),
        enablement: uiText("vidyalms.ui.0f07846b55203fe2", "Enablement"),
        policy: uiText("vidyalms.ui.609a4db4cb73f97c", "Data-use policy"),
        routes: uiText("vidyalms.ui.2c5b669bcdeb51bf", "Routes"),
        runs: uiText("vidyalms.ui.848f54e89660cea4", "Runs"),
        drafts: uiText("vidyalms.ui.f592e6a4db3c6638", "Drafts"),
        usage: uiText("vidyalms.ui.8d59829c1e15afe1", "Usage"),
    },
    notFound: {
        title: uiText("vidyalms.ui.f4c7f41c12d6d2ed", "Nothing here"),
        body: uiText("vidyalms.ui.cff3cc286bc704a1", "That address is not a section of the AI screen."),
        back: uiText("vidyalms.ui.4338bf49a7571994", "Back to enablement"),
    },
    forbidden: {
        title: uiText("vidyalms.ui.b35bcc2739337f12", "The AI screen needs manage."),
        body: uiText("vidyalms.ui.ea38699378b94dbb", "Reading the run log, the spend and the data-use policy needs the vidyalms.manage permission."),
    },
    errors: {
        load: uiText("vidyalms.ui.ebb2767bfb6d1a96", "The AI screen could not load."),
        save: uiText("vidyalms.ui.35fc6d2856bf3460", "That change was not saved."),
    },
    loading: uiText("vidyalms.ui.dc380888c4e2c776", "Loading"),
    enablement: {
        title: uiText("vidyalms.ui.9f588c6d302d6789", "Four levels, and one stop above them"),
        intro:
            uiText("vidyalms.ui.c50c4798c016ac7c", "Everything is off by default. The site enables AI at all, the site enables each helper, ") +
            uiText("vidyalms.ui.ffc0d425bb82ecc9", "each route enables each task, and each person accepts the data-use policy. ") +
            uiText("vidyalms.ui.c713ef91f4480f0d", "Above all four sits one control that halts every task on the site."),
        siteLabel: uiText("vidyalms.ui.205f0e4176c49af3", "AI is enabled on this site"),
        siteHint: uiText("vidyalms.ui.aa12ae02b39ace0f", "Level one. With this off no helper runs, whatever else is configured."),
        placements: uiText("vidyalms.ui.78993adbf627c394", "Helpers"),
        placementsHint:
            uiText("vidyalms.ui.5d1e417c2e8c9f8c", "Level two. This list is generated from what is registered, so a newly installed helper ") +
            uiText("vidyalms.ui.a995b44bcfdc7afa", "arrives with its own switch."),
        placementsEmpty: uiText("vidyalms.ui.9245ab8861d0cad1", "No helper is registered on this site yet."),
        routesSummary: (enabled: number, total: number, tasks: number) =>
            uiText("vidyalms.ui.c568ff85f8acdfef", "Level three. {p0} of {p1} routes enabled, serving {p2} {p3}.", { p0: enabled, p1: total, p2: tasks, p3: tasks === 1 ? 'task' : 'tasks' }),
        routesNone: uiText("vidyalms.ui.4ab4b2ea0c325f55", "Level three. No route is configured, so every task refuses and says so."),
        policyLevel: (version: number, count: number) =>
            uiText("vidyalms.ui.8d0fc3f84b970815", "Level four. Version {p0} is in force and {p1} {p2} accepted it.", { p0: version, p1: count, p2: count === 1 ? 'person has' : 'people have' }),
        policyNone: uiText("vidyalms.ui.0c384b0f53616287", "Level four. No data-use policy is active, so every run refuses."),
        save: uiText("vidyalms.ui.e22078afc454eddd", "Save enablement"),
        saved: uiText("vidyalms.ui.b5c120b316c237a0", "Saved"),
        surface: uiText("vidyalms.ui.0905f7f59021c2a8", "Surface"),
        helper: uiText("vidyalms.ui.ce793a72d4ab405e", "Helper"),
        enabled: uiText("vidyalms.ui.92c1cdfdf4cb9cf6", "Enabled"),
        source: uiText("vidyalms.ui.863704817fea532b", "Supplied by"),
        free: uiText("vidyalms.ui.f411a1fb62758b4c", "Free"),
        pro: uiText("vidyalms.ui.957b0b874524d09a", "Pro"),
        thirdParty: uiText("vidyalms.ui.f3c6dbcc99e47e54", "Third party"),
    },
    stop: {
        title: uiText("vidyalms.ui.db975b6c481f7b97", "Stop all AI"),
        intro:
            uiText("vidyalms.ui.24ba39d70f2fd0b3", "One control halts every task on the site in a safe state, without uninstalling anything. ") +
            uiText("vidyalms.ui.12951244948f09af", "A run already in flight stops within one unit of work and is recorded as cancelled rather than failed."),
        set: uiText("vidyalms.ui.db975b6c481f7b97", "Stop all AI"),
        clear: uiText("vidyalms.ui.7bb84e00c551912e", "Resume AI"),
        reasonLabel: uiText("vidyalms.ui.0f7d4664a9bcd28a", "Reason (optional)"),
        stopped: uiText("vidyalms.ui.10792e1daa454577", "AI is stopped on this site."),
        running: uiText("vidyalms.ui.4cac66b88b8683b4", "AI is not stopped."),
        setBy: (who: number, when: string) => uiText("vidyalms.ui.dd9e5825ae9066c5", "Stopped by user {p0} at {p1}.", { p0: who, p1: when }),
        clearedBy: (who: number, when: string) => uiText("vidyalms.ui.9d0d332995c8c813", "Resumed by user {p0} at {p1}.", { p0: who, p1: when }),
        confirmTitle: uiText("vidyalms.ui.435332eed2f4882f", "Stop every AI task on this site?"),
        confirmBody:
            uiText("vidyalms.ui.b4fbe26b80c853a4", "Runs in flight stop at the next unit of work and are recorded as cancelled. ") +
            uiText("vidyalms.ui.90921764a183e4dc", "Nothing is uninstalled and nothing already accepted is withdrawn."),
        confirm: uiText("vidyalms.ui.db975b6c481f7b97", "Stop all AI"),
        cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
    },
    policy: {
        title: uiText("vidyalms.ui.609a4db4cb73f97c", "Data-use policy"),
        intro:
            uiText("vidyalms.ui.2e1b1439881f0352", "One row per version. The version is on every acceptance and on every run, so a changed ") +
            uiText("vidyalms.ui.1cd741b717037ed1", "policy re-prompts everybody and an audit row can say which text was in force."),
        empty: uiText("vidyalms.ui.ac9bee9ac714f13d", "No policy version has been drafted yet."),
        newVersion: uiText("vidyalms.ui.d4d04f1daf5d4aff", "Draft a version"),
        version: uiText("vidyalms.ui.dd167905de0defca", "Version"),
        state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
        retention: uiText("vidyalms.ui.3b1f58d94eacf04f", "Retention"),
        redaction: uiText("vidyalms.ui.38ae39ff6c88b6f5", "Redaction"),
        acceptances: uiText("vidyalms.ui.ed3e3dd0c31e14a9", "Accepted by"),
        effective: uiText("vidyalms.ui.f567f87a9204adde", "In force from"),
        activate: uiText("vidyalms.ui.24433c70eba5b153", "Activate"),
        activateHint: uiText("vidyalms.ui.4ba3f88f8e0688a9", "Activating supersedes the version in force and re-prompts every person."),
        draft: uiText("vidyalms.ui.ebf12ef47cf575b3", "Draft"),
        active: uiText("vidyalms.ui.92340695899bd2d8", "Active"),
        superseded: uiText("vidyalms.ui.e5830d265452f1ed", "Superseded"),
        days: (n: number) => uiText("vidyalms.ui.39b7f98fd359ccb4", "{p0} days", { p0: n }),
        floor: uiText("vidyalms.ui.e45fb674c138ba1c", "The floor is 180 days, which is the deployer log period the AI Act requires."),
        form: {
            title: uiText("vidyalms.ui.7e8cd2056da73a7f", "Title"),
            body: uiText("vidyalms.ui.c10162a93f18c13e", "Policy text"),
            retention: uiText("vidyalms.ui.7c39ce0a1b137958", "Retention in days"),
            redaction: uiText("vidyalms.ui.19aad2349b54f7ff", "Redaction level"),
            save: uiText("vidyalms.ui.3de100106dd4e52e", "Save draft"),
            cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
        },
    },
    routes: {
        title: uiText("vidyalms.ui.2c5b669bcdeb51bf", "Routes"),
        intro:
            uiText("vidyalms.ui.b5ba9286d01bde1f", "A route binds one task to one bridge, in order. A free site has none, which is why every ") +
            uiText("vidyalms.ui.1a4d943bbb449dc1", "task reports that no model is configured rather than failing when somebody clicks."),
        empty: uiText("vidyalms.ui.e7b212f093d1c098", "No route is configured. Every task refuses with \"not configured\"."),
        task: uiText("vidyalms.ui.4bc74b21357c6cf5", "Task"),
        bridge: uiText("vidyalms.ui.3892e10346fd2012", "Bridge"),
        model: uiText("vidyalms.ui.5e2c614c23f02239", "Model"),
        position: uiText("vidyalms.ui.6be090825c997103", "Order"),
        ceiling: uiText("vidyalms.ui.7239640bb8eae4b6", "Ceiling per run"),
        enabled: uiText("vidyalms.ui.92c1cdfdf4cb9cf6", "Enabled"),
        missingBridge: uiText("vidyalms.ui.f13d1e2079596c63", "No installed package answers this bridge key."),
    },
    runs: {
        title: uiText("vidyalms.ui.848f54e89660cea4", "Runs"),
        intro:
            uiText("vidyalms.ui.8b1efc69041f8f7f", "One row per invocation, including the refused and the previewed ones. Every attempt writes ") +
            uiText("vidyalms.ui.59cb4e91a85eb4bb", "its own row before the success test, so an outage or a billing dispute is answerable."),
        empty: uiText("vidyalms.ui.27f8f6afa151efff", "No run has been recorded yet."),
        filterState: uiText("vidyalms.ui.a3b50c476732c740", "State"),
        filterDryRun: uiText("vidyalms.ui.d5da154d9f00ca14", "Dry run"),
        filterAll: uiText("vidyalms.ui.a52ace420f2175d0", "All"),
        yes: uiText("vidyalms.ui.85a39ab345d672ff", "Yes"),
        no: uiText("vidyalms.ui.1ea442a134b2a184", "No"),
        when: uiText("vidyalms.ui.cf9c7aa24a26aac4", "When"),
        task: uiText("vidyalms.ui.4bc74b21357c6cf5", "Task"),
        who: uiText("vidyalms.ui.c17b94b8c9394d40", "Who"),
        state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
        cost: uiText("vidyalms.ui.204a5eb2cd28bcfd", "Cost"),
        attempts: uiText("vidyalms.ui.06e70139fcf5e5c7", "Attempts"),
        open: uiText("vidyalms.ui.ed077f3d8125d60d", "Open"),
        back: uiText("vidyalms.ui.5f3641135a1e889b", "Back to runs"),
        detailTitle: (id: number) => uiText("vidyalms.ui.45f2908280baa25e", "Run {p0}", { p0: id }),
        attemptsTitle: uiText("vidyalms.ui.06e70139fcf5e5c7", "Attempts"),
        attemptNo: uiText("vidyalms.ui.c934cc71493d4673", "Attempt"),
        attemptBridge: uiText("vidyalms.ui.3892e10346fd2012", "Bridge"),
        attemptOutcome: uiText("vidyalms.ui.4e80abb5b14656d4", "Outcome"),
        attemptRefusal: uiText("vidyalms.ui.512d9d30934fb39c", "Refusal"),
        attemptLatency: uiText("vidyalms.ui.e0e7d293043c8cf6", "Latency"),
        success: uiText("vidyalms.ui.6d9a6f97a5fdaf3b", "Succeeded"),
        failed: uiText("vidyalms.ui.031a8f0f659df890", "Failed"),
        manifestTitle: uiText("vidyalms.ui.4142b9bba7683b32", "What would be sent"),
        manifestIntro:
            uiText("vidyalms.ui.a43bd977971f4a52", "The redaction manifest of this run, one provenance label per span that left or would have ") +
            uiText("vidyalms.ui.ad46c79d1b666980", "left the site. ") +
            uiText("vidyalms.ui.509b0b3f922cb688", "A dry run renders it and calls no model at all."),
        manifestEmpty: uiText("vidyalms.ui.1edf01614419615a", "This run recorded no manifest."),
        unmetered: uiText("vidyalms.ui.2580b1f02ef67da9", "This bridge reported no usage, so the cost is unknown rather than zero."),
    },
    drafts: {
        title: uiText("vidyalms.ui.f592e6a4db3c6638", "Drafts"),
        intro:
            uiText("vidyalms.ui.1b8d1e272b52c25d", "Rejection is the default state. The findings are shown with the draft rather than behind it, ") +
            uiText("vidyalms.ui.18cd82fbb06f1743", "because a reviewer shown a clean surface approves by default."),
        empty: uiText("vidyalms.ui.be914c9f4a6b9720", "No draft is waiting for a decision."),
        kind: uiText("vidyalms.ui.f5387f9bb6ed7031", "Kind"),
        state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
        blocking: uiText("vidyalms.ui.f778a33133e5c30b", "Blocking"),
        advisory: uiText("vidyalms.ui.c1dd64b75680d92a", "Advisory"),
        accept: uiText("vidyalms.ui.89713b9c9c1b8f65", "Accept"),
        reject: uiText("vidyalms.ui.ab604a360777735f", "Reject"),
        acceptBlocked: uiText("vidyalms.ui.8d777a68760a2365", "This draft has blocking findings. Acknowledge them before accepting."),
        acknowledge: uiText("vidyalms.ui.5bbcc32fd64752d6", "I have read the blocking findings"),
        noAcceptor: (kind: string) => uiText("vidyalms.ui.00fe6031efabb0d5", "No installed package can accept a \"{p0}\" draft, so nothing would be written.", { p0: kind }),
        reasonLabel: uiText("vidyalms.ui.9e13ec9ee6a95a38", "Reason code"),
        reasonPlaceholder: uiText("vidyalms.ui.c245b0b8e83cb94a", "distractor_implausible"),
        findings: uiText("vidyalms.ui.e171c2ff25b55e5a", "Findings"),
        findingSeverity: uiText("vidyalms.ui.5e9f98120dbe5682", "Severity"),
        findingMessage: uiText("vidyalms.ui.59d032ea6dfc4496", "Finding"),
        accepted: uiText("vidyalms.ui.a00fb0c50741f81b", "Accepted"),
        rejected: uiText("vidyalms.ui.753d654847b2e45b", "Sent back"),
    },
    usage: {
        title: uiText("vidyalms.ui.0af7fad52ba59e36", "Usage and oversight"),
        intro:
            uiText("vidyalms.ui.4beb032195d2c8b3", "Where the money went, and how much of it was reviewed. A site owner cannot set an honest ") +
            uiText("vidyalms.ui.a881b2e2c7acb7db", "ceiling without seeing where the money went."),
        totals: uiText("vidyalms.ui.6bd6df68b5e9d5a5", "Totals"),
        byTask: uiText("vidyalms.ui.f99a5eeffd4abd14", "By task"),
        byPlacement: uiText("vidyalms.ui.dd9fa8b46d657c08", "By helper"),
        byPerson: uiText("vidyalms.ui.195e76d9f5cb5d35", "By person"),
        refusals: uiText("vidyalms.ui.86f941bc03c2bbbc", "Refusals"),
        oversight: uiText("vidyalms.ui.141fe8858d27fb6f", "Oversight"),
        unit: uiText("vidyalms.ui.4e545960f1bffc13", "Unit"),
        runs: uiText("vidyalms.ui.848f54e89660cea4", "Runs"),
        refused: uiText("vidyalms.ui.66b873543aebf01b", "Refused"),
        spend: uiText("vidyalms.ui.85f3d558db120199", "Spend"),
        unknown: uiText("vidyalms.ui.b764cdc0eab71374", "Unknown"),
        unmetered: uiText("vidyalms.ui.96219226d04e559e", "No bridge on this site meters usage, so the spend is unmetered rather than zero."),
        acceptedCount: uiText("vidyalms.ui.a00fb0c50741f81b", "Accepted"),
        uneditedCount: uiText("vidyalms.ui.44012e8328ca4491", "Accepted unedited"),
        rate: uiText("vidyalms.ui.44012e8328ca4491", "Accepted unedited"),
        median: uiText("vidyalms.ui.62084d93c985bc82", "Median edit distance"),
        oversightHint:
            uiText("vidyalms.ui.7cdb6c4f8701d3a5", "A task sitting near 100 percent accepted unedited is either excellent or unreviewed. ") +
            uiText("vidyalms.ui.1da3de1f62ea97eb", "The published classroom benchmark is 74.4 percent unedited across 3,617 drafted messages."),
        oversightEmpty: uiText("vidyalms.ui.8f9a3947cc4f87de", "No draft has been accepted yet, so there is nothing to measure."),
        ceilings: uiText("vidyalms.ui.ef020ef9d18e1706", "Ceilings"),
        ceilingPolicy: uiText("vidyalms.ui.c611981fab983bc6", "Policy"),
        ceilingDecision: uiText("vidyalms.ui.640ae4baf96061fe", "Decision"),
        empty: uiText("vidyalms.ui.4b4a502fb064fbc2", "No run has been recorded in this window."),
    },
} as const;
