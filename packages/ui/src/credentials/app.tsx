import { useCredentialConfig } from './config';
import { MyCredentialsPage } from './my-credentials';
import { VerifyPage } from './verify';
import { credentialPageStrings as strings } from './strings';
import { OrganisationInvitationPage } from './organisations';
import { AiActivityPage } from './ai-activity';
import { aiActivityStrings } from './ai-activity-strings';

/*
 * The two credential site pages, under the host's own template.
 *
 * One labelled region and no second main: the host page owns the document
 * outline, and these mount inside it exactly as the player does.
 */

export function CredentialsApp() {
    const config = useCredentialConfig();
    if (config.screen === 'organisations') return <OrganisationInvitationPage />;
    if (config.screen === 'ai-activity') {
        return (
            <div role="region" aria-label={aiActivityStrings.region} className="w-full min-w-0 font-body text-fg">
                <AiActivityPage />
            </div>
        );
    }
    return (
        <div role="region" aria-label={strings.region} className="w-full min-w-0 font-body text-fg">
            {config.screen === 'verify' ? <VerifyPage /> : <MyCredentialsPage />}
        </div>
    );
}
