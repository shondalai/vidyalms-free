import { useMemo } from 'react';
import { useAuthoringCurriculum } from '../../api';
import { useInstructorConfig } from '../../config';
import { conditionTargets } from '../../lib/condition-targets';
import type { AvailabilitySubjectType } from '../../lib/flow-types';
import { RestrictionBox } from './restriction-box';
import { registerFreeConditionEditors } from './free-editors';

/*
 * The Restrict access box, with its targets resolved from the course.
 *
 * It sits here rather than inside the box so the box stays a pure editor
 * over data it is handed, and so the two dialogs and the settings tab do not
 * each grow a copy of the same two lines. The curriculum read is already in
 * the query cache whenever the workbench has drawn the curriculum tab, which
 * is every path that reaches this component.
 *
 * Registering the free editors here is what makes the seam exercised by
 * every mount rather than by nobody: the registration function is the one a
 * package calls, and it is idempotent.
 */
export function SubjectRestrictions({
    subjectType,
    subjectId,
    courseId,
    className, onDirtyChange,
}: {
    subjectType: AvailabilitySubjectType;
    subjectId: number;
    courseId: number;
    className?: string;
    onDirtyChange?: (dirty: boolean) => void;
}) {
    registerFreeConditionEditors();
    const config = useInstructorConfig();
    const curriculum = useAuthoringCurriculum(courseId);
    const targets = useMemo(() => conditionTargets(curriculum.data), [curriculum.data]);

    return (
        <RestrictionBox
            subjectType={subjectType}
            subjectId={subjectId}
            courseId={courseId}
            targets={targets}
            locale={config.locale}
            className={className}
            onDirtyChange={onDirtyChange}
        />
    );
}
