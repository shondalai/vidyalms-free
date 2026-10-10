import { CourseBuilder } from '@/instructor/course-builder';
import { InstructorConfigContext, type InstructorConfig } from '@/instructor/config';
import { useAdminConfig } from './config';

/** Reuse the actual authoring engine and authenticated query cache inside the admin shell. */
export function CourseWorkspace({ courseId }: { courseId: number }) {
    const config = useAdminConfig();
    const courses = config.urls.screens.courses ?? config.urls.workbench;
    const instructor: InstructorConfig = {
        ...config,
        course: { id: courseId, slug: '' },
        urls: {
            courses,
            workbench: `${courses}#/course/{course_id}`,
            preview: config.urls.preview,
            upload: config.urls.upload,
            emailTemplates: config.urls.screens['email-templates'],
            certificateTemplates: config.urls.screens.certificates,
            notifications: config.urls.screens.notifications,
        },
    };
    return (
        <InstructorConfigContext.Provider value={instructor}>
            <CourseBuilder courseId={courseId} />
        </InstructorConfigContext.Provider>
    );
}
