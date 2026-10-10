import { translate as uiText } from '@/shared/i18n';
export const courseCertificateStrings = {
    title: uiText("vidyalms.ui.3d660823d0b81314", "Course certificate"), intro: uiText("vidyalms.ui.cc4dadaf63ab59da", "Recognize achievement with a published design from your certificate library."),
    enabled: uiText("vidyalms.ui.7a47d31db6233513", "Issue a certificate when this course is completed"),
    disabledHint: uiText("vidyalms.ui.9fde4393fa3145bf", "Turning issuance off does not revoke certificates already earned."),
    template: uiText("vidyalms.ui.708b5396f7f88088", "Certificate design"), siteDefault: uiText("vidyalms.ui.9f7d8f5c7ed75ea6", "Use the site default"), choose: uiText("vidyalms.ui.94c53bfcedb7176f", "Choose a published design"),
    selected: uiText("vidyalms.ui.d695d5855bc5f2b6", "Selected design"), search: uiText("vidyalms.ui.1d82d63ae6de6972", "Search published certificate designs"), pages: uiText("vidyalms.ui.e91ed1359d396671", "Certificate design pages"),
    empty: uiText("vidyalms.ui.395757cb43e2ddeb", "No published designs match this search."), unavailable: uiText("vidyalms.ui.ca184496974204a0", "Unavailable"), loading: uiText("vidyalms.ui.d79440432267d1e6", "Loading certificate settings…"),
    defaultBadge: uiText("vidyalms.ui.a7e4bad1c5de459a", "Site default"), expiry: uiText("vidyalms.ui.dc0114befc9aba66", "Expiry policy"), days: uiText("vidyalms.ui.ed6ea3e80e2d8b66", "Days after issue"), years: uiText("vidyalms.ui.f93295d148224f1e", "Whole years"),
    at: uiText("vidyalms.ui.97cba86909ae07c6", "Expires at (UTC)"), save: uiText("vidyalms.ui.376406ff6e923b75", "Save certificate settings"), saving: uiText("vidyalms.ui.fef0c768de51eda4", "Saving certificate settings…"),
    saved: uiText("vidyalms.ui.e3adc9c16f6f3366", "Certificate settings saved."), dirty: uiText("vidyalms.ui.a710c2b90913b537", "Unsaved changes"), reload: uiText("vidyalms.ui.415db71a129627bb", "Reload saved settings"),
    reloadTitle: uiText("vidyalms.ui.7ca905cb331a3d4a", "Discard your certificate setting changes?"), reloadBody: uiText("vidyalms.ui.b0fed81c6e5e767a", "Your changes will be replaced only after the saved settings load successfully."),
    cancel: uiText("vidyalms.ui.e76fd2add01005ae", "Keep editing"), confirmReload: uiText("vidyalms.ui.56a15293fdb77d13", "Discard and reload"), library: uiText("vidyalms.ui.8c3b8a1dd05da006", "Open certificate library"),
    leaveTitle: uiText("vidyalms.ui.9c5cb9a4fc725ec8", "Leave unsaved certificate settings?"), leaveBody: uiText("vidyalms.ui.708265390beed247", "Save your changes before switching tabs, or discard them to continue."),
    leave: uiText("vidyalms.ui.43c9d079f26ed82f", "Discard and continue"),
    resolved: uiText("vidyalms.ui.ec07480788f63a4d", "Effective published design"), missing: uiText("vidyalms.ui.810bdd4f23914222", "No available published design. Choose another design or publish a site default."),
    expiryLabels: {
        inherit_template: uiText("vidyalms.ui.44385816852877b3", "Use the design’s expiry policy"), never: uiText("vidyalms.ui.6a9894204cc74ce5", "Never expires"), fixed: uiText("vidyalms.ui.2b6d018d2e8d6b01", "Fixed date and time"),
        days_after_issue: uiText("vidyalms.ui.ed6ea3e80e2d8b66", "Days after issue"), end_of_calendar_year: uiText("vidyalms.ui.a19c610600aa9318", "End of calendar year"), anniversary: uiText("vidyalms.ui.62660f7331cb38ef", "Anniversary of issue"),
    } as Record<string, string>,
    revision: (revision: number) => uiText("vidyalms.ui.1df95040965b4c86", "Published revision {p0}", { p0: revision }),
    stored: (id: number) => uiText("vidyalms.ui.c37e9c4fd5380959", "Design #{p0}", { p0: id }),
};
