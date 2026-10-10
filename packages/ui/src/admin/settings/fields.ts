import { translate as uiText } from '@/shared/i18n';
import { Settings2, BookOpen, Award, Mail, HardDrive, ShieldCheck, Palette, ShoppingBag, Activity } from 'lucide-react';
import type { components } from '@vidyalms/api-sdk';

export type ConfigurationValues = components['schemas']['RuntimeConfigurationValues'];
export type SettingKey = keyof ConfigurationValues;
export interface SettingField {
    key: SettingKey;
    label: string;
    hint: string;
    type?: 'url' | 'number' | 'boolean' | 'textarea' | 'select';
    max?: number;
    min?: number;
    maxLength?: number;
    headerPanel?: 'layout' | 'branding' | 'spacing';
    options?: { value: string; label: string }[];
}
export const settingsGroups: {
    id: string;
    title: string;
    description: string;
    icon: typeof Settings2;
    fields: SettingField[];
}[] = [
    {
        id: 'general',
        title: uiText("vidyalms.ui.c910d474dcd724bf", "General"),
        description: uiText("vidyalms.ui.478c53403aac85fe", "The identity and public home of your learning workspace."),
        icon: Settings2,
        fields: [
            {
                key: 'academy_name',
                label: uiText("vidyalms.ui.08e854af2536e7ff", "Academy name"),
                hint: uiText("vidyalms.ui.3328ee090a0710cc", "Used in learning messages and as the default credential issuer name. Leave empty to use your site name."),
            },
            {
                key: 'site_base_url',
                label: uiText("vidyalms.ui.2493928923cf07da", "Site base URL"),
                type: 'url',
                hint: uiText("vidyalms.ui.21f8957423e4f3bc", "The public root used by background jobs and credential links. Leave empty to use the host’s site configuration."),
            },
        ],
    },
    {
        id: 'learner-experience',
        title: uiText("vidyalms.ui.98ecce0a5de2226b", "Learner experience"),
        description: uiText("vidyalms.ui.ac95dc653d05662c", "Choose how the learning area fits into your Joomla or WordPress site."),
        icon: Palette,
        fields: [
            {
                key: 'learner_navigation', label: uiText("vidyalms.ui.b238eb04151e49f6", "Learner navigation"), type: 'select',
                headerPanel: 'layout',
                options: [{ value: 'top', label: uiText("vidyalms.ui.1187aab287892dff", "Top navigation") }, { value: 'sidebar', label: uiText("vidyalms.ui.cc844d9e1f95482c", "Left sidebar") }],
                hint: uiText("vidyalms.ui.fa0ed58058fd2060", "Applies to every learner. Top navigation sits beside your academy name; a left sidebar provides a workspace layout. Both adapt to smaller screens."),
            },
            {
                key: 'learning_idle_timeout_seconds', label: uiText("vidyalms.ui.d9ca7ec1f0f9652c", "Learning inactivity timeout (seconds)"), type: 'number', min: 30, max: 3600,
                hint: uiText("vidyalms.ui.8a3039c8071ddc76", "Stop counting active learning time after this long without interaction. Defaults to 300 seconds. Hidden tabs and unfocused windows do not count; active media playback keeps the timer running."),
            },
            {
                key: 'learner_header_display', label: uiText("vidyalms.ui.daf03ec57700fd4f", "Header display"), type: 'select', headerPanel: 'layout',
                options: [{ value: 'full', label: uiText("vidyalms.ui.1c57fa97c6a4816c", "Brand and navigation") }, { value: 'navigation', label: uiText("vidyalms.ui.0ec3a14bdca12bf3", "Navigation only") }, { value: 'hidden', label: uiText("vidyalms.ui.da3d6bd32ab9292e", "Hide learner header") }],
                hint: uiText("vidyalms.ui.a48e71cae6159dff", "Use navigation only when your template already shows your brand. Hide the entire header when your site provides its own links to the learning pages. The focused course player keeps its own controls."),
            },
            {
                key: 'learner_header_title', label: uiText("vidyalms.ui.a14bf9149e1edd46", "Header title"), headerPanel: 'branding', maxLength: 120,
                hint: uiText("vidyalms.ui.0b4d7029b133bde1", "Leave empty to use the academy name, or “Learning space” when no academy name is set. This changes only the learner header."),
            },
            {
                key: 'learner_header_tagline', label: uiText("vidyalms.ui.e27a07f2040bf411", "Header tagline"), headerPanel: 'branding', maxLength: 180,
                hint: uiText("vidyalms.ui.c632f0440aa9b373", "A short line beneath the title. Leave empty for the translated “Your learning, your pace.” message."),
            },
            {
                key: 'learner_header_show_tagline', label: uiText("vidyalms.ui.46b44c8042d65b87", "Show tagline"), type: 'boolean', headerPanel: 'branding',
                hint: uiText("vidyalms.ui.d887f6615973b330", "Turn off for a simpler title-only brand."),
            },
            {
                key: 'learner_header_show_logo', label: uiText("vidyalms.ui.6835861a4b47faf1", "Show logo"), type: 'boolean', headerPanel: 'branding',
                hint: uiText("vidyalms.ui.a1016951d0e14c89", "Show your custom logo or the default VidyaLMS mark beside the title."),
            },
            {
                key: 'learner_header_logo_url', label: uiText("vidyalms.ui.8966409b075ff429", "Header logo URL"), type: 'url', headerPanel: 'branding',
                hint: uiText("vidyalms.ui.7180427eb6b61975", "Paste an absolute HTTPS image URL from your media library. Transparent images work best. Leave empty for the default V mark; credential logos are unchanged."),
            },
            {
                key: 'learner_header_logo_size', label: uiText("vidyalms.ui.685eac66ad1d8004", "Logo height (px)"), type: 'number', headerPanel: 'branding', min: 24, max: 80,
                hint: uiText("vidyalms.ui.82a9ffd93f9a2113", "Between 24 and 80 pixels. Your image keeps its proportions, with a maximum width of 180 pixels."),
            },
            {
                key: 'learner_header_density', label: uiText("vidyalms.ui.0b99fe0e08abae51", "Header spacing"), type: 'select', headerPanel: 'spacing',
                options: [{ value: 'comfortable', label: uiText("vidyalms.ui.459a23a5980fd5c6", "Comfortable") }, { value: 'compact', label: uiText("vidyalms.ui.99452646e34b6970", "Compact") }],
                hint: uiText("vidyalms.ui.ee4300911d2e7100", "Compact reduces space above and below the brand while keeping navigation targets easy to select."),
            },
            {
                key: 'learner_header_divider', label: uiText("vidyalms.ui.462c48535e8b7ccc", "Show header divider"), type: 'boolean', headerPanel: 'spacing',
                hint: uiText("vidyalms.ui.7b98ae0ee4be296c", "A subtle line separates the header from the learning content. Turn off to blend into your site template."),
            },
        ],
    },
    {
        id: 'catalogue',
        title: uiText("vidyalms.ui.af8ea4d806250775", "Catalogue & enrolment"),
        description: uiText("vidyalms.ui.22d42a3799ef8520", "Help learners find their next course and get started."),
        icon: BookOpen,
        fields: [
            {
                key: 'catalogue_per_page',
                label: uiText("vidyalms.ui.5987dca43bf7b0c9", "Catalogue page size"),
                type: 'number',
                max: 50,
                hint: uiText("vidyalms.ui.58cccf888c12e61f", "Courses displayed on each public catalogue page. Choose between 1 and 50."),
            },
            {
                key: 'self_enrolment',
                label: uiText("vidyalms.ui.d3de81b6429da33d", "Allow self-enrolment"),
                type: 'boolean',
                hint: uiText("vidyalms.ui.cae2dd294c6f4ab4", "Signed-in learners may join courses that allow it. Each course’s access rules still apply."),
            },
        ],
    },
    {
        id: 'credentials',
        title: uiText("vidyalms.ui.e8162ed2af47d7a5", "Certificates & credentials"),
        description: uiText("vidyalms.ui.0cf075f62bda45e5", "Recognize achievement with a consistent issuer and verifiable credentials."),
        icon: Award,
        fields: [
            {
                key: 'site_logo_url',
                label: uiText("vidyalms.ui.3d23ddfcc1505dda", "Default issuer logo URL"),
                type: 'url',
                hint: uiText("vidyalms.ui.a95b76f1ca936de3", "Used by learning messages and credentials. An issuer-specific logo takes precedence."),
            },
            {
                key: 'verification_base_url',
                label: uiText("vidyalms.ui.b7bc4fe965a045e9", "Certificate verification URL"),
                type: 'url',
                hint: uiText("vidyalms.ui.d6cabe756e0fae4b", "The verification code is appended to this address. Leave empty to use this site’s verification route."),
            },
            {
                key: 'credential_base_url',
                label: uiText("vidyalms.ui.095b5129108af6d4", "Credential base URL"),
                type: 'url',
                hint: uiText("vidyalms.ui.386a163d82cde83f", "The credential UUID is appended to this address. Leave empty to use this site’s API mount."),
            },
            {
                key: 'status_list_base_url',
                label: uiText("vidyalms.ui.65673bf815e804af", "Status list base URL"),
                type: 'url',
                hint: uiText("vidyalms.ui.5e755998201b35da", "The status list UUID is appended here. Leave empty to use this site’s API mount."),
            },
        ],
    },
    {
        id: 'email',
        title: uiText("vidyalms.ui.55e33f82c576ae3c", "Email & notifications"),
        description: uiText("vidyalms.ui.dba8e230e31d098b", "Give every message a reliable path to your learners."),
        icon: Mail,
        fields: [
            {
                key: 'unsubscribe_base_url',
                label: uiText("vidyalms.ui.50f6c5e605f4d270", "Unsubscribe URL"),
                type: 'url',
                hint: uiText("vidyalms.ui.70018f9158bf91a2", "An HTTPS address for one-click unsubscribe links. Leave empty for this site’s unsubscribe page. A signed token is appended."),
            },
            {
                key: 'envelope_domain',
                label: uiText("vidyalms.ui.4b22af8f73d0252f", "Bounce domain"),
                hint: uiText("vidyalms.ui.b7de5b46ad7491ef", "The domain used for tagged return addresses. Leave empty to use the site’s mail-from domain."),
            },
        ],
    },
    {
        id: 'storage',
        title: uiText("vidyalms.ui.b46a096aa0eac51c", "Files & storage"),
        description: uiText("vidyalms.ui.62d09cc11818d350", "Manage upload ceilings and the private files behind your courses."),
        icon: HardDrive,
        fields: [
            {
                key: 'import_max_upload_mb',
                label: uiText("vidyalms.ui.9d7a3e781ea5e9ac", "Maximum import upload (MB)"),
                type: 'number',
                max: 2048,
                hint: uiText("vidyalms.ui.d7f5c6670b04e956", "Largest archive the import screen accepts. PHP and host upload limits still apply; the smaller limit wins."),
            },
            {
                key: 'upload_max_resource_mb',
                label: uiText("vidyalms.ui.ff733e6ece042ef7", "Maximum resource file (MB)"),
                type: 'number',
                max: 2048,
                hint: uiText("vidyalms.ui.b34fc68dd5a147ae", "Largest document or archive for a resource lesson. PHP limits and accepted file types still apply."),
            },
            {
                key: 'upload_max_image_mb',
                label: uiText("vidyalms.ui.06bb626e6d91800f", "Maximum course image (MB)"),
                type: 'number',
                max: 512,
                hint: uiText("vidyalms.ui.c4e43a6866f51fb2", "Raster course images only. SVG is not accepted. PHP upload limits still apply."),
            },
            {
                key: 'import_source_retention_days',
                label: uiText("vidyalms.ui.77d89728da687104", "Import source retention (days)"),
                type: 'number',
                max: 3650,
                hint: uiText("vidyalms.ui.eb4b99874946cd41", "Default retention for completed import sources. Queued or running imports keep their sources. A Joomla cleanup task with its own retention value overrides this default."),
            },
        ],
    },
    {
        id: 'network',
        title: uiText("vidyalms.ui.c03838989c45dffc", "Network & security"),
        description: uiText("vidyalms.ui.3409a21b56ccaabf", "Control the external services your learning platform can reach."),
        icon: ShieldCheck,
        fields: [
            {
                key: 'integrations_http_allowlist',
                label: uiText("vidyalms.ui.5eae1a7143a37444", "Outbound host allowlist"),
                type: 'textarea',
                hint: uiText("vidyalms.ui.outboundHostsHint", "One host per line or comma-separated. Empty allows no outbound hosts. A leading dot includes subdomains: .example.com includes api.example.com. Used by webhooks and other outbound integrations."),
            },
            {
                key: 'integrations_http_timeout',
                label: uiText("vidyalms.ui.7032d34072a45bb2", "Outbound call timeout (seconds)"),
                type: 'number',
                max: 120,
                hint: uiText("vidyalms.ui.3f435557abbda5f6", "Maximum time for an outbound request, from 1 to 120 seconds. Individual callers may use less time."),
            },
        ],
    },
    {
        id: 'commerce',
        title: uiText("vidyalms.ui.commerceIntegrations", "Shop integrations"),
        description: uiText("vidyalms.ui.commerceIntegrationsHint", "EasyCommerce on Joomla and WooCommerce on WordPress handle sales. VidyaLMS grants learning access."),
        icon: ShoppingBag,
        fields: [],
    },
    {
        id: 'operations',
        title: uiText("vidyalms.ui.64bbb1ba33d496ca", "Learning activity"),
        description: uiText("vidyalms.ui.b830bf8600d8fd40", "The runtime cadence and scheduled services supporting your academy."),
        icon: Activity,
        fields: [],
    },
    {
        id: 'appearance',
        title: uiText("vidyalms.ui.3907fa7f80722a6f", "Appearance"),
        description: uiText("vidyalms.ui.d608940996ee6a4a", "A considered workspace, designed for focused work."),
        icon: Palette,
        fields: [],
    },
];

