import { useMemo } from 'react';
import type { LessonView } from '@/shared/api';
import { sanitizeHtml } from '../../sanitize';
import { playerStrings } from '../../strings';
import { presentationStrings as copy } from '../../presentation-strings';
import { AssignmentWork } from './assignment-work';

export interface AssignmentPaneProps {
    lesson: LessonView;
    /** No enrolment: the brief is the preview, and nothing can be submitted. */
    previewing?: boolean;
}

export function AssignmentPane({ lesson, previewing = false }: AssignmentPaneProps) {
    const instructions = typeof lesson.content?.instructions === 'string' ? lesson.content.instructions : '';
    const html = useMemo(() => sanitizeHtml(instructions), [instructions]);
    return <div className="vy-assignment-panel grid gap-4">
        <div className="vy-activity-heading"><span className="vy-study-eyebrow">{copy.assignment}</span><h4>{copy.assignmentTitle}</h4></div>
        {html && <div className="vy-prose max-w-[64ch]" dangerouslySetInnerHTML={{ __html: html }} />}
        {previewing
            /* The submission workspace reads the visitor's own submissions,
               which a guest has no route to and nobody without an enrolment
               may add to. */
            ? <p className="rounded border border-border bg-bg px-3.5 py-2.5 text-[12.5px] text-fg-2">{playerStrings.preview.assignment}</p>
            : <AssignmentWork key={lesson.id} lessonId={lesson.id} />}
    </div>;
}
