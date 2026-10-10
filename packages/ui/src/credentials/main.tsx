import './styles.css';
import { mountApp } from '@/shared/mount';
import { CredentialsApp } from './app';
import { CredentialConfigContext, credentialConfigSchema } from './config';

/*
 * The credential site pages entry. The adapter renders
 * <div id="vidyalms-credentials" data-vy-config='{json}'></div> on its own
 * credentials page and on its verification page; the config's "screen" says
 * which one and, on the second, carries the code from the address.
 *
 * It is a separate entry from the player because the verification page is
 * reached from a code printed on paper with no session at all, and an app
 * that assumed a learner would make the one page a relying party visits
 * depend on being signed in.
 */
mountApp({
    learnerNavigation: true,
    mountId: 'vidyalms-credentials',
    schema: credentialConfigSchema,
    context: CredentialConfigContext,
    render: () => <CredentialsApp />,
});
