import { translate as uiText } from '@/shared/i18n';
import { z } from 'zod';

/* The refine messages are functions so they are translated when a value is checked, not when the shared chunk loads. */
const optionalUrl = z
    .string()
    .refine((value) => value === '' || /^https?:\/\/\S+$/i.test(value), { error: () => uiText("vidyalms.ui.9e5239fb446a51ce", "Enter a valid http or https URL.") });
export const lessonPresentationSchema = z.object({
    poster_url: optionalUrl,
    transcript: z.string().max(100_000),
    resume: z.boolean(),
    picture_in_picture: z.boolean(),
    download: z.boolean(),
    captions: z
        .array(
            z.object({
                label: z.string().trim().min(1),
                language: z.string().trim().min(1),
                url: z
                    .string()
                    .refine((value) => /^https?:\/\/\S+$/i.test(value), { error: () => uiText("vidyalms.ui.9e5239fb446a51ce", "Enter a valid http or https URL.") }),
            }),
        )
        .max(20),
    reading_width: z.enum(['comfortable', 'wide', 'full']),
    show_contents: z.boolean(),
    allow_print: z.boolean(),
    introduction: z.string().max(100_000),
    show_file_size: z.boolean(),
    new_tab: z.boolean(),
});
export type LessonPresentation = z.infer<typeof lessonPresentationSchema>;
export const presentationDefaults: LessonPresentation = {
    poster_url: '',
    transcript: '',
    resume: true,
    picture_in_picture: true,
    download: false,
    captions: [],
    reading_width: 'comfortable',
    show_contents: false,
    allow_print: false,
    introduction: '',
    show_file_size: true,
    new_tab: false,
};
const keys: Record<string, readonly (keyof LessonPresentation)[]> = {
    video: ['poster_url', 'transcript', 'resume', 'picture_in_picture', 'download', 'captions'],
    reading: ['reading_width', 'show_contents', 'allow_print'],
    resource: ['introduction', 'show_file_size', 'new_tab'],
};

/** Read only known, individually valid fields, without trusting optional provider content. */
export function readPresentation(content: Record<string, unknown> | null | undefined): LessonPresentation {
    const raw = content?.presentation;
    const parsed: Record<string, unknown> = { ...presentationDefaults, captions: [] };
    if (typeof raw === 'object' && raw !== null && !Array.isArray(raw)) {
        for (const key of Object.keys(presentationDefaults) as (keyof LessonPresentation)[]) {
            const result = lessonPresentationSchema.shape[key].safeParse(
                (raw as Record<string, unknown>)[key],
            );
            if (result.success) parsed[key] = result.data;
        }
    }
    return parsed as LessonPresentation;
}

/** Defaults need no stored override. Preserve provider additions inside the presentation object. */
export function presentationContent(
    type: string,
    value: LessonPresentation,
    original: Record<string, unknown>,
) {
    const supported = keys[type];
    if (!supported) return {};
    const raw = original.presentation;
    const result: Record<string, unknown> =
        typeof raw === 'object' && raw !== null && !Array.isArray(raw) ? { ...raw } : {};
    for (const key of supported) {
        if (JSON.stringify(value[key]) === JSON.stringify(presentationDefaults[key])) delete result[key];
        else result[key] = value[key];
    }
    return Object.keys(result).length > 0 || raw !== undefined ? { presentation: result } : {};
}
