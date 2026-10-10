import { translate as uiText } from '@/shared/i18n';
/*
 * Copy of the credential site pages: the holder's own credentials and the
 * public verification page.
 *
 * Its own catalogue, one typed object, no scattered literals. Tone per the
 * Foundations artboard: plain, neutral, factual. No em-dashes and no
 * en-dashes anywhere.
 */

function plural(n: number, one: string, many: string): string {
    return n === 1 ? one : many;
}

export const credentialPageStrings = {
    region: uiText("vidyalms.ui.f2d16be1a937ceab", "VidyaLMS credentials"),

    mine: {
        learnerNavigation: uiText("vidyalms.ui.918a98c75364d2cf", "Learning navigation"),
        myLearning: uiText("vidyalms.ui.c60fdfd4dca90d4d", "My learning"),
        organisations: uiText("vidyalms.ui.0f9dc2ef2e4c9015", "My organizations"),
        preferences: uiText("vidyalms.ui.19a58710832b20e2", "Notification preferences"),
        certificatePages: uiText("vidyalms.ui.3f7e7aa114901fb3", "Certificate pages"),
        badgePages: uiText("vidyalms.ui.d2874b5ca7547da3", "Badge pages"),
        title: uiText("vidyalms.ui.2b3ceb0239a6f99e", "My credentials"),
        intro:
            uiText("vidyalms.ui.cb686af8ef1893c9", "Every certificate and badge issued to this account. A code is the link to its own public ")
            + uiText("vidyalms.ui.4dc5b81c9e3adac4", "verification page, which anyone can check without an account."),
        certificates: uiText("vidyalms.ui.16f637921eba69b0", "Certificates"),
        badges: uiText("vidyalms.ui.185d8ef0ae5ea9b8", "Badges"),
        identities: uiText("vidyalms.ui.7e6c4f05a364da9a", "Continuing-education details"),
        empty: uiText("vidyalms.ui.e63c786a5a581f61", "Nothing issued to this account yet."),
        badgesEmpty: uiText("vidyalms.ui.71b98b0825acef06", "No badges yet."),
        badgesUnavailable: uiText("vidyalms.ui.effd8fd356471a18", "This site issues no badges."),
        loading: uiText("vidyalms.ui.b401ddad9452ed30", "Loading your credentials"),
        columns: {
            course: uiText("vidyalms.ui.2096cefba06a32ba", "Course"),
            code: uiText("vidyalms.ui.340f463033e0fd5d", "Code"),
            issued: uiText("vidyalms.ui.0221e48751889912", "Issued"),
            status: uiText("vidyalms.ui.920e413c7d411b61", "Status"),
            actions: uiText("vidyalms.ui.ff8059dc6752afdd", "Actions"),
        },
        issuedOn: (date: string) => uiText("vidyalms.ui.fea9a11c789c036f", "Issued {p0}", { p0: date }),
        completedOn: (date: string) => uiText("vidyalms.ui.b19a6073485e67f4", "Completed {p0}", { p0: date }),
        expiresOn: (date: string) => uiText("vidyalms.ui.f3e641f173ca173a", "Expires {p0}", { p0: date }),
        expiredOn: (date: string) => uiText("vidyalms.ui.f42f5a17470b5d3c", "Expired {p0}", { p0: date }),
        validFrom: (date: string) => uiText("vidyalms.ui.ceeb0bb2032c257f", "Valid from {p0}", { p0: date }),
        revokedOn: (date: string) => uiText("vidyalms.ui.a079537ac0a1125d", "Revoked {p0}", { p0: date }),
        credits: (n: number) => uiText("vidyalms.ui.779fecf8c1108723", "{p0} credit {p1}", { p0: n, p1: plural(n, 'entry', 'entries') }),
        creditLine: (amount: string, unit: string, scheme: string) => uiText("vidyalms.ui.7d5443cc0e53f07d", "{p0} {p1} ({p2})", { p0: amount, p1: unit, p2: scheme }),
        verify: uiText("vidyalms.ui.d3aad9d24fa73223", "Public verification page"),
        verifyNamed: (code: string) => uiText("vidyalms.ui.343507955ad21b08", "Open the public verification page for {p0}", { p0: code }),
        download: uiText("vidyalms.ui.d6eafe8235910042", "Download"),
        downloadNamed: (code: string) => uiText("vidyalms.ui.d67ad4bc6e1688f9", "Download the certificate {p0}", { p0: code }),
        downloading: uiText("vidyalms.ui.74a6b5348c06cde0", "Preparing..."),
        downloadReady: uiText("vidyalms.ui.a0ce695d442af512", "The download link is ready and expires shortly."),
        openArtefact: uiText("vidyalms.ui.bd5f6b1d9360a891", "Open the file"),
        notRendered:
            uiText("vidyalms.ui.a979410a1fc515bd", "This certificate has not been rendered yet. Rendering is a scheduled task, so try again in a ")
            + uiText("vidyalms.ui.50d911847a129435", "moment."),
        credential: uiText("vidyalms.ui.2a2a99ecf01364e6", "Machine-readable credential"),
        credentialNamed: (code: string) => uiText("vidyalms.ui.dff1707744795c53", "Open the machine-readable credential for {p0}", { p0: code }),
        credentialHint:
            uiText("vidyalms.ui.a79937519136734d", "The credential document a wallet or a verifier reads. The free build serves it unsigned and says ")
            + uiText("vidyalms.ui.ab9ef92b97a7e28e", "so, rather than inviting a wallet to verify something with no securing mechanism."),
        badgeImage: uiText("vidyalms.ui.862f10a86847b02d", "Badge image"),
        badgeImageNamed: (code: string) => uiText("vidyalms.ui.0e73146708819391", "Open the badge image for {p0}", { p0: code }),
        identitiesHint:
            uiText("vidyalms.ui.bd71f67e87ba3920", "A credit scheme asks for these so the credit it awards can be reported. They are stored behind the ")
            + uiText("vidyalms.ui.294ff5eb50653c04", "scheme and never in your account."),
        identitiesEmpty: uiText("vidyalms.ui.a37040f2db5319ab", "No credit scheme on this site asks for anything."),
        identityUnavailable: uiText("vidyalms.ui.47d3927175055e9b", "The scheme that asked for this is no longer installed."),
        save: uiText("vidyalms.ui.bb76faabfd0b8e65", "Save details"),
        saving: uiText("vidyalms.ui.dc85af8f2b1d0d67", "Saving..."),
        saved: uiText("vidyalms.ui.af94af107781626f", "Your details were saved."),
        error: uiText("vidyalms.ui.55c202b648170936", "Your credentials did not load."),
        signIn: uiText("vidyalms.ui.0c3a28e9bc4a04d0", "Sign in to see the credentials issued to you."),
    },

    verify: {
        title: uiText("vidyalms.ui.4e353f6d4fbfc246", "Verify a certificate"),
        titleBadge: uiText("vidyalms.ui.fc13a5980ea237f7", "Verify a badge"),
        intro: uiText("vidyalms.ui.ec02e1da8a54a95e", "Enter the code printed on the certificate. No account needed."),
        introBadge: uiText("vidyalms.ui.d559a3b0fd3c4b5f", "Enter the code from the badge. No account needed."),
        codeLabel: uiText("vidyalms.ui.ea8334305ce1f682", "Certificate code"),
        codeLabelBadge: uiText("vidyalms.ui.b6d3dac066a47f8e", "Badge code"),
        codeHint: uiText("vidyalms.ui.e19ed381aa41992d", "Hyphens and spaces are optional; the code is matched either way."),
        submit: uiText("vidyalms.ui.eea2745e2867a677", "Verify"),
        checking: uiText("vidyalms.ui.2e5f79bb94a8c40b", "Checking..."),
        resultRegion: uiText("vidyalms.ui.507b2e38e81b4a4e", "Verification result"),
        /* Verification is not validation, and the page must not imply
           otherwise: it answers that the credential conforms and what its
           status is, never whether the holder can do the job. */
        scope:
            uiText("vidyalms.ui.3384a9e0f58f91d3", "This page answers whether the credential is genuine and what its status is. Whether the ")
            + uiText("vidyalms.ui.19c1fa86bd3bfb57", "achievement means the holder can do a particular job is a separate judgement and is not ")
            + uiText("vidyalms.ui.83992f506e87313b", "answered here."),
        headings: {
            active: uiText("vidyalms.ui.3adad5388df9ba62", "Valid certificate"),
            not_yet_valid: uiText("vidyalms.ui.7dd16af437043de2", "Not yet valid"),
            expired: uiText("vidyalms.ui.7ce61658a31b88cd", "Expired certificate"),
            suspended: uiText("vidyalms.ui.35d3485eeee6ae94", "Suspended certificate"),
            revoked: uiText("vidyalms.ui.e14aadec91eae787", "Revoked certificate"),
            superseded: uiText("vidyalms.ui.e6692350d159d2f9", "Replaced by a newer certificate"),
            not_found: uiText("vidyalms.ui.828758d0b3a4e1b8", "No certificate matches this code"),
        } as Record<string, string>,
        reasons: {
            not_found:
                uiText("vidyalms.ui.d31f1ef6dedb48c7", "Check for typos. Codes are twelve characters and the hyphens are optional."),
            revoked: uiText("vidyalms.ui.dc70b2111cdc03f8", "The issuer withdrew this certificate. It stays on record and this page keeps answering."),
            expired: uiText("vidyalms.ui.d965f8d5db4efee1", "This certificate was valid and its validity period has passed."),
            suspended:
                uiText("vidyalms.ui.63700b4ca632622f", "The issuer has placed a reversible hold on this certificate. It is neither withdrawn nor ")
                + uiText("vidyalms.ui.66576e39829793ef", "currently in force."),
            superseded:
                uiText("vidyalms.ui.0ffd05a6105fd116", "A corrected certificate replaced this one. The replacement is the certificate in force."),
            not_yet_valid: uiText("vidyalms.ui.82b7379d6bea9cd9", "This certificate was issued ahead of the date it takes effect."),
        } as Record<string, string>,
        labels: {
            issuedTo: uiText("vidyalms.ui.17b043ed1a3c1e50", "Issued to"),
            course: uiText("vidyalms.ui.2096cefba06a32ba", "Course"),
            achievement: uiText("vidyalms.ui.002474e368210827", "Badge"),
            completed: uiText("vidyalms.ui.22a970d2e5b1cc23", "Completed"),
            issued: uiText("vidyalms.ui.0221e48751889912", "Issued"),
            issuedBy: uiText("vidyalms.ui.5f06f11875a68aed", "Issued by"),
            code: uiText("vidyalms.ui.340f463033e0fd5d", "Code"),
            validFrom: uiText("vidyalms.ui.9a56d91b557102a1", "Valid from"),
            expires: uiText("vidyalms.ui.f6725f3af08a06a2", "Expires"),
            expired: uiText("vidyalms.ui.424a2551d356754c", "Expired"),
            revoked: uiText("vidyalms.ui.f6f738d043929f59", "Revoked"),
            credit: uiText("vidyalms.ui.fb1875d0a5e85f29", "Credit"),
            status: uiText("vidyalms.ui.920e413c7d411b61", "Status"),
        },
        nameWithheld: uiText("vidyalms.ui.cbc80582fc3f8a48", "Withheld by the issuer"),
        replacement: uiText("vidyalms.ui.335c8ce3e2008c6d", "Verify the replacement"),
        credential: uiText("vidyalms.ui.2a2a99ecf01364e6", "Machine-readable credential"),
        checkedAt: (stamp: string) => uiText("vidyalms.ui.17f5f3cadba7bd7c", "Checked {p0}", { p0: stamp }),
        emptyCode: uiText("vidyalms.ui.05eb8e7e6c85d373", "Enter a code to check it."),
        error: uiText("vidyalms.ui.ed0ac88e907dbea3", "The check could not be completed. Try again in a moment."),
        rateLimited: uiText("vidyalms.ui.c4bdac14f283fcb7", "Too many checks from here. Wait a moment and try again."),
    },
} as const;
