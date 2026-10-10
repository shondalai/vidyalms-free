import './styles.css';
import { installQuestionTypeBridge, registerFreeQuestionTypes } from '@/question-types';
import { mountApp } from '@/shared/mount';
import { instructorConfigSchema, InstructorConfigContext } from './config';
import { InstructorApp } from './app';

/*
 * The instructor workbench entry. The adapter renders
 * <div id="vidyalms-instructor" data-vy-config='{json}'></div> inside its
 * administrator and loads this bundle; the app validates its own mount
 * contract (src/instructor/config.ts) and reads nothing else.
 *
 * The one documented exception is window.vidyalms.questionTypes, created
 * here at module scope before anything mounts, so a third-party bundle that
 * declares this one as a dependency can register a renderer at its own
 * module scope and be certain the registry exists.
 */
installQuestionTypeBridge();
registerFreeQuestionTypes();

mountApp({
    mountId: 'vidyalms-instructor',
    schema: instructorConfigSchema,
    context: InstructorConfigContext,
    render: () => <InstructorApp />,
});
