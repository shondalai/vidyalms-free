import { useQuery } from '@tanstack/react-query';
import type { components, QuestionTypeInfo } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';

export type QuestionPresetInfo = components['schemas']['QuestionPresetInfo'];

export interface QuestionTypeCatalogue {
    types: QuestionTypeInfo[];
    presets: QuestionPresetInfo[];
}

/** The installed server catalogue, shared by every authoring mount. */
export function useQuestionTypeCatalogue() {
    const client = useApiClient();
    return useQuery({
        queryKey: ['vidyalms', 'question-types'],
        staleTime: 5 * 60_000,
        queryFn: async ({ signal }): Promise<QuestionTypeCatalogue> => {
            const { data } = await client.GET('/question-types', { signal });
            const answer = must(data);
            return { types: answer.items, presets: answer.presets ?? [] };
        },
    });
}
