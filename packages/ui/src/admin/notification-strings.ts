import { translate as uiText } from '@/shared/i18n';
/*
 * The admin notifications screen: provider settings, the site policy matrix,
 * suppressions, the consent record and the delivery feedback endpoint.
 *
 * Same rules as admin/strings.ts. No em-dashes and no en-dashes anywhere.
 */
export const notificationAdminStrings = {
    screen: uiText("vidyalms.ui.788011833a5a0f22", "Notifications"),
    tabs: {
        rules: uiText("vidyalms.ui.afd8c121d8c9608b", "Notification rules"),
        delivery: uiText("vidyalms.ui.ce76b95a5ebd4b7b", "Delivery controls"),
        providers: uiText("vidyalms.ui.4c8906cf76f5740a", "Channels"),
        policies: uiText("vidyalms.ui.ccad15c31c9f3a38", "Learner preferences"),
        suppressions: uiText("vidyalms.ui.9304c6c1a8f6a497", "Blocked addresses"),
        consents: uiText("vidyalms.ui.fd08db7f3dee5f6e", "Consent history"),
        feedback: uiText("vidyalms.ui.fdb9aeefe6dcd0f2", "Delivery reports"),
    },

    policies: {
        title: uiText("vidyalms.ui.7ae0863e5c1a49e6", "What this site sends"),
        intro: uiText("vidyalms.ui.31ed487a660df3e5", "Choose which message categories are enabled on each channel and whether learners can change their preferences."),
        marketingRule: uiText("vidyalms.ui.5a16e195105b18b5", "Marketing messages always respect consent. Required delivery is available only for eligible account and learning messages. A disabled channel stops all messages regardless of these preferences."),
        states: {
            disallowed: uiText("vidyalms.ui.f1f9b3b9ab0ed9a0", "Off for everyone"),
            permitted: uiText("vidyalms.ui.9eb041aaae4452e0", "On, learner can change"),
            forced: uiText("vidyalms.ui.f072ba260a338bd2", "Required, learner cannot change"),
        } as Record<string, string>,
        defaultFrequency: uiText("vidyalms.ui.18601e569542aab1", "Default for a new account"),
        category: uiText("vidyalms.ui.73250fd78ba61d54", "Kind of message"),
        save: uiText("vidyalms.ui.57ee14ce1425ca9b", "Save policy"),
        saved: uiText("vidyalms.ui.ce26d73887af792a", "Policy saved"),
        empty: uiText("vidyalms.ui.4ebdf569b937ed9f", "No categories are declared yet."),
    },

    suppressions: {
        title: uiText("vidyalms.ui.b31a245eea278a6d", "Suppressed addresses"),
        intro:
            uiText("vidyalms.ui.0e6b179ace07cd41", "A deliverability fact about one address, not a preference. It binds every message class, including the")
            + uiText("vidyalms.ui.5c6659e1b200dc7b", " ones a site may be obliged to send, which is exactly why an unsubscribe never writes one."),
        privacy:
            uiText("vidyalms.ui.a63b0215b3afacdf", "Addresses are stored as a digest and are never shown. Look one up by typing it: it is hashed before it")
            + uiText("vidyalms.ui.e1985fe1157f98e8", " leaves this page."),
        lookup: uiText("vidyalms.ui.97908387adab33b4", "Look up an address"),
        lookupHint: uiText("vidyalms.ui.6db469d63e0c7991", "Hashed on arrival. It is never stored or echoed back."),
        add: uiText("vidyalms.ui.855014b8eb655a1f", "Suppress this address"),
        release: uiText("vidyalms.ui.e020e3c67bd0c312", "Release"),
        reasons: {
            hard_bounce: uiText("vidyalms.ui.c6bb0dbbcbe56513", "Hard bounce"),
            complaint: uiText("vidyalms.ui.f5a84a3c39e9d9a0", "Complaint"),
            manual: uiText("vidyalms.ui.cd55f7e0a745f437", "Added by hand"),
        } as Record<string, string>,
        manualWarning:
            uiText("vidyalms.ui.186842019fcfd62e", "Suppressing an address by hand stops every message to it, including certificate and account mail."),
        columns: {
            digest: uiText("vidyalms.ui.5fafb6e80fbdde29", "Address digest"),
            channel: uiText("vidyalms.ui.ce4683e7013a18cd", "Channel"),
            reason: uiText("vidyalms.ui.f81ab834de5f8491", "Reason"),
            firstSeen: uiText("vidyalms.ui.32fbd28bce07040c", "First seen"),
            lastSeen: uiText("vidyalms.ui.21fd79c7de52e1cf", "Last seen"),
            state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
        },
        active: uiText("vidyalms.ui.435c78310f60812e", "Suppressed"),
        released: uiText("vidyalms.ui.f33deea9b1009db3", "Released"),
        softBounces: (n: number) => uiText("vidyalms.ui.07058d9a1eee5e94", "{p0} soft bounces", { p0: n }),
        none: uiText("vidyalms.ui.a6845129595da031", "Nothing is suppressed."),
    },

    consents: {
        title: uiText("vidyalms.ui.fd08db7f3dee5f6e", "Consent history"),
        intro:
            uiText("vidyalms.ui.fb86f52758027aad", "Append only, with the lawful basis on every row. A withdrawal is a new row and never a deleted one,")
            + uiText("vidyalms.ui.e1fd9f07db0aac65", " because a deleted row cannot tell \"never consented\" from \"objected\"."),
        pick: uiText("vidyalms.ui.06b832778a617f07", "Whose record"),
        columns: {
            category: uiText("vidyalms.ui.73250fd78ba61d54", "Kind of message"),
            state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
            basis: uiText("vidyalms.ui.988015bfb5ebea95", "Lawful basis"),
            source: uiText("vidyalms.ui.cebc8d0d5452af1b", "Recorded by"),
            when: uiText("vidyalms.ui.cf9c7aa24a26aac4", "When"),
        },
        states: {
            granted: uiText("vidyalms.ui.588f4572935611b8", "Consented"),
            withdrawn: uiText("vidyalms.ui.00c0b03fcfa2ac02", "Withdrawn"),
            objected: uiText("vidyalms.ui.35a91c609e79cc99", "Objected"),
        } as Record<string, string>,
        bases: {
            consent: uiText("vidyalms.ui.d37e0cd00f18a366", "Consent"),
            soft_opt_in: uiText("vidyalms.ui.f85022f7a6ba18e5", "Soft opt-in"),
            contract: uiText("vidyalms.ui.a6f5b0e592fa68e4", "Contract"),
            legitimate_interests: uiText("vidyalms.ui.5664fbfd85fd3917", "Legitimate interests"),
        } as Record<string, string>,
        none: uiText("vidyalms.ui.d915608e61058763", "No consent rows for this person."),
    },

    feedback: {
        title: uiText("vidyalms.ui.fdb9aeefe6dcd0f2", "Delivery reports"),
        intro:
            uiText("vidyalms.ui.398a0b6135b999f2", "Bounces and complaints arrive here and are mapped to one neutral shape before anything is written. A new")
            + uiText("vidyalms.ui.52794796068c9031", " mail provider is a new mapper and touches nothing else."),
        endpoint: uiText("vidyalms.ui.3df9726c68ba76d6", "Endpoint"),
        endpointPath: (channel: string) => uiText("vidyalms.ui.3b2b23d9bd39ac3b", "POST /v1/notifications/feedback/{p0}", { p0: channel }),
        secret:
            uiText("vidyalms.ui.c9256a1804cd6c93", "Authenticated by a shared secret per channel, held in that channel provider settings and sent in the")
            + uiText("vidyalms.ui.188b437d95dd2a47", " X-VidyaLMS-Feedback-Secret header. A request with no valid secret is refused without saying whether")
            + uiText("vidyalms.ui.8907757c61cfb93e", " the channel exists."),
        mappers: uiText("vidyalms.ui.573d0cc50279bbdf", "Registered mappers"),
        noMappers:
            uiText("vidyalms.ui.7924e9896040257b", "No mapper is registered. A report that arrives is logged as it came and no address is suppressed, which")
            + uiText("vidyalms.ui.99eb813738cd6217", " is the published behaviour of this seam rather than an error."),
        unparsed:
            uiText("vidyalms.ui.3184ea17df03cf0f", "A payload no mapper claims is accepted, logged unparsed and writes no suppression."),
    },

    common: {
        loadError: uiText("vidyalms.ui.32ccbd5bb8bde196", "Could not load."),
        retry: uiText("vidyalms.ui.d8b8392e2c542950", "Try again"),
        cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
        save: uiText("vidyalms.ui.1509f561f2416598", "Save"),
        none: uiText("vidyalms.ui.dc937b59892604f5", "None"),
        all: uiText("vidyalms.ui.a52ace420f2175d0", "All"),
    },
} as const;
