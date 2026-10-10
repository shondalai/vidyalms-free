import { translate as uiText } from '@/shared/i18n';
export const unitStrings = {
    title: uiText("vidyalms.ui.3f6c8a7a8e2eaaf6", "Organization units"), intro: uiText("vidyalms.ui.0f359443237b0a14", "Give departments and teams a clear place. Membership placement and a manager’s permission scope are separate choices."),
    name: uiText("vidyalms.ui.a1cc787b2fca1b63", "Unit name"), path: uiText("vidyalms.ui.98d70720bc8f4768", "Unit path"), parent: uiText("vidyalms.ui.36b505818b7b7710", "Parent unit"), depth: uiText("vidyalms.ui.1709305c9fa966d1", "Level"), create: uiText("vidyalms.ui.91f08c727be90def", "Create unit"), edit: uiText("vidyalms.ui.864a32732a1b2111", "Edit unit"),
    search: uiText("vidyalms.ui.04968d819a79af6f", "Search unit paths"), empty: uiText("vidyalms.ui.ba048d7cf5c9a586", "No units match this search."), actions: uiText("vidyalms.ui.ff8059dc6752afdd", "Actions"), state: uiText("vidyalms.ui.920e413c7d411b61", "Status"), active: uiText("vidyalms.ui.92340695899bd2d8", "Active"), inactive: uiText("vidyalms.ui.ac7c949f1211b781", "Inactive"),
    save: uiText("vidyalms.ui.1f12ad267497808c", "Save unit"), saving: uiText("vidyalms.ui.23e39291d6135814", "Saving…"), saved: uiText("vidyalms.ui.5051e68a8c56723d", "Organization unit saved."), cancel: uiText("vidyalms.ui.19766ed6ccb2f4a3", "Cancel"),
    editorIntro: uiText("vidyalms.ui.b9760b9cdb060f71", "Create up to six levels. Move leaf units explicitly; a branch with children cannot be renamed, moved or made inactive."),
    lifecycleHint: uiText("vidyalms.ui.6ce65c0b7290ea46", "Move active members before making their unit inactive. Unit changes do not grant course access."),
    scopeHint: uiText("vidyalms.ui.ce9741bf0238cc1f", "You are viewing your assigned unit and its descendants. Organization-wide administrators manage the structure."),
    membership: uiText("vidyalms.ui.91720b600ba73309", "Membership unit"), membershipHint: uiText("vidyalms.ui.19f807b406d84df4", "Organizes this member and determines which unit managers can manage their relationship."),
    roleScope: uiText("vidyalms.ui.9f2dcfe32485aaaa", "Role scope"), roleScopeHint: uiText("vidyalms.ui.293796315e63bf32", "Limit this role to a unit and its descendants, or explicitly grant organization-wide reach."),
    organizationWide: uiText("vidyalms.ui.6536cefd14b1612c", "Entire organization"), noUnit: uiText("vidyalms.ui.b21e4175a11d2b5e", "No unit assigned"), root: uiText("vidyalms.ui.f61dd2542ac99ed5", "Top level"),
    choose: uiText("vidyalms.ui.2b25ad687fb7c518", "Choose unit"), change: uiText("vidyalms.ui.b0d1dc75d960628e", "Change unit"), results: uiText("vidyalms.ui.77452231e4781020", "Matching units"), pages: uiText("vidyalms.ui.1ef9f53e8a289215", "Unit results pages"),
    chooseSearch: uiText("vidyalms.ui.e9b44ddb71484600", "Search by department or path"), loading: uiText("vidyalms.ui.5a9c90b69c397646", "Loading unit…"), unavailable: uiText("vidyalms.ui.a9e74832a7095a29", "Unit unavailable in your current scope."),
    selected: uiText("vidyalms.ui.1c576b74d7358b74", "Selected unit"), closed: uiText("vidyalms.ui.dad5a227ba342fd1", "Close unit search"),
    membersOnly: uiText("vidyalms.ui.2c9891e66324a1f2", "Unit managers can manage members in their subtree. Organization-wide administrators handle invitations and new accounts."),
    deliveryUnavailable: uiText("vidyalms.ui.8b9d67b382d35198", "Secure invitation delivery is unavailable in this server configuration. A site manager can still add an existing account."),
    unitReference: (id: number) => uiText("vidyalms.ui.f5eccbda276c47f2", "Unit #{p0}", { p0: id }), level: (depth: number) => uiText("vidyalms.ui.117f2b59694b3c6e", "Level {p0}", { p0: depth }),
};
