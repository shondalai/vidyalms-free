import './styles.css';
import { mountApp } from '@/shared/mount';
import { NotificationsApp } from './app';
import { NotificationConfigContext, notificationConfigSchema } from './config';

/*
 * The notification site pages entry. The adapter renders
 * <div id="vidyalms-notifications" data-vy-config='{json}'></div> on its own
 * preference-centre page and on its unsubscribe page; the config's "screen"
 * says which one and, on the second, carries the signed token from the link.
 *
 * It is a separate entry from the player because the unsubscribe page is
 * reached from an inbox with no session at all, and an app that assumed a
 * learner would make the one page a person reaches after leaving depend on
 * being signed in.
 */
mountApp({
    learnerNavigation: true,
    mountId: 'vidyalms-notifications',
    schema: notificationConfigSchema,
    context: NotificationConfigContext,
    render: () => <NotificationsApp />,
});
