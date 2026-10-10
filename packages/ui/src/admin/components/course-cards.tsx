import { translate as uiText } from '@/shared/i18n';
import { ArrowUpRight, Users } from 'lucide-react';
import type { ManagedCourse } from '@vidyalms/api-sdk';
import { Badge, Icon, ProgressBar, buttonVariants } from '@/shared/components';
import { useAdminConfig, workbenchUrl } from '../config';
import { adminStrings } from '../strings';
import { instructorNames, roundPercent } from '../lib';
import { ResourceLifecycleActions } from './resource-lifecycle-actions';
import { CourseArtwork } from './course-artwork';

export function CourseCards({ courses, onChanged }: { courses: ManagedCourse[]; onChanged: (message: string) => void }) {
    const config = useAdminConfig();
    const strings = adminStrings.courses;
    return (
        <ul className="vy-course-cards" aria-label={strings.title}>
            {courses.map((course) => (
                <li key={course.id} className="vy-course-card">
                    <div className="vy-course-cover">
                        <CourseArtwork course={course} />
                        <div className="vy-course-cover-status"><Badge variant={course.state === 'published' ? 'ok' : 'neutral'} glyph={course.state === 'published' ? undefined : null}>{strings.states[course.state]}</Badge></div>
                    </div>
                    <div className="vy-course-card-body">
                        <h3><a href={course.state === 'trashed' ? undefined : workbenchUrl(config, course.id)}>{course.title}</a></h3>
                        <p className="text-xs text-fg-2">{strings.meta(instructorNames(course.instructors), course.lesson_count)}</p>
                        <p className="line-clamp-2 text-sm text-fg-2">{course.summary}</p>
                        <div className="mt-auto grid gap-3 pt-3">
                            {course.completion_rate !== null && <div className="vy-course-card-completion">
                                <span>{strings.columns.completionRate}</span><strong>{roundPercent(course.completion_rate) ?? 0}%</strong>
                                <ProgressBar value={roundPercent(course.completion_rate) ?? 0} label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: strings.columns.completionRate, p1: course.title })} />
                            </div>}
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <span className="flex items-center gap-2 text-xs text-fg-2"><Icon icon={Users} size={15} />{strings.columns.enrolled}: {course.enrolled_count.toLocaleString(config.locale)}</span>
                                <div className="flex items-center gap-1">
                                {course.state !== 'trashed' && <a className={buttonVariants({ variant: 'ghost', size: 'sm' })} href={workbenchUrl(config, course.id)} aria-label={strings.openNamed(course.title)} title={strings.openNamed(course.title)}><Icon icon={ArrowUpRight} size={18} /></a>}
                                <ResourceLifecycleActions kind="course" id={course.id} name={course.title} state={course.state} onChanged={onChanged} />
                                </div>
                            </div>
                        </div>
                    </div>
                </li>
            ))}
        </ul>
    );
}
