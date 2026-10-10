import './styles.css';
import { installQuestionTypeBridge, registerFreeQuestionTypes } from '@/question-types';
import { mountApp } from '@/shared/mount';
import { AdminApp } from './app';
import { AdminConfigContext, adminConfigSchema } from './config';
import { installAdminScreenBridge } from './screens/registry';

/*
 * The admin entry. The adapter renders
 * <div id="vidyalms-admin" data-vy-config='{json}'></div> on each of its
 * administrator menu entries; the config's "screen" says which one, and
 * everything else flows from the mount contract.
 */
installAdminScreenBridge();
installQuestionTypeBridge();
registerFreeQuestionTypes();

mountApp({
    mountId: 'vidyalms-admin',
    schema: adminConfigSchema,
    context: AdminConfigContext,
    render: () => <AdminApp />,
});
