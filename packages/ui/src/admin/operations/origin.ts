import type { OperationTask } from '@vidyalms/api-sdk';
import type { AdminConfig, AdminScreen } from '../config';
import { operationsStrings as s } from './strings';

interface Destination { screen: AdminScreen; hash: string; label: string }

/** Only registered Core task contracts can select a workflow; queue text is never a URL. */
function destination(task: Pick<OperationTask, 'type' | 'group_key'>): Destination | null {
    switch (task.type) {
        case 'vidyalms.certificate.reissue':
        case 'vidyalms.certificate.reissue_one': {
            // ReissueCertificatesForTemplate::dedupeKeyFor sets the same group on fanout and child tasks.
            const match = /^reissue:template:([1-9][0-9]*):([1-9][0-9]*)$/.exec(task.group_key ?? '');
            const templateId = Number(match?.[1]);
            const revision = Number(match?.[2]);
            return match && Number.isSafeInteger(templateId) && Number.isSafeInteger(revision)
                ? { screen: 'certificates', hash: `/templates/${templateId}/edit`, label: s.openTemplate }
                : { screen: 'certificates', hash: '/templates', label: s.openTemplates };
        }
        case 'vidyalms.certificate.render':
        case 'vidyalms.certificate.backfill':
            return { screen: 'certificates', hash: '/', label: s.openCertificates };
        case 'vidyalms.import.run':
            // Scheduler IDs and import IDs are independent; the public task does not expose job_id.
            return { screen: 'import', hash: '/', label: s.openImports };
        case 'vidyalms.organisation.terminate_access':
            // This periodic task drains more than one organization's durable cleanup queue.
            return { screen: 'organisations', hash: '/', label: s.openOrganisations };
        case 'vidyalms.notification.fanout':
        case 'vidyalms.notification.send':
        case 'vidyalms.notification.sweep':
            return { screen: 'notifications', hash: '/', label: s.openNotifications };
        case 'vidyalms.report.generate':
            return { screen: 'reports', hash: '/', label: s.openReports };
        case 'vidyalms.entitlements.expire':
        case 'vidyalms.progress.recompute':
            return { screen: 'enrolments', hash: '/', label: s.openEnrolments };
        default:
            return null;
    }
}

/** Preserve host routing/query parameters, but only link to a same-origin advertised screen. */
export function operationOrigin(
    task: Pick<OperationTask, 'type' | 'group_key'>,
    screens: AdminConfig['urls']['screens'],
    baseUrl: string,
): { href: string; label: string } | null {
    const target = destination(task);
    const hostUrl = target ? screens[target.screen] : undefined;
    if (!target || !hostUrl?.trim()) return null;
    try {
        const base = new URL(baseUrl);
        const url = new URL(hostUrl, base);
        if (!['http:', 'https:'].includes(url.protocol) || url.origin !== base.origin || url.username || url.password) return null;
        url.hash = target.hash;
        return { href: url.href, label: target.label };
    } catch {
        return null;
    }
}