export const settingsStrings = {
    title: uiText("vidyalms.ui.74a883a037bc227f", "Settings"),
    intro: uiText("vidyalms.ui.34063c23e96e58d6", "A workspace that works the way you do."),
    search: uiText("vidyalms.ui.ca4431193303dd52", "Find a setting…"),
    navigation: uiText("vidyalms.ui.e26d51d36781ba17", "Settings sections"),
    save: uiText("vidyalms.ui.dd0ae7a5cbcf2339", "Save changes"),
    discard: uiText("vidyalms.ui.f9bfa3dc7c8fcdea", "Discard changes"),
    saved: uiText("vidyalms.ui.fc2f5a4752973d72", "All changes saved"),
    saving: uiText("vidyalms.ui.395207583eb8f1d1", "Saving changes…"),
    dirty: uiText("vidyalms.ui.a710c2b90913b537", "Unsaved changes"),
    savedNotice: uiText("vidyalms.ui.2a4438d038ecaa6d", "Your settings have been saved."),
    conflict:
        uiText("vidyalms.ui.98be0251236fe485", "Another administrator changed these settings. Your edits are still here. Reload the latest settings before saving again."),
    reload: uiText("vidyalms.ui.43b169a18fb9887b", "Reload latest settings"),
    resetTitle: uiText("vidyalms.ui.3b13192b9d88d1c6", "Discard your changes?"),
    resetBody: uiText("vidyalms.ui.5a93a3776825abfd", "This restores the most recently loaded settings. Your saved configuration will stay intact."),
    cancel: uiText("vidyalms.ui.e76fd2add01005ae", "Keep editing"),
    confirmDiscard: uiText("vidyalms.ui.56a15293fdb77d13", "Discard and reload"),
    noResults: uiText("vidyalms.ui.3e4d15d46dc7aa48", "No settings found"),
    searchHint: uiText("vidyalms.ui.a1d56b016b5fbe47", "Try a name such as upload, certificate, or enrolment."),
    clearSearch: uiText("vidyalms.ui.3b7ea51793e9d906", "Clear search"),
    sourceTitle: uiText("vidyalms.ui.787c0ff21b7794fe", "Workspace configuration"),
    sourceHint: uiText("vidyalms.ui.e6e6ac4de883fbe9", "Saved for your academy and applied to new requests as soon as you save."),
    unavailable: uiText("vidyalms.ui.e3dc3a359103340c", "The settings service is unavailable. Check that the backend and dashboard are on the same version."),
    export: uiText("vidyalms.ui.d61863de9760ec3b", "Export saved configuration"),
    exportHint: uiText("vidyalms.ui.e8f5dea1568adb83", "Exports saved workspace values. Credentials and provider secrets are not included."),
    results: uiText("vidyalms.ui.e978b00de465a271", "Search results"),
    required: uiText("vidyalms.ui.8d487f5315dedf1c", "Enter a valid value before saving."),
};
