import type { ManagedCourseDetail } from '@vidyalms/api-sdk';
import { Check, Circle } from 'lucide-react';
import { ErrorState, Icon } from '@/shared/components';
import { useAuthoringCurriculum } from '../api';
import { studioStrings as s } from '../studio-strings';

export function PublishingReview({ course }: { course: ManagedCourseDetail }) {
    const curriculum = useAuthoringCurriculum(course.id);
    const checks = [
        { label: s.checks.title, ok: course.title.trim() !== '', required: true },
        {
            label: s.checks.lesson,
            ok: curriculum.data?.modules.some(
                (module) =>
                    module.state === 'published' &&
                    module.lessons.some((lesson) => lesson.state === 'published'),
            ),
            required: true,
        },
        { label: s.checks.summary, ok: Boolean(course.summary?.trim()), required: false },
        { label: s.checks.cover, ok: Boolean(course.image), required: false },
        { label: s.checks.instructor, ok: course.instructors.length > 0, required: false },
    ];
    return (
        <section className="vy-studio-readiness">
            <h3>{s.readiness}</h3>
            <p>{s.readinessIntro}</p>
            {curriculum.isError && (
                <ErrorState error={curriculum.error} onRetry={() => void curriculum.refetch()} />
            )}
            <ul>
                {checks.map((check) => (
                    <li key={check.label}>
                        <Icon icon={check.ok ? Check : Circle} size={18} />
                        <span>
                            {check.label}
                            <small>{check.required ? s.required : s.recommended}</small>
                        </span>
                        <span className={check.ok ? 'text-ok-strong' : 'text-fg-2'}>
                            {check.ok === undefined ? '…' : check.ok ? s.complete : s.needsAttention}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
