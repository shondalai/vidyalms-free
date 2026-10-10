import { translate as uiText } from '@/shared/i18n';
/*
 * Copy of the credential screens: the certificate templates, the issuance
 * records and their lifecycle, continuing-education credit and badges.
 *
 * Its own catalogue rather than an addition to admin/strings.ts, for the
 * same reason notification-strings.ts is its own: one feature, one file, and
 * two agents editing one object is a merge rather than a design. Same rules
 * as everywhere else: one typed object, no scattered literals, no em-dashes
 * and no en-dashes.
 */

function plural(n: number, one: string, many: string): string {
    return n === 1 ? one : many;
}

export const credentialStrings = {
    tabs: {
        label: uiText("vidyalms.ui.d48a617c7da5163c", "Credentials"),
        certificates: uiText("vidyalms.ui.16f637921eba69b0", "Certificates"),
        templates: uiText("vidyalms.ui.56b564b75c7fdb48", "Templates"),
        credits: uiText("vidyalms.ui.2a6b24ad28722034", "Credits"),
        badges: uiText("vidyalms.ui.185d8ef0ae5ea9b8", "Badges"),
    },

    /* The six-value enumeration, plus the "valid" filter convenience. The
       precedence is published on the server: revoked, then superseded, then
       suspended, then not yet valid, then expired, then active. */
    statuses: {
        valid: uiText("vidyalms.ui.f5162206318ee964", "Valid"),
        active: uiText("vidyalms.ui.f5162206318ee964", "Valid"),
        not_yet_valid: uiText("vidyalms.ui.7dd16af437043de2", "Not yet valid"),
        expired: uiText("vidyalms.ui.424a2551d356754c", "Expired"),
        suspended: uiText("vidyalms.ui.e392a3891c070abe", "Suspended"),
        revoked: uiText("vidyalms.ui.f6f738d043929f59", "Revoked"),
        superseded: uiText("vidyalms.ui.e5830d265452f1ed", "Superseded"),
    } as Record<string, string>,

    statusMeaning: {
        active: uiText("vidyalms.ui.18f2e541bcef566d", "In force."),
        not_yet_valid: uiText("vidyalms.ui.ed4250d6801a9279", "Issued ahead of its effective date."),
        expired: uiText("vidyalms.ui.adc4dc12e4e7b14b", "Past its validity."),
        suspended: uiText("vidyalms.ui.55be981de09407e1", "A reversible hold."),
        revoked: uiText("vidyalms.ui.00f3449bb24b9b49", "Permanent."),
        superseded: uiText("vidyalms.ui.241c1aeab085fdbf", "Replaced by a later issuance."),
    } as Record<string, string>,

    certificates: {
        chips: {
            valid: uiText("vidyalms.ui.f5162206318ee964", "Valid"),
            active: uiText("vidyalms.ui.92340695899bd2d8", "Active"),
            not_yet_valid: uiText("vidyalms.ui.7dd16af437043de2", "Not yet valid"),
            suspended: uiText("vidyalms.ui.e392a3891c070abe", "Suspended"),
            expired: uiText("vidyalms.ui.424a2551d356754c", "Expired"),
            revoked: uiText("vidyalms.ui.f6f738d043929f59", "Revoked"),
            superseded: uiText("vidyalms.ui.e5830d265452f1ed", "Superseded"),
        },
        actions: uiText("vidyalms.ui.ff8059dc6752afdd", "Actions"),
        details: uiText("vidyalms.ui.45989de49fb7f66d", "Details"),
        detailsNamed: (code: string) => uiText("vidyalms.ui.15dc412b3a74336d", "Show the record of certificate {p0}", { p0: code }),
        suspend: uiText("vidyalms.ui.4948e134182f8420", "Suspend"),
        suspendNamed: (code: string) => uiText("vidyalms.ui.31557981c0d6f2b5", "Suspend certificate {p0}", { p0: code }),
        reinstate: uiText("vidyalms.ui.67cbd55059160905", "Reinstate"),
        reinstateNamed: (code: string) => uiText("vidyalms.ui.5b7d770b5e63ef2f", "Reinstate certificate {p0}", { p0: code }),
        reissue: uiText("vidyalms.ui.4309a43f8197b50b", "Reissue"),
        reissueNamed: (code: string) => uiText("vidyalms.ui.f0dc72efe7723f15", "Reissue certificate {p0}", { p0: code }),
        templateFilter: uiText("vidyalms.ui.0575f29df888a27e", "Template"),
        anyTemplate: uiText("vidyalms.ui.f1fae86f6e5d133b", "Any template"),
    },

    platformSettings: {
        sections: {
            credentials: {
                title: uiText("vidyalms.ui.d48a617c7da5163c", "Credentials"),
                description: uiText("vidyalms.ui.a6489a05ad465edb", "The default certificate and its public verification address."),
            },
            learning: {
                title: uiText("vidyalms.ui.64bbb1ba33d496ca", "Learning activity"),
                description: uiText("vidyalms.ui.fe43fca66016e8f9", "The timing rules that keep progress and expiry work current."),
            },
            access: {
                title: uiText("vidyalms.ui.06947be9f22e4a00", "Access and commerce"),
                description: uiText("vidyalms.ui.724f89ec64d0238d", "How learners receive access to courses on this site."),
            },
        },
        noCommerce:
            uiText("vidyalms.ui.c01387b427a2c8bd", "No commerce provider is connected. Paid-course purchases are unavailable, while manual access still works."),
        selfEnrolmentOffWithoutCommerce:
            uiText("vidyalms.ui.5c4ec02af0143504", "Learners may not enrol themselves. An administrator grants access manually."),
        hostOwned:
            uiText("vidyalms.ui.8419e733d863838d", "This summary is read only. Platform-owned values are edited with the controls provided by the host."),
    },

    detail: {
        title: (code: string) => uiText("vidyalms.ui.9af3660a2c83f46d", "Certificate {p0}", { p0: code }),
        close: uiText("vidyalms.ui.7d9eb7acb13e2462", "Close"),
        sections: {
            provenance: uiText("vidyalms.ui.1c4b8ff60530a1bf", "Provenance"),
            lifecycle: uiText("vidyalms.ui.46459b1fa9194f6a", "Lifecycle"),
            artefact: uiText("vidyalms.ui.1b0fa42abe8b2e42", "Artefact"),
            credential: uiText("vidyalms.ui.b1c42b3ce118093b", "Credential"),
            credits: uiText("vidyalms.ui.fb1875d0a5e85f29", "Credit"),
            statusEntries: uiText("vidyalms.ui.f159f9c679b84e37", "Status list entries"),
            chain: uiText("vidyalms.ui.65afea8eafe336bd", "Supersession"),
        },
        labels: {
            uuid: uiText("vidyalms.ui.360f253bc119442b", "Credential id"),
            template: uiText("vidyalms.ui.0575f29df888a27e", "Template"),
            revision: uiText("vidyalms.ui.2e516d68f42f3e35", "Revision"),
            snapshot: uiText("vidyalms.ui.3e64f54a527e977a", "Rendering from"),
            renderer: uiText("vidyalms.ui.9deb780b4331c60e", "Renderer"),
            engine: uiText("vidyalms.ui.71085db512c2d1b6", "Engine version"),
            issued: uiText("vidyalms.ui.0221e48751889912", "Issued"),
            completed: uiText("vidyalms.ui.22a970d2e5b1cc23", "Completed"),
            validFrom: uiText("vidyalms.ui.9a56d91b557102a1", "Valid from"),
            expires: uiText("vidyalms.ui.f6725f3af08a06a2", "Expires"),
            revoked: uiText("vidyalms.ui.f6f738d043929f59", "Revoked"),
            reason: uiText("vidyalms.ui.f81ab834de5f8491", "Reason"),
            published: uiText("vidyalms.ui.3390762cc5ae54cf", "Name shown publicly"),
            checksum: uiText("vidyalms.ui.11c94bc7fb13afeb", "Checksum"),
            renderedAt: uiText("vidyalms.ui.331e25aab7ff4d49", "Rendered"),
            format: uiText("vidyalms.ui.2f343666aaa88c44", "Format"),
            proof: uiText("vidyalms.ui.7fbb3ccf9640651f", "Proof"),
            earnerId: uiText("vidyalms.ui.cb116d2f21197788", "Issuer earner id"),
            purpose: uiText("vidyalms.ui.d4e8830a71c73edf", "Purpose"),
            list: uiText("vidyalms.ui.6f202f54a7b2d8fe", "List"),
            index: uiText("vidyalms.ui.39c3be1198a2fddf", "Index"),
            supersedes: uiText("vidyalms.ui.11755fd7eddec37a", "Replaces"),
            supersededBy: uiText("vidyalms.ui.05718ca1ac24d536", "Replaced by"),
        },
        snapshotFrozen: uiText("vidyalms.ui.a0abc1f98f4e95f3", "Its own frozen snapshot"),
        snapshotLive:
            uiText("vidyalms.ui.40bf34a543e354c6", "The live template. This row was issued before the snapshot existed, so a template correction ")
            + uiText("vidyalms.ui.65ce6c0d4f103c62", "would change what the holder is shown."),
        artefactMissing: uiText("vidyalms.ui.8e795997cd1927d0", "Not rendered yet. Rendering is a scheduled task, so this fills in shortly after issue."),
        credentialNone: uiText("vidyalms.ui.d43d3464f980871a", "No machine-readable credential was built for this row."),
        proofNone: uiText("vidyalms.ui.2aef46958a7d7cdf", "Unsigned, which is what the free formats are."),
        creditsNone: uiText("vidyalms.ui.d9386f5ee3e8fefc", "This course carries no continuing-education credit."),
        statusEntriesNone: uiText("vidyalms.ui.361d2da1c69c79a1", "This build allocated no status list entry."),
        chainNone: uiText("vidyalms.ui.45452422cb1202e6", "Nothing replaced this certificate and it replaced nothing."),
        yes: uiText("vidyalms.ui.85a39ab345d672ff", "Yes"),
        no: uiText("vidyalms.ui.1ea442a134b2a184", "No"),
        never: uiText("vidyalms.ui.6300ef800bb88429", "Never"),
        loadFailed: uiText("vidyalms.ui.f05aaf35df04f9e0", "The record did not load."),
    },

    /*
     * The line that names who holds the certificate and for what. The
     * ceremony a destructive action needs is a confirmation naming the
     * learner, the course and the consequence; the shared dialog already
     * carries the consequence, so this carries the other two.
     */
    revokeContext: (learner: string, course: string) => uiText("vidyalms.ui.c905a1970a577887", "{p0} holds it for \"{p1}\".", { p0: learner, p1: course }),

    suspend: {
        title: uiText("vidyalms.ui.a95ee7131e80cb5c", "Suspend this certificate?"),
        body: (code: string, learner: string, course: string) =>
            uiText("vidyalms.ui.4e1958de143e0315", "{p0} holds {p1} for \"{p2}\". Suspension is a reversible hold: the public verification ", { p0: learner, p1: code, p2: course })
            + uiText("vidyalms.ui.98280f9e312c02a1", "page will report \"suspended\" rather than \"revoked\", and reinstating it lifts the hold everywhere."),
        reasonLabel: uiText("vidyalms.ui.f81ab834de5f8491", "Reason"),
        reasonHint: uiText("vidyalms.ui.630b9cd6aed25198", "Recorded against the certificate and readable by anyone who audits it later."),
        reasons: {
            payment_dispute: uiText("vidyalms.ui.5745e82c0b7f246f", "Payment dispute"),
            under_investigation: uiText("vidyalms.ui.f9bdb11906ee6022", "Under investigation"),
            registration_lapsed: uiText("vidyalms.ui.987b7dbd88edd988", "Registration lapsed"),
        } as Record<string, string>,
        cancel: uiText("vidyalms.ui.0439ef4c9e156301", "Keep it in force"),
        submit: uiText("vidyalms.ui.e68ccba77071b21d", "Suspend certificate"),
        submitting: uiText("vidyalms.ui.ad9ae9752eaabd0a", "Suspending..."),
        done: (code: string) => uiText("vidyalms.ui.3646d5cb7ca79049", "Certificate {p0} is suspended.", { p0: code }),
    },

    reinstate: {
        title: uiText("vidyalms.ui.146017ae5bb4634b", "Reinstate this certificate?"),
        body: (code: string) =>
            uiText("vidyalms.ui.a63c78b4590e3878", "{p0} returns to \"valid\" and the suspension entry is cleared, so every verifier sees the hold ", { p0: code })
            + uiText("vidyalms.ui.3236c1aa0e363cd4", "lifted at the next status list publication."),
        cancel: uiText("vidyalms.ui.9c27dc44069edaa0", "Leave it suspended"),
        submit: uiText("vidyalms.ui.6ee3ebe3d2512bd4", "Reinstate certificate"),
        submitting: uiText("vidyalms.ui.5c38123e43234c32", "Reinstating..."),
        done: (code: string) => uiText("vidyalms.ui.41cfd2f0a36374ab", "Certificate {p0} is back in force.", { p0: code }),
    },

    reissue: {
        title: uiText("vidyalms.ui.214d2d250b47dd38", "Reissue this certificate?"),
        body: (code: string) =>
            uiText("vidyalms.ui.be2f5ae1b2fecd17", "A correction is a supersession and never an edit. A new certificate is issued with a new code and a ")
            + uiText("vidyalms.ui.8c96dcc44d4c623e", "new snapshot, {p0} becomes \"superseded\", and its code keeps resolving to a page that says so and ", { p0: code })
            + uiText("vidyalms.ui.fc321b776e75d18c", "links onward."),
        carryForward:
            uiText("vidyalms.ui.743bb7ebb51e9e7e", "The new row carries forward the completion date, the validity dates, the recipient identity hash ")
            + uiText("vidyalms.ui.5d26122ec0ea91d8", "and one copy of every credit row."),
        renewal: uiText("vidyalms.ui.1fe86e6e7b716fe3", "Recompute the expiry date from the template"),
        renewalHint:
            uiText("vidyalms.ui.7210aa748588c60f", "A renewal recomputes validity from the template expiry mode instead of carrying the old date ")
            + uiText("vidyalms.ui.ca92b8b7f6f16446", "forward. Leave it off for a correction."),
        reasonLabel: uiText("vidyalms.ui.f81ab834de5f8491", "Reason"),
        reasonHint: uiText("vidyalms.ui.f59b30556e332145", "Recorded on the superseded row, so the trail says why."),
        cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
        submit: uiText("vidyalms.ui.e373159d0833f850", "Reissue certificate"),
        submitting: uiText("vidyalms.ui.99f9d63a76304a5c", "Reissuing..."),
        done: (code: string) => uiText("vidyalms.ui.2b75dc5cc903d04f", "Certificate {p0} was superseded by a new issuance.", { p0: code }),
    },

    templates: {
        title: uiText("vidyalms.ui.336d8e9c07f45785", "Certificate templates"),
        table: uiText("vidyalms.ui.336d8e9c07f45785", "Certificate templates"),
        count: (n: number) => uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: n, p1: plural(n, 'template', 'templates') }),
        empty: uiText("vidyalms.ui.368a75f9d3171c77", "No templates match this filter."),
        create: uiText("vidyalms.ui.30d87ec4525974fb", "New template"),
        createTitle: uiText("vidyalms.ui.1309ab2456e09400", "New certificate template"),
        columns: {
            name: uiText("vidyalms.ui.dcd1d5223f73b3a9", "Name"),
            format: uiText("vidyalms.ui.2f343666aaa88c44", "Format"),
            state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
            revision: uiText("vidyalms.ui.2e516d68f42f3e35", "Revision"),
            pages: uiText("vidyalms.ui.9046da16aea909ba", "Pages"),
        },
        formats: { html: uiText("vidyalms.ui.523ea05bc80e278f", "Token body"), elements: uiText("vidyalms.ui.a30dda2d302c8b2e", "Pages and elements") } as Record<string, string>,
        states: { draft: uiText("vidyalms.ui.ebf12ef47cf575b3", "Draft"), published: uiText("vidyalms.ui.2ef42ec21d9300dd", "Published"), archived: uiText("vidyalms.ui.bdb86505f8062d15", "Archived") } as Record<string, string>,
        chips: { all: uiText("vidyalms.ui.a52ace420f2175d0", "All"), draft: uiText("vidyalms.ui.ebf12ef47cf575b3", "Draft"), published: uiText("vidyalms.ui.2ef42ec21d9300dd", "Published"), archived: uiText("vidyalms.ui.bdb86505f8062d15", "Archived") },
        defaultBadge: uiText("vidyalms.ui.21b111cbfe6e8fca", "Default"),
        lockedBadge: uiText("vidyalms.ui.a424e33d90931d1e", "Locked"),
        lockedHint:
            uiText("vidyalms.ui.1099b74d19bac736", "A certificate has been issued from this template, so its criteria and expiry are frozen. Editing it ")
            + uiText("vidyalms.ui.ee3f26587d259275", "raises the revision, and existing certificates keep rendering from their own snapshot."),
        /* Named on the control itself, because the paragraph above says what
           the lock is and this says which control it reached. The server
           publishes the list; the screen never holds a copy of it. */
        lockedFieldHint:
            uiText("vidyalms.ui.4389ab498dbf4cc6", "Frozen by the lock: a certificate has already been issued from this template, so this field cannot ")
            + uiText("vidyalms.ui.14fa7ab8f7a40eaf", "change on it. Use a new template for a different rule."),
        edit: uiText("vidyalms.ui.464c4ffd019e1e96", "Edit"),
        editNamed: (name: string) => uiText("vidyalms.ui.3354544d5b2c7a14", "Edit the template {p0}", { p0: name }),
        back: uiText("vidyalms.ui.1b58f4276af1681b", "Back to templates"),
        pageCount: (n: number) => uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: n, p1: plural(n, 'page', 'pages') }),
        elementCount: (n: number) => uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: n, p1: plural(n, 'element', 'elements') }),
        fields: {
            name: uiText("vidyalms.ui.dcd1d5223f73b3a9", "Name"),
            key: uiText("vidyalms.ui.df56dc09bd749c79", "Template key"),
            keyHint:
                uiText("vidyalms.ui.aa3203b8af820695", "A stable identifier a package can find this template by after somebody has renamed it. ")
                + uiText("vidyalms.ui.b8dfdb55e16ecca9", "Leave it empty on a template you created here."),
            renderer: uiText("vidyalms.ui.9deb780b4331c60e", "Renderer"),
            format: uiText("vidyalms.ui.2f343666aaa88c44", "Format"),
            formatHint:
                uiText("vidyalms.ui.81fbd755a29c3023", "A token body is one document with placeholders. Pages and elements draws each element through ")
                + uiText("vidyalms.ui.27f8751f82f224f5", "its own provider, which is what the palette below fills."),
            achievementType: uiText("vidyalms.ui.c97a4b1b091b28b7", "Achievement type"),
            achievementHint:
                uiText("vidyalms.ui.648abdf0454b3db9", "The Open Badges 3.0 value the credential carries. A course completion certificate is ")
                + uiText("vidyalms.ui.3a0b5175f464fb98", "CertificateOfCompletion."),
            locale: uiText("vidyalms.ui.ca3dc612f47e3913", "Locale"),
            state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
            isDefault: uiText("vidyalms.ui.35df354e9caa9352", "Use this template when a course names none"),
            body: uiText("vidyalms.ui.6ccaa6415b5ee449", "Body"),
            bodyHint: uiText("vidyalms.ui.36727293ef0bccaf", "Tokens read {source.field} and an unresolved one is left intact rather than blanked."),
            expiryMode: uiText("vidyalms.ui.6956d81401b84d01", "Expiry"),
            expiryDays: uiText("vidyalms.ui.ed6ea3e80e2d8b66", "Days after issue"),
            expiryAt: uiText("vidyalms.ui.71712ec2f7ba4ba8", "Expires on"),
        },
        expiryModes: {
            never: uiText("vidyalms.ui.6a9894204cc74ce5", "Never expires"),
            fixed: uiText("vidyalms.ui.a59cf708f083ba81", "A fixed date"),
            days_after_issue: uiText("vidyalms.ui.68cbb813db019c20", "A number of days after issue"),
        } as Record<string, string>,
        expiryPro:
            uiText("vidyalms.ui.6650a0cf86fbf668", "Expiry policies are registered by a package. Without one the template issues credentials that never ")
            + uiText("vidyalms.ui.cee23f9e2478d2ff", "expire, and the option is shown so nothing is hidden."),
        save: uiText("vidyalms.ui.47f72a2f3e91d32d", "Save template"),
        saving: uiText("vidyalms.ui.dc85af8f2b1d0d67", "Saving..."),
        saved: uiText("vidyalms.ui.fdda48babb9af36f", "Template saved."),
        archive: uiText("vidyalms.ui.2083a59398a7ea62", "Archive template"),
        archiveTitle: uiText("vidyalms.ui.bac0db2c75a04376", "Archive this template?"),
        archiveBody: (name: string) =>
            uiText("vidyalms.ui.9ed35d8b02a353d9", "\"{p0}\" stops being offered to new courses. Nothing already issued changes: every certificate ", { p0: name })
            + uiText("vidyalms.ui.ffd23c56cb763f42", "renders from its own frozen snapshot."),
        archiveCancel: uiText("vidyalms.ui.fdce5da2cec2ff02", "Keep it"),
        archiveSubmit: uiText("vidyalms.ui.2083a59398a7ea62", "Archive template"),
        archived: (name: string) => uiText("vidyalms.ui.ebe0258722003285", "{p0} is archived.", { p0: name }),
    },

    pages: {
        heading: uiText("vidyalms.ui.9046da16aea909ba", "Pages"),
        add: uiText("vidyalms.ui.ca18fd7475a0b71e", "Add a page"),
        added: uiText("vidyalms.ui.3c1a8a3be5fd618f", "Page added."),
        name: (sequence: number) => uiText("vidyalms.ui.dfeb8707a5c4f210", "Page {p0}", { p0: sequence }),
        remove: uiText("vidyalms.ui.c3812fc4acb861d5", "Remove"),
        removeNamed: (sequence: number) => uiText("vidyalms.ui.a105ada52a5e51d8", "Remove page {p0}", { p0: sequence }),
        moveUp: uiText("vidyalms.ui.c66feb5eb8f217c7", "Move up"),
        moveDown: uiText("vidyalms.ui.40bb50da160cdc21", "Move down"),
        moveUpNamed: (sequence: number) => uiText("vidyalms.ui.4da55b70f978ea47", "Move page {p0} earlier", { p0: sequence }),
        moveDownNamed: (sequence: number) => uiText("vidyalms.ui.279e59169f50715b", "Move page {p0} later", { p0: sequence }),
        empty: uiText("vidyalms.ui.ec97ae4483cdb912", "This template has no pages yet. Add one and the palette opens."),
        fields: {
            width: uiText("vidyalms.ui.66194cc31a4f277b", "Width (mm)"),
            height: uiText("vidyalms.ui.c52c57e0197a4149", "Height (mm)"),
            orientation: uiText("vidyalms.ui.e3d11242200fe8f2", "Orientation"),
            marginTop: uiText("vidyalms.ui.712fd235e7bee8ae", "Top margin (mm)"),
            marginRight: uiText("vidyalms.ui.73f4aedf2d8d0e20", "Right margin (mm)"),
            marginBottom: uiText("vidyalms.ui.13adcc3ff0b43518", "Bottom margin (mm)"),
            marginLeft: uiText("vidyalms.ui.7ef8860cf5a4983b", "Left margin (mm)"),
            background: uiText("vidyalms.ui.e10a4ff2dd6ff9cd", "Background image path"),
        },
        orientations: { portrait: uiText("vidyalms.ui.f71094c30160d155", "Portrait"), landscape: uiText("vidyalms.ui.e658d3ae034a3dcf", "Landscape") } as Record<string, string>,
        savePage: uiText("vidyalms.ui.66684832de28fa7b", "Save page"),
    },

    elements: {
        heading: uiText("vidyalms.ui.e1ff7a9061031102", "Elements"),
        empty: uiText("vidyalms.ui.78a82e085dd520fb", "Nothing on this page yet. Choose one from the palette."),
        palette: uiText("vidyalms.ui.85794effd6e85e56", "Palette"),
        paletteHint: uiText("vidyalms.ui.fc9c5f43bfe6528f", "Everything the element registry offers this template. A package adding an element adds it here."),
        add: (label: string) => uiText("vidyalms.ui.dd7ccf7334fa0118", "Add {p0}", { p0: label }),
        added: (label: string) => uiText("vidyalms.ui.fb89878d6918357c", "{p0} added to the page.", { p0: label }),
        unavailable: (reason: string) => uiText("vidyalms.ui.e2743a94e03c4922", "Not available: {p0}", { p0: reason }),
        unavailableGeneric: uiText("vidyalms.ui.b6bf97eb431b0aa1", "Not available on this template."),
        missingProvider:
            uiText("vidyalms.ui.3934752e28c70c31", "No package on this build registers this element. It is kept exactly as authored and renders as a ")
            + uiText("vidyalms.ui.05d081ad13c69691", "visible placeholder naming what is missing."),
        futureShape:
            uiText("vidyalms.ui.a71e5349cd21ae45", "This element was stored by a newer version of its package than this build has, so it is treated ")
            + uiText("vidyalms.ui.8a6e46ba3462f03b", "exactly as an unregistered one."),
        remove: uiText("vidyalms.ui.c3812fc4acb861d5", "Remove"),
        removeNamed: (label: string) => uiText("vidyalms.ui.55fbe401207d0466", "Remove {p0}", { p0: label }),
        moveUpNamed: (label: string) => uiText("vidyalms.ui.ec3254af5b49e508", "Draw {p0} earlier", { p0: label }),
        moveDownNamed: (label: string) => uiText("vidyalms.ui.f251f92d51af7fa0", "Draw {p0} later", { p0: label }),
        drawOrder: uiText("vidyalms.ui.42f2d864ed03d5a1", "Draw order decides what sits on top: a border drawn first stays behind a name drawn after it."),
        fields: {
            name: uiText("vidyalms.ui.0e66373f45dcf3dd", "Label"),
            nameHint: uiText("vidyalms.ui.ead5769c3ab9f284", "Your own name for this instance, used in the layer list and nowhere else."),
            placementHint: uiText('vidyalms.ui.certificate.placement_hint', 'Use whole millimetres. Leave width or height blank for automatic sizing.'),
            positionInvalid: uiText('vidyalms.ui.certificate.position_invalid', 'Enter a whole number of 0 or more.'),
            sizeInvalid: uiText('vidyalms.ui.certificate.size_invalid', 'Enter a whole number greater than 0, or leave blank for automatic sizing.'),
            posx: uiText("vidyalms.ui.22f6cf0a3b0d5032", "X (mm)"),
            posy: uiText("vidyalms.ui.66267271365b2da0", "Y (mm)"),
            width: uiText("vidyalms.ui.66194cc31a4f277b", "Width (mm)"),
            height: uiText("vidyalms.ui.c52c57e0197a4149", "Height (mm)"),
            refpoint: uiText("vidyalms.ui.b4c36c3ff7af1547", "Anchored at"),
            alignment: uiText("vidyalms.ui.9d597870c4b3f76d", "Alignment"),
        },
        refpoints: {
            top_left: uiText("vidyalms.ui.a0eda50fb155e51c", "Top left"),
            top_centre: uiText("vidyalms.ui.f18b369c4c50e77a", "Top centre"),
            top_right: uiText("vidyalms.ui.d83cbef9db1bcfc0", "Top right"),
            centre: uiText("vidyalms.ui.45c19322f7b9337e", "Centre"),
            bottom_left: uiText("vidyalms.ui.f5a57b969150df60", "Bottom left"),
            bottom_centre: uiText("vidyalms.ui.cc7ecb87f63a8154", "Bottom centre"),
            bottom_right: uiText("vidyalms.ui.b4b7cb63bc9bae12", "Bottom right"),
        } as Record<string, string>,
        alignments: {
            left: uiText("vidyalms.ui.58eb9032e3bb83f0", "Left"),
            centre: uiText("vidyalms.ui.45c19322f7b9337e", "Centre"),
            right: uiText("vidyalms.ui.883361d5d682a157", "Right"),
            justify: uiText("vidyalms.ui.7211e50abce8d8a4", "Justified"),
        } as Record<string, string>,
        save: uiText("vidyalms.ui.9ed31a8a461285d2", "Save element"),
        saved: uiText("vidyalms.ui.4913e545680e2d67", "Element saved."),
        settings: uiText("vidyalms.ui.74a883a037bc227f", "Settings"),
        capabilities: uiText("vidyalms.ui.9460f16ac9b5171e", "Capabilities"),
        contributes: (target: string) => uiText("vidyalms.ui.761999f8c5a8881e", "Feeds {p0} in the credential.", { p0: target }),
        packageLabel: (name: string) => uiText("vidyalms.ui.a8f4bf044ac3c7e4", "From {p0}", { p0: name }),
        editorTab: uiText("vidyalms.ui.2e0e960ab3204127", "Form"),
        designerTab: uiText("vidyalms.ui.8b6d8e40753ad9f2", "Designer"),
        designerMissing:
            uiText("vidyalms.ui.6695cf7ae8724ab4", "The drag-and-drop designer is registered by a package. Without it every element is still addable, ")
            + uiText("vidyalms.ui.fea6806f21a76954", "positionable, editable and removable through the form below."),
    },

    tokens: {
        heading: uiText("vidyalms.ui.89cd50263d87a700", "Placeholders"),
        hint:
            uiText("vidyalms.ui.441c457aaa95d537", "The same vocabulary the notification templates use. An unresolved placeholder is left intact in the ")
            + uiText("vidyalms.ui.59e1a1ab0b6e1188", "output, so a template written for a richer renderer degrades visibly rather than losing a line."),
        unavailable: uiText("vidyalms.ui.e9363f76e1145505", "not installed"),
    },

    findings: {
        heading: uiText("vidyalms.ui.7b22b5d284b0e028", "Publication checks"),
        none: uiText("vidyalms.ui.8f01380299bc5444", "Nothing to fix."),
        severity: { error: uiText("vidyalms.ui.54a0e8c17ebb21a1", "Error"), warning: uiText("vidyalms.ui.e981ddae45d8f4ca", "Warning"), info: uiText("vidyalms.ui.d8da2c49df39d91d", "Note") } as Record<string, string>,
    },

    preview: {
        heading: uiText("vidyalms.ui.324b134f57c70c72", "Preview"),
        run: uiText("vidyalms.ui.324b134f57c70c72", "Preview"),
        running: uiText("vidyalms.ui.7bcbd9f8989cb958", "Rendering..."),
        note: uiText("vidyalms.ui.dc2bbcafb1d445a9", "Nothing is written. A certificate already issued keeps rendering from its own snapshot."),
        empty: uiText("vidyalms.ui.80036ac9b623b4be", "Run a preview to see what this template renders."),
        frame: uiText("vidyalms.ui.739a2ddaca4612fa", "Certificate preview"),
        binary: (mediaType: string) => uiText("vidyalms.ui.87eefc5e2e1be05c", "The renderer produced {p0}, which cannot be shown inline.", { p0: mediaType }),
        download: uiText("vidyalms.ui.a8cb0b9e8adf00f2", "Open the rendered artefact"),
        unresolved: (n: number) =>
            uiText("vidyalms.ui.7246c36585451b4b", "{p0} {p1} left intact because no resolver owns ", { p0: n, p1: plural(n, 'placeholder was', 'placeholders were') })
            + uiText("vidyalms.ui.90912feb913f8e4e", "{p0}.", { p0: plural(n, 'it', 'them') }),
    },

    bulkReissue: {
        heading: uiText("vidyalms.ui.5063005f769d174f", "Bulk reissue"),
        note:
            uiText("vidyalms.ui.2d96eca5fe36ba73", "Reissues every active certificate of this template below the target revision, one job per certificate, ")
            + uiText("vidyalms.ui.0fb959e468b92cf2", "so a restart re-drives only what it has not done."),
        start: uiText("vidyalms.ui.286d2b966dcba52d", "Reissue every certificate"),
        starting: uiText("vidyalms.ui.d6e3ff1af9acecf6", "Queueing..."),
        cancel: uiText("vidyalms.ui.b1e562b3cc7642b6", "Cancel the run"),
        cancelling: uiText("vidyalms.ui.7b26131098bb18b2", "Cancelling..."),
        renewal: uiText("vidyalms.ui.ba6ee5ee9afdeb20", "Recompute expiry (a renewal rather than a correction)"),
        notify: uiText("vidyalms.ui.59de5e976ec0041e", "Send one notification per reissued certificate"),
        unavailable:
            uiText("vidyalms.ui.1a0f5caa38fcc4fe", "No package on this build registers bulk reissue, so nothing was queued. One certificate at a time ")
            + uiText("vidyalms.ui.a49703456b91d23b", "still works from the certificates list."),
        queued: (n: number) => uiText("vidyalms.ui.0920881841f85df4", "{p0} {p1} selected.", { p0: n, p1: plural(n, 'certificate', 'certificates') }),
        progress: (done: number, total: number) => uiText("vidyalms.ui.ae6ce7471adf6bdc", "{p0} of {p1} done", { p0: done, p1: total }),
        dedupe: (key: string) => uiText("vidyalms.ui.855090484e799bfc", "Fan-out key {p0}", { p0: key }),
        statuses: {
            unavailable: uiText("vidyalms.ui.67a926f7008e2b0e", "Not available"),
            queued: uiText("vidyalms.ui.661ff40a07e037bb", "Queued"),
            running: uiText("vidyalms.ui.f4ccae29e1bb0c20", "Running"),
            completed: uiText("vidyalms.ui.22a970d2e5b1cc23", "Completed"),
            cancelled: uiText("vidyalms.ui.d353a99eb4556847", "Cancelled"),
        } as Record<string, string>,
        cancelled: uiText("vidyalms.ui.2670eba5eb27715b", "The run was cancelled."),
    },

    credits: {
        title: uiText("vidyalms.ui.077f1abfb6254e85", "Continuing-education credit"),
        schemes: uiText("vidyalms.ui.2cbd7951580816e6", "Registered schemes"),
        schemesHint:
            uiText("vidyalms.ui.8073126e19632be6", "One scheme is free and computes contact hours. A package adding IACET, NASBA or ACCME adds it here, ")
            + uiText("vidyalms.ui.e2404919914155b0", "and its parameters are its own."),
        schemeColumns: {
            scheme: uiText("vidyalms.ui.dca65687b97af5d9", "Scheme"),
            unit: uiText("vidyalms.ui.4e545960f1bffc13", "Unit"),
            retention: uiText("vidyalms.ui.c808f2aeb00cf8ba", "Records kept"),
            fields: uiText("vidyalms.ui.3a606d8d3960214c", "Asks the learner for"),
        },
        retentionYears: (n: number) => uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: n, p1: plural(n, 'year', 'years') }),
        retentionUnstated: uiText("vidyalms.ui.068109fd3f73fbbb", "Not stated"),
        noLearnerFields: uiText("vidyalms.ui.67e1bca0889ba21c", "Nothing"),
        notInstalled: uiText("vidyalms.ui.e9363f76e1145505", "not installed"),
        courseHeading: uiText("vidyalms.ui.20bd32da0d19105a", "Credit a course carries"),
        courseHint:
            uiText("vidyalms.ui.dea76c61430be89d", "What an author records here becomes credit rows on every certificate issued from the course, frozen ")
            + uiText("vidyalms.ui.9b02f196689cbf6f", "at issue inside the same transaction as the certificate."),
        chooseCourse: uiText("vidyalms.ui.2096cefba06a32ba", "Course"),
        chooseCoursePlaceholder: uiText("vidyalms.ui.8a1518e9c1ab4748", "Choose a course"),
        noCourse: uiText("vidyalms.ui.7440d117b930167e", "Choose a course to see and edit the credit it carries."),
        addScheme: uiText("vidyalms.ui.39b89f25982d5ee4", "Add credit"),
        removeScheme: uiText("vidyalms.ui.c3812fc4acb861d5", "Remove"),
        removeSchemeNamed: (scheme: string) => uiText("vidyalms.ui.688c5922585ed5a2", "Remove {p0} credit", { p0: scheme }),
        derived: uiText("vidyalms.ui.7a15102667bdcea4", "Computed"),
        derivedNone: uiText("vidyalms.ui.c6dd4c358cfa8e9c", "Nothing can compute this: the scheme that owns it is not installed."),
        amount: (amount: string, unit: string) => uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: amount, p1: unit }),
        contactMinutes: (minutes: number) => uiText("vidyalms.ui.5ae717e320f354de", "{p0} contact minutes", { p0: minutes }),
        save: uiText("vidyalms.ui.8ca57d25e73d8f3e", "Save credit"),
        saving: uiText("vidyalms.ui.dc85af8f2b1d0d67", "Saving..."),
        saved: uiText("vidyalms.ui.36a6015b903e88de", "Credit saved."),
        empty: uiText("vidyalms.ui.99e0c8d5fd60caf9", "This course carries no credit."),
        emptyAll: uiText("vidyalms.ui.d78a038c9c34af17", "No credit scheme is registered on this build."),
    },

    badges: {
        title: uiText("vidyalms.ui.185d8ef0ae5ea9b8", "Badges"),
        table: uiText("vidyalms.ui.185d8ef0ae5ea9b8", "Badges"),
        count: (n: number) => uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: n, p1: plural(n, 'badge', 'badges') }),
        unavailable:
            uiText("vidyalms.ui.b589a08d26136513", "No package on this build defines badges. Nothing is gated: the list is empty because there is ")
            + uiText("vidyalms.ui.de436ac3bf308e22", "nothing to list."),
        empty: uiText("vidyalms.ui.b4d788544e30d952", "No badges match this filter."),
        create: uiText("vidyalms.ui.1bea10f4693a7cec", "New badge"),
        createTitle: uiText("vidyalms.ui.1bea10f4693a7cec", "New badge"),
        columns: {
            name: uiText("vidyalms.ui.dcd1d5223f73b3a9", "Name"),
            type: uiText("vidyalms.ui.baaddf70fb5d432b", "Type"),
            state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
            awards: uiText("vidyalms.ui.2a70b0b82afb1334", "Awarded"),
        },
        states: { draft: uiText("vidyalms.ui.ebf12ef47cf575b3", "Draft"), published: uiText("vidyalms.ui.2ef42ec21d9300dd", "Published"), archived: uiText("vidyalms.ui.bdb86505f8062d15", "Archived") } as Record<string, string>,
        chips: { all: uiText("vidyalms.ui.a52ace420f2175d0", "All"), draft: uiText("vidyalms.ui.ebf12ef47cf575b3", "Draft"), published: uiText("vidyalms.ui.2ef42ec21d9300dd", "Published"), archived: uiText("vidyalms.ui.bdb86505f8062d15", "Archived") },
        lockedBadge: uiText("vidyalms.ui.82b60eb4d15720db", "Criteria locked"),
        lockedHint:
            uiText("vidyalms.ui.367e9adc26832690", "This badge has been awarded, so its criteria and expiry are frozen. Changing them makes a new ")
            + uiText("vidyalms.ui.04577f78e0b86f8c", "version rather than rewriting what holders met."),
        fields: {
            key: uiText("vidyalms.ui.2ea14ba41451b58a", "Badge key"),
            name: uiText("vidyalms.ui.dcd1d5223f73b3a9", "Name"),
            description: uiText("vidyalms.ui.526e0087cc3f254d", "Description"),
            narrative: uiText("vidyalms.ui.90fc812e994f81f1", "Criteria, in a sentence"),
            narrativeHint: uiText("vidyalms.ui.60eb2d235e56ff72", "What a holder reads. The machine criteria are the availability condition tree."),
            achievementType: uiText("vidyalms.ui.c97a4b1b091b28b7", "Achievement type"),
            course: uiText("vidyalms.ui.2096cefba06a32ba", "Course"),
            state: uiText("vidyalms.ui.a3b50c476732c740", "State"),
        },
        save: uiText("vidyalms.ui.9f0d893aa2889107", "Save badge"),
        saving: uiText("vidyalms.ui.dc85af8f2b1d0d67", "Saving..."),
        saved: uiText("vidyalms.ui.740eb8c706b74f55", "Badge saved."),
        edit: uiText("vidyalms.ui.464c4ffd019e1e96", "Edit"),
        editNamed: (name: string) => uiText("vidyalms.ui.2526cc99c2e14efb", "Edit the badge {p0}", { p0: name }),
        awards: uiText("vidyalms.ui.669a46138d69d7c1", "Awards"),
        awardsNamed: (name: string) => uiText("vidyalms.ui.b8c74fa268f8c04b", "Show the awards of {p0}", { p0: name }),
        awardsOf: (name: string) => uiText("vidyalms.ui.91b6ada297751fbd", "Awards of {p0}", { p0: name }),
        awardColumns: {
            code: uiText("vidyalms.ui.340f463033e0fd5d", "Code"),
            learner: uiText("vidyalms.ui.0815286c81c0a2b1", "Learner"),
            status: uiText("vidyalms.ui.920e413c7d411b61", "Status"),
            issued: uiText("vidyalms.ui.0221e48751889912", "Issued"),
        },
        awardsEmpty: uiText("vidyalms.ui.85547bc2ba39d9c3", "Nobody holds this badge yet."),
        award: uiText("vidyalms.ui.d36b83a8fcf0688b", "Award to a learner"),
        awardTitle: uiText("vidyalms.ui.02bbec3eb95cca64", "Award this badge"),
        awardLearner: uiText("vidyalms.ui.0815286c81c0a2b1", "Learner"),
        awardNote: uiText("vidyalms.ui.d8da2c49df39d91d", "Note"),
        awardSubmit: uiText("vidyalms.ui.eb7e3fc8b709d5d4", "Award badge"),
        awardSubmitting: uiText("vidyalms.ui.b1c2c85a9dbeb0d1", "Awarding..."),
        awarded: uiText("vidyalms.ui.215a7624083b3ba8", "The badge was awarded."),
        revokeAward: uiText("vidyalms.ui.87e6d00bbf53ec5a", "Revoke"),
        revokeAwardNamed: (code: string) => uiText("vidyalms.ui.01b4bace50727ef5", "Revoke award {p0}", { p0: code }),
        revokeTitle: uiText("vidyalms.ui.7656ffb74d9fadf1", "Revoke this award?"),
        revokeBody: (code: string) =>
            uiText("vidyalms.ui.adf35266ec23604f", "{p0} stays on record and its verification page keeps answering, with the reason \"revoked\".", { p0: code }),
        revokeCancel: uiText("vidyalms.ui.fdce5da2cec2ff02", "Keep it"),
        revokeSubmit: uiText("vidyalms.ui.25eb985be376ae80", "Revoke award"),
        revokeSubmitting: uiText("vidyalms.ui.cab1ba57601105bd", "Revoking..."),
        revoked: (code: string) => uiText("vidyalms.ui.c699c00e555e47c0", "Award {p0} is revoked.", { p0: code }),
        archive: uiText("vidyalms.ui.66f4804ee23ddc09", "Archive"),
        archiveNamed: (name: string) => uiText("vidyalms.ui.53f7e3a0dc79186e", "Archive the badge {p0}", { p0: name }),
    },

    form: {
        required: uiText("vidyalms.ui.59212ef4264b3751", "This is needed."),
        wholeNumber: uiText("vidyalms.ui.29d032fb7f5207c8", "A whole number."),
        number: uiText("vidyalms.ui.8ffc64ccc58bef33", "A number."),
        atLeast: (n: number) => uiText("vidyalms.ui.20f9c17bc2d8e9fd", "{p0} or more.", { p0: n }),
        atMost: (n: number) => uiText("vidyalms.ui.abbdf7fc3b185e2a", "{p0} or fewer.", { p0: n }),
        between: (min: number, max: number) => uiText("vidyalms.ui.f88d86f222ee961a", "Between {p0} and {p1}.", { p0: min, p1: max }),
        cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
        close: uiText("vidyalms.ui.7d9eb7acb13e2462", "Close"),
        yes: uiText("vidyalms.ui.85a39ab345d672ff", "Yes"),
        no: uiText("vidyalms.ui.1ea442a134b2a184", "No"),
        none: uiText("vidyalms.ui.dc937b59892604f5", "None"),
        unset: uiText("vidyalms.ui.4895f73177ab5d67", "Not set"),
    },
} as const;
