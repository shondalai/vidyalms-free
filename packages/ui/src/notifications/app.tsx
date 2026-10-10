import { useNotificationConfig } from './config';
import { notificationStrings as strings } from './strings';
import { PreferenceCentre } from './preference-centre';
import { UnsubscribePage } from './unsubscribe';

/*
 * The two notification site pages, under the host's own template.
 *
 * One labelled region and no second main: the host page owns the document
 * outline, and these mount inside it exactly as the player does.
 */
export function NotificationsApp() {
    const config = useNotificationConfig();
    return (
        <div role="region" aria-label={strings.region} className="w-full min-w-0 font-body text-fg">
            {config.screen === 'unsubscribe' ? <UnsubscribePage /> : <PreferenceCentre />}
        </div>
    );
}
