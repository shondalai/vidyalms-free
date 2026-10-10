import './styles.css';
import { installQuestionTypeBridge, registerFreeQuestionTypes } from '@/question-types';
import { installRunnerPanelBridge } from '@/runner-panels';
import { mountConfigSchema } from '@/shared/config';
import { MountConfigContext } from '@/shared/context';
import { mountApp } from '@/shared/mount';
import { QuizRunnerApp } from './app';

/*
 * The quiz runner entry. The adapter renders
 * <div id="vidyalms-quiz-runner" data-vy-config='{json}'></div> and loads
 * this bundle; everything else flows from the mount contract.
 *
 * The one documented exception is window.vidyalms.questionTypes, created
 * here at module scope before anything mounts, so a third-party bundle that
 * declares this one as a dependency can register a renderer at its own
 * module scope and be certain the registry exists. The six free types are
 * the registry's first six consumers and register the same way.
 *
 * window.vidyalms.runnerPanels is the same carve-out for the two places the
 * runner offers a panel it does not draw: the confidence scale and the per
 * question feedback panel, which belong to the attempt behaviour rather than
 * to any question type. The free build registers neither, which is what
 * makes this bundle the same bytes with or without Pro.
 */
installQuestionTypeBridge();
installRunnerPanelBridge();
registerFreeQuestionTypes();

mountApp({
    learnerNavigation: true,
    mountId: 'vidyalms-quiz-runner',
    schema: mountConfigSchema,
    context: MountConfigContext,
    render: () => <QuizRunnerApp />,
});
