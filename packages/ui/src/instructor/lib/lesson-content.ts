import {
    lessonPresentationSchema,
    presentationDefaults,
    readPresentation,
    presentationContent,
    type LessonPresentation,
} from '@/shared/lesson-presentation';
import { z } from 'zod';
import type { AssignmentConfig, AssignmentConfigRequest, AuthoringLesson } from '@vidyalms/api-sdk';
import { instructorStrings as strings } from '../strings';
import { isApiDate } from './format';

/*
 * The authoring side of the five free lesson types: the content payloads
 * the core validates (packages/core/src/Provider/LessonType), read into one
 * flat form model and written back out.
 *
 *   video      {provider: youtube|vimeo|url, url, caption_url?}
 *   reading    {body}
 *   resource   {files: [{path, name, size_bytes?}]}   private storage paths
 *   assignment {instructions?}                        rules in the assignment row
 *   quiz       {quiz_id}                              or omitted, which creates one
 *
 * The lesson type is the authority and revalidates every write. What this
 * module guarantees is that an editor never silently drops a stored field:
 * a type with no block here (an add-on's type on a site without the add-on)
 * keeps its content verbatim and is saved back unchanged.
 */

export const VIDEO_PROVIDERS = ['youtube', 'vimeo', 'url'] as const;
export type VideoProvider = (typeof VIDEO_PROVIDERS)[number];

export interface LessonFileValue {
    path: string;
    name: string;
    sizeBytes: string;
}

export interface LessonFormValues {
    title: string;
    summary: string;
    durationMinutes: string;
    state: 'published' | 'draft';
    freePreview: boolean;
    presentation: LessonPresentation;
    /* video */
    provider: VideoProvider;
    url: string;
    captionUrl: string;
    /* reading */
    body: string;
    /* resource */
    files: LessonFileValue[];
    /* assignment: the lesson content, then the assignment row */
    instructions: string;
    maxPoints: string;
    maxFiles: string;
    maxFileSizeKb: string;
    allowedTypes: string;
    dueAt: string;
    allowResubmission: boolean;
    passPercentage: string;
    rubric: { id: string; label: string; max_points: number }[];
    /* quiz: an existing unlinked quiz of this course, or "" to create one */
    quizId: string;
}

const EMPTY: LessonFormValues = {
    title: '',
    summary: '',
    durationMinutes: '',
    state: 'published',
    freePreview: false,
    presentation: { ...presentationDefaults, captions: [] },
    provider: 'youtube',
    url: '',
    captionUrl: '',
    body: '',
    files: [],
    instructions: '',
    maxPoints: '100',
    maxFiles: '1',
    maxFileSizeKb: '',
    allowedTypes: '',
    dueAt: '',
    allowResubmission: false,
    passPercentage: '',
    rubric: [],
    quizId: '',
};

function stringOf(value: unknown): string {
    return typeof value === 'string' ? value : '';
}

function numberText(value: unknown): string {
    return typeof value === 'number' && Number.isFinite(value) ? String(value) : '';
}

function isVideoProvider(value: unknown): value is VideoProvider {
    return typeof value === 'string' && (VIDEO_PROVIDERS as readonly string[]).includes(value);
}

function filesOf(content: Record<string, unknown>): LessonFileValue[] {
    const files = content.files;
    if (!Array.isArray(files)) {
        return [];
    }
    return files.flatMap((entry) => {
        if (typeof entry !== 'object' || entry === null) {
            return [];
        }
        const record = entry as Record<string, unknown>;
        return [
            {
                path: stringOf(record.path),
                name: stringOf(record.name),
                sizeBytes: numberText(record.size_bytes),
            },
        ];
    });
}

export function blankLessonValues(type: string): LessonFormValues {
    if (type === 'resource') {
        return { ...EMPTY, files: [{ path: '', name: '', sizeBytes: '' }] };
    }
    return { ...EMPTY };
}

