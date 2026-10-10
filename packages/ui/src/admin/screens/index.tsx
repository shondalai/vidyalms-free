import { translate as uiText } from '@/shared/i18n';
import { adminStrings } from '../strings';
import { CertificatesScreen } from './certificates';
import { CoursesScreen } from './courses';
import { CategoriesScreen } from './categories';
import { DashboardScreen } from './dashboard';
import { EnrolmentsScreen } from './enrolments';
import { renderEmailTemplatesAdminScreen } from '../email-templates';
import { emailTemplateStrings } from '../email-templates/strings';
import { renderAutomationsAdminScreen } from '../automations';
import { automationStrings } from '../automations/strings';
import { renderAiAdminScreen } from '../ai';
import { aiStrings } from '../ai/strings';
import { LearnersScreen } from './learners';
import { NotificationsScreen } from './notifications';
import { notificationAdminStrings } from '../notification-strings';
import { adminScreen, registerAdminScreen } from './registry';
import { SettingsScreen } from './settings';
import { lazy, Suspense } from 'react';
import { Skeleton } from '@/shared/components';
import { organisationStrings } from '../organisations/strings';
import { operationsStrings } from '../operations/strings';
import { useAdminConfig } from '../config';

function RegisteredCertificatesScreen() {
    const config = useAdminConfig();
    return <CertificatesScreen enableTemplateStudio enableBadges={config.routes?.includes('/manage/badges') ?? false} />;
}

const OrganisationsScreen = lazy(() => import('../organisations/screen').then((module) => ({ default: module.OrganisationsScreen })));
const OperationsScreen = lazy(() => import('../operations/screens').then((module) => ({ default: module.OperationsScreen })));
const ReportsScreen = lazy(() => import('../reports/screen').then((module) => ({ default: module.ReportsScreen })));
const ImportsScreen = lazy(() => import('../operations/screens').then((module) => ({ default: module.ImportsScreen })));

/*
 * The screens the free build ships, registered through the same
 * function a package would use. Importing this module is what installs them,
 * which is why the entry point and the tests both do it.
 */

export function registerFreeAdminScreens(): void {
    const register = (definition: Parameters<typeof registerAdminScreen>[0]) => {
        if (adminScreen(definition.key) === undefined) {
            registerAdminScreen(definition);
        }
    };

    register({
        key: 'dashboard',
        label: adminStrings.app.screens.dashboard,
        render: () => <DashboardScreen />,
    });
    register({ key: 'courses', label: adminStrings.app.screens.courses, render: () => <CoursesScreen /> });
    register({ key: 'categories', label: uiText("vidyalms.ui.b8b1d894c6837252", "Categories"), render: () => <CategoriesScreen /> });
    register({
        key: 'enrolments',
        label: adminStrings.app.screens.enrolments,
        render: () => <EnrolmentsScreen />,
    });
    register({
        key: 'learners',
        label: adminStrings.app.screens.learners,
        render: () => <LearnersScreen />,
    });
    register({
        key: 'certificates',
        label: adminStrings.app.screens.certificates,
        render: () => <RegisteredCertificatesScreen />,
    });
    register({
        key: 'notifications',
        label: notificationAdminStrings.screen,
        render: () => <NotificationsScreen />,
    });
    register({
        key: 'email-templates',
        label: emailTemplateStrings.screen,
        render: renderEmailTemplatesAdminScreen,
    });
    /*
     * The automation rule builder. It takes the "automations" host key, which
     * the notification destination never claimed in code: both adapters
     * register "notifications" for that one, and two screens on one key would
     * be one screen nobody can reach.
     */
    register({
        key: 'automations',
        label: automationStrings.screen,
        render: renderAutomationsAdminScreen,
    });
    /*
     * The AI screen, on its own host key "ai". It is free on every build:
     * the four enablement levels, the stop above them, the data-use policy
     * and its acceptance count, the run log with its refusals and the
     * redaction manifest of a dry run, the review queue and the usage
     * report all answer on a site that has bought nothing.
     */
    register({
        key: 'ai',
        label: aiStrings.screen,
        render: renderAiAdminScreen,
    });
    for (const [key, label, Screen] of [
        ['organisations', organisationStrings.title, OrganisationsScreen],
        ['reports', operationsStrings.reports, ReportsScreen],
        ['import', operationsStrings.imports, ImportsScreen],
        ['operations', operationsStrings.title, OperationsScreen],
    ] as const) register({ key, label, render: () => <Suspense fallback={<Skeleton className="h-64 w-full" />}><Screen /></Suspense> });
    register({
        key: 'settings',
        label: adminStrings.app.screens.settings,
        render: () => <SettingsScreen />,
    });
}
