import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';

const root = ['vidyalms', 'admin', 'learner-details'] as const;

export function useLearningProgress(userId: number, courseId: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...root, userId, courseId, 'progress'],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/learners/{user_id}', {
                params: { path: { id: courseId, user_id: userId } },
                signal,
            });
            return must(data);
        },
    });
}

export function useLearnerCurriculum(courseId: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...root, courseId, 'curriculum'],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/curriculum', {
                params: { path: { id: courseId } },
                signal,
            });
            return must(data);
        },
    });
}

export function useLearnerGradeItems(courseId: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...root, courseId, 'grade-items'],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/gradebook/items', {
                params: { path: { id: courseId } },
                signal,
            });
            return must(data);
        },
    });
}

export function useLearnerGrades(userId: number, courseId: number) {
    const client = useApiClient();
    return useInfiniteQuery({
        queryKey: [...root, userId, courseId, 'grades'],
        initialPageParam: undefined as string | undefined,
        queryFn: async ({ signal, pageParam }) => {
            const { data } = await client.GET('/manage/courses/{id}/gradebook/grades', {
                params: { path: { id: courseId }, query: { user_id: userId, limit: 50, cursor: pageParam } },
                signal,
            });
            return must(data);
        },
        getNextPageParam: (page) => page.next_cursor ?? undefined,
    });
}

export function useLearnerActivity(userId: number, courseId?: number) {
    const client = useApiClient();
    return useInfiniteQuery({
        queryKey: [...root, userId, courseId, 'activity'],
        initialPageParam: undefined as string | undefined,
        queryFn: async ({ signal, pageParam }) => {
            const { data } = await client.GET('/manage/learners/{user_id}/timeline', {
                params: {
                    path: { user_id: userId },
                    query: { course_id: courseId, limit: 25, cursor: pageParam },
                },
                signal,
            });
            return must(data);
        },
        getNextPageParam: (page) => page.next ?? undefined,
    });
}

export function useAccessEvidence(userId: number, courseId: number) {
    const client = useApiClient();
    return useQuery({
        queryKey: [...root, userId, courseId, 'access-evidence'],
        queryFn: async ({ signal }) => {
            const { data } = await client.GET(
                '/manage/learners/{user_id}/courses/{course_id}/access-evidence',
                { params: { path: { user_id: userId, course_id: courseId } }, signal },
            );
            return must(data);
        },
    });
}