/** A stored lesson read into the form model, assignment configuration included. */
export function lessonFormValues(lesson: AuthoringLesson): LessonFormValues {
    const content = lesson.content;
    const assignment: AssignmentConfig | null = lesson.assignment;
    return {
        ...EMPTY,
        presentation: readPresentation(content),
        title: lesson.title,
        summary: lesson.summary ?? '',
        durationMinutes:
            lesson.duration_seconds === null
                ? ''
                : String(Math.max(1, Math.round(lesson.duration_seconds / 60))),
        /* The form holds the two states an author sets. Anything else the
           server holds (an archived lesson, reached some other way) reads as
           a draft, so saving the form can never republish it by accident. */
        state: lesson.state === 'published' ? 'published' : 'draft',
        freePreview: lesson.free_preview,
        provider: isVideoProvider(content.provider) ? content.provider : 'youtube',
        url: stringOf(content.url),
        captionUrl: stringOf(content.caption_url),
        body: stringOf(content.body),
        files: filesOf(content),
        instructions: stringOf(content.instructions),
        maxPoints: assignment === null ? EMPTY.maxPoints : String(assignment.max_points),
        maxFiles: assignment === null ? EMPTY.maxFiles : String(assignment.max_files),
        maxFileSizeKb: assignment?.max_file_size_kb == null ? '' : String(assignment.max_file_size_kb),
        allowedTypes: assignment === null ? '' : assignment.allowed_file_types.join(', '),
        dueAt: assignment?.due_at ?? '',
        allowResubmission: assignment?.allow_resubmission ?? false,
        passPercentage: assignment?.pass_percentage == null ? '' : String(assignment.pass_percentage),
        rubric: assignment?.rubric ?? [],
        quizId: typeof content.quiz_id === 'number' ? String(content.quiz_id) : '',
    };
}

/**
 * The content payload for a type, or null when the type has no block here
 * and its stored content must travel back untouched.
 */
export function lessonContent(
    type: string,
    values: LessonFormValues,
    original: Record<string, unknown> = {},
): Record<string, unknown> | null {
    const owned = editableContent(type, values);
    return owned === null
        ? null
        : { ...original, ...owned, ...presentationContent(type, values.presentation, original) };
}

function editableContent(type: string, values: LessonFormValues): Record<string, unknown> | null {
    switch (type) {
        case 'video': {
            const caption = values.captionUrl.trim();
            return {
                provider: values.provider,
                url: values.url.trim(),
                caption_url: caption === '' ? null : caption,
            };
        }
        case 'reading':
            return { body: values.body };
        case 'resource':
            return {
                files: values.files.map((file) => ({
                    path: file.path.trim(),
                    name: file.name.trim(),
                    size_bytes: file.sizeBytes.trim() === '' ? null : Number(file.sizeBytes),
                })),
            };
        case 'assignment': {
            const instructions = values.instructions.trim();
            return { instructions: instructions === '' ? null : instructions };
        }
        case 'quiz':
            return values.quizId === '' ? {} : { quiz_id: Number(values.quizId) };
        default:
            return null;
    }
}

/** The assignment row, which is a separate full-replacement write. */
export function assignmentRequest(values: LessonFormValues): AssignmentConfigRequest {
    const instructions = values.instructions.trim();
    const due = values.dueAt.trim();
    const size = values.maxFileSizeKb.trim();
    return {
        instructions: instructions === '' ? null : instructions,
        max_points: Number(values.maxPoints),
        allowed_file_types: values.allowedTypes
            .split(',')
            .map((entry) => entry.trim().replace(/^\./, '').toLowerCase())
            .filter((entry) => entry !== ''),
        max_file_size_kb: size === '' ? null : Number(size),
        max_files: Number(values.maxFiles),
        due_at: due === '' ? null : due,
        allow_resubmission: values.allowResubmission,
        pass_percentage: values.passPercentage.trim() === '' ? null : Number(values.passPercentage),
        rubric: values.rubric,
    };
}

