import { translate as uiText } from '@/shared/i18n';
/*
 * Copy of the two notification site pages. Same rules as every other strings
 * module: one typed object, plain and factual, no em-dashes and no
 * en-dashes anywhere.
 */
export const notificationStrings = {
    region: uiText("vidyalms.ui.35a8b8e286943161", "Email and notification settings"),
    preferences: {
        title: uiText("vidyalms.ui.788011833a5a0f22", "Notifications"),
        intro:
            uiText("vidyalms.ui.0d558263d79a6e6a", "Choose how often you hear from us about each kind of message. Changes take effect on the next message,")
            + uiText("vidyalms.ui.68dd1917ae7d71bb", " and nothing here costs anything or needs a reason."),
        columnCategory: uiText("vidyalms.ui.73250fd78ba61d54", "Kind of message"),
        frequencies: {
            immediate: uiText("vidyalms.ui.45a6c7e91a83a593", "Straight away"),
            daily: uiText("vidyalms.ui.6c126a03ea7fc009", "Once a day"),
            weekly: uiText("vidyalms.ui.393349fba6adc153", "Once a week"),
            never: uiText("vidyalms.ui.6300ef800bb88429", "Never"),
        } as Record<string, string>,
        locked: uiText("vidyalms.ui.aa664ed2b3dcf3b1", "Set by the site"),
        lockedHint: uiText("vidyalms.ui.2151b0e714ad56bd", "The site sends this to everybody and it cannot be turned off here."),
        transactional: uiText("vidyalms.ui.aa705f9c94595781", "Always sent"),
        transactionalHint:
            uiText("vidyalms.ui.ea6a3df3db43d463", "Account and certificate messages are not marketing. A site may be required to send them, so they are not")
            + uiText("vidyalms.ui.e6accd37dff73041", " affected by these settings."),
        save: uiText("vidyalms.ui.089e57e38f9325bd", "Save preferences"),
        saving: uiText("vidyalms.ui.23e39291d6135814", "Saving…"),
        saved: uiText("vidyalms.ui.60d6766a9e62463a", "Preferences saved."),
        empty: uiText("vidyalms.ui.97a898b0614db477", "This site sends no notifications you can choose between yet."),
        error: uiText("vidyalms.ui.931edca2c568600b", "Your preferences could not be loaded."),
        digestNote: uiText("vidyalms.ui.65cb44959ea2088d", "Once a day and once a week collect that kind of message into one email."),
        substituted: (stored: string) =>
            uiText("vidyalms.ui.fd98cf2d585ce1f5", "This site can no longer collect messages {p0}, so straight away is shown instead.", { p0: stored.toLowerCase() }),
    },
    unsubscribe: {
        title: uiText("vidyalms.ui.3e92efb7664f0255", "Unsubscribe"),
        /* The GET renders and never acts. Nothing on this page has happened
           until the person presses the button. */
        intro: (category: string) => uiText("vidyalms.ui.574d8b25d8a2f516", "This will stop {p0} messages reaching you.", { p0: category }),
        nothingYet: uiText("vidyalms.ui.e3325693b7aef33a", "Nothing has changed yet. Press the button below to confirm."),
        confirm: uiText("vidyalms.ui.3e92efb7664f0255", "Unsubscribe"),
        working: uiText("vidyalms.ui.542c5c2a0ca29ce0", "Unsubscribing…"),
        done: (category: string) => uiText("vidyalms.ui.0a30707834b203b0", "Done. You will not receive {p0} messages.", { p0: category }),
        already: uiText("vidyalms.ui.59ba01e21af35799", "You are already unsubscribed from these messages."),
        keeps: uiText("vidyalms.ui.0934d029dd6881a8", "This does not stop:"),
        keepsHint:
            uiText("vidyalms.ui.c46830d023fc5d9e", "Account, certificate and other required messages keep arriving. Unsubscribing from one kind of message")
            + uiText("vidyalms.ui.7d36f9b426feb14c", " never blocks your email address."),
        stillReceiving: uiText("vidyalms.ui.8083c38f1b6b12a0", "You still receive:"),
        channels: uiText("vidyalms.ui.6687458beee57a58", "Applies to"),
        invalidTitle: uiText("vidyalms.ui.450e262c41cce10b", "This link is no longer valid"),
        invalidBody:
            uiText("vidyalms.ui.372ea96f21b65a09", "The link may have been mistyped, or it may be older than the site allows. You can change every setting")
            + uiText("vidyalms.ui.63a31deb854daab9", " from your account instead."),
        managePreferences: uiText("vidyalms.ui.586a5f89cb4cb7fb", "Manage all notification settings"),
        home: uiText("vidyalms.ui.72b912edf5f9ee55", "Go to the site"),
        noToken: uiText("vidyalms.ui.3c9d3e2752b0cdda", "This page needs an unsubscribe link from one of our messages."),
    },
} as const;
