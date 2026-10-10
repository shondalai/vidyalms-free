import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import { Button, Pagination, Skeleton } from '@/shared/components';
import { useManagedCourses } from '@/shared/manage';
import { FilterSearch } from '../components/filter-toolbar';
import { ScreenError } from '../components/states';
import { useDebounced } from '../hooks';
import { learnerWorkspaceStrings as s } from './strings';

export interface CourseSelection {
    id: number;
    title: string;
}
export function EnrolmentCoursePicker({
    value,
    onChange,
    error,
    searchLabel = s.courseSearch,
}: {
    value: CourseSelection | null;
    onChange: (course: CourseSelection | null) => void;
    error?: string;
    searchLabel?: string;
}) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const query = useDebounced(search).trim();
    const courses = useManagedCourses(
        { q: query || undefined, page, per_page: 20, sort: 'title', order: 'asc' },
        { enabled: value === null },
    );
    return (
        <fieldset className="vy-learner-course-picker">
            <legend>{s.course}</legend>
            {value ? (
                <div className="vy-learner-selected-course">
                    <strong>{value.title}</strong>
                    <Button variant="ghost" size="sm" onClick={() => onChange(null)}>
                        {s.changeCourse}
                    </Button>
                </div>
            ) : (
                <>
                    <FilterSearch
                        label={searchLabel}
                        placeholder={searchLabel}
                        value={search}
                        onValueChange={(text) => {
                            setSearch(text);
                            setPage(1);
                        }}
                    />
                    {courses.isError ? (
                        <ScreenError error={courses.error} onRetry={() => void courses.refetch()} />
                    ) : courses.isPending ? (
                        <Skeleton className="h-28 w-full" />
                    ) : (
                        <ul aria-label={s.courseResults}>
                            {courses.data.items.map((course) => (
                                <li key={course.id}>
                                    <Button
                                        variant="ghost"
                                        disabled={
                                            courses.isFetching ||
                                            courses.isPlaceholderData ||
                                            query !== search.trim()
                                        }
                                        onClick={() => onChange({ id: course.id, title: course.title })}
                                    >
                                        {course.title}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                    {courses.isSuccess && !courses.data.items.length && <p>{s.noCourses}</p>}
                    <Pagination
                        label={uiText("vidyalms.ui.7027e93d94e74fd2", "Course selection pages")}
                        page={page}
                        perPage={20}
                        total={courses.data?.total ?? 0}
                        onPageChange={setPage}
                    />
                </>
            )}
            {error && (
                <p role="alert" className="text-xs text-danger">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