export function durationSeconds(values: LessonFormValues): number | null {
    const minutes = values.durationMinutes.trim();
    return minutes === '' ? null : Math.max(0, Math.round(Number(minutes) * 60));
}

/* ── Validation ─────────────────────────────────────────────────────── */

const httpUrl = z
    .string()
    .trim()
    .refine((value) => /^https?:\/\/\S+$/i.test(value), strings.form.urlRequired);

const optionalNumberText = (message: string) =>
    z.string().refine((value) => value.trim() === '' || Number(value) > 0, message);

export function lessonFormSchema(type: string): z.ZodType<LessonFormValues, LessonFormValues> {
    const base = z.object({
        presentation: lessonPresentationSchema,
        title: z.string().trim().min(1, strings.form.required),
        summary: z.string(),
        durationMinutes: optionalNumberText(strings.form.positiveNumber),
        state: z.enum(['published', 'draft']),
        freePreview: z.boolean(),
        provider: z.enum(VIDEO_PROVIDERS),
        url: z.string(),
        captionUrl: z.string(),
        body: z.string(),
        files: z.array(z.object({ path: z.string(), name: z.string(), sizeBytes: z.string() })),
        instructions: z.string(),
        maxPoints: z.string(),
        maxFiles: z.string(),
        maxFileSizeKb: z.string(),
        allowedTypes: z.string(),
        dueAt: z.string(),
        allowResubmission: z.boolean(),
        passPercentage: z.string().refine(value => value === '' || (Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= 100), strings.form.positiveNumber),
        rubric: z.array(z.object({ id: z.string(), label: z.string().min(1, strings.form.required), max_points: z.number().positive() })),
        quizId: z.string(),
    });

    switch (type) {
        case 'video':
            return base.extend({
                url: httpUrl,
                durationMinutes: z.string().refine(value => Number.isFinite(Number(value)) && Number(value) > 0, strings.form.positiveNumber),
                captionUrl: z
                    .string()
                    .refine(
                        (value) => value.trim() === '' || /^https?:\/\/\S+$/i.test(value),
                        strings.form.urlRequired,
                    ),
            });
        case 'reading':
            return base.extend({ body: z.string().trim().min(1, strings.form.required) });
        case 'resource':
            return base.superRefine((values, ctx) => {
                if (values.files.length === 0) {
                    ctx.addIssue({ code: 'custom', path: ['files'], message: strings.form.atLeastOne });
                }
                values.files.forEach((file, index) => {
                    if (file.path.trim() === '') {
                        ctx.addIssue({
                            code: 'custom',
                            path: ['files', index, 'path'],
                            message: strings.form.required,
                        });
                    }
                    if (file.name.trim() === '') {
                        ctx.addIssue({
                            code: 'custom',
                            path: ['files', index, 'name'],
                            message: strings.form.required,
                        });
                    }
                });
            });
        case 'assignment':
            return base.superRefine((values, ctx) => {
                if (!(Number(values.maxPoints) > 0)) {
                    ctx.addIssue({
                        code: 'custom',
                        path: ['maxPoints'],
                        message: strings.form.positiveNumber,
                    });
                }
                if (!(Number(values.maxFiles) >= 1)) {
                    ctx.addIssue({
                        code: 'custom',
                        path: ['maxFiles'],
                        message: strings.form.positiveNumber,
                    });
                }
                if (values.maxFileSizeKb.trim() !== '' && !(Number(values.maxFileSizeKb) >= 1)) {
                    ctx.addIssue({
                        code: 'custom',
                        path: ['maxFileSizeKb'],
                        message: strings.form.positiveNumber,
                    });
                }
                if (values.dueAt.trim() !== '' && !isApiDate(values.dueAt)) {
                    ctx.addIssue({ code: 'custom', path: ['dueAt'], message: strings.form.dateFormat });
                }
            });
        default:
            return base;
    }
}
