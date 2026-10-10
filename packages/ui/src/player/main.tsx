import './styles.css';
import { mountConfigSchema } from '@/shared/config';
import { MountConfigContext } from '@/shared/context';
import { mountApp } from '@/shared/mount';
import { PlayerApp } from './app';

/*
 * The learner player entry. The mount contract, providers and error
 * boundary come from the shared mountApp; everything below it is the
 * player app built from the 02 Learner Player artboard (direction 1a).
 */
mountApp({
    learnerNavigation: true,
    mountId: 'vidyalms-player',
    schema: mountConfigSchema,
    context: MountConfigContext,
    render: () => <PlayerApp />,
});
