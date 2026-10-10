import { lazy, Suspense } from 'react';
import { translate as uiText } from '@/shared/i18n';
import { ShopSetup } from '@/shared/shop-setup';
import { builderStrings as builder } from '../builder-strings';
const CourseArticleEditor = lazy(() =>
    import('../components/article-editor').then((module) => ({ default: module.ArticleEditor })),
);
import { isApiDate } from '../lib/format';
import { studioStrings as studio } from '../studio-strings';
import { PublishingReview } from './publishing-review';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { useEffect, useMemo, useState } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Image as ImageIcon, Plus, X } from 'lucide-react';
import type { ManagedCourseDetail } from '@vidyalms/api-sdk';
import {
    Badge,
    Button,
    CourseCategoryPicker,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    FilePicker,
    Icon,
    SaveIndicator,
} from '@/shared/components';
import { IMAGE_POLICY, policyFor } from '@/shared/upload';
import { hasRoute, useInstructorConfig } from '../config';
import {
    useAssignInstructor,
    useInstructors,
    useManagedCourses,
    useRemoveInstructor,
    useSetCourseState,
    useSetPrerequisites,
    useUpdateCourse,
    useUserSearch,
} from '../api';
import { instructorStrings as strings } from '../strings';
import { useSaveTracker } from '../lib/save-state';
import { CheckboxField, Field, FormSection, Select, TextArea, TextInput } from '../components/field';
import { SaveError } from '../components/states';
import { flowStrings } from '../flow-strings';
import { conditionTargets } from '../lib/condition-targets';
import { useAuthoringCurriculum } from '../api';
import { GrantsPanel } from './availability/grants-panel';
import { SubjectRestrictions } from './availability/subject-restrictions';
import { CourseCertificatePolicyPanel } from './course-certificate-panel';

/*
 * The Settings tab: the course fields, the three completion rules with
 * their threshold, prerequisites, instructors and the two state moves.
 *
 * State never travels through PATCH: publish and archive are their own
 * routes, so they are their own controls here, and publishing a course with
 * no published lesson answers 409, which is rendered where the action is
 * rather than as a mystery.
 */

function startTimestamp(value: string): string {
    const stamp = value.trim().replace('T', ' ');
    return stamp.length === 16 ? `${stamp}:00` : stamp;
}

const schema = z
    .object({
        title: z.string().trim().min(1, strings.form.required),
        categoryId: z.number().int().positive().nullable(),
        summary: z.string(),
        description: z.string(),
        image: z.string(),
        visibility: z.enum(['public', 'unlisted', 'private']),
        priceMode: z.enum(['free', 'paid']),
        completionRule: z.enum(['all_lessons', 'percentage', 'pass_final']),
        threshold: z.string(),
        slug: z.string().trim().min(1, strings.form.required),
        level: z.string(),
        language: z.string(),
        featured: z.boolean(),
        timezone: z.string().refine((value) => {
            if (value.trim() === '') return true;
            try {
                new Intl.DateTimeFormat('en', { timeZone: value.trim() });
                return true;
            } catch {
                return false;
            }
        }, studio.timezoneHint),
        startsAt: z
            .string()
            .refine(
                (value) => value.trim() === '' || isApiDate(startTimestamp(value)),
                strings.form.dateFormat,
            ),
    })
    .superRefine((values, ctx) => {
        if (values.completionRule !== 'percentage') {
            return;
        }
        const threshold = Number(values.threshold);
        if (!Number.isInteger(threshold) || threshold < 1 || threshold > 100) {
            ctx.addIssue({ code: 'custom', path: ['threshold'], message: strings.form.wholePercent });
        }
    });

type Values = z.infer<typeof schema>;

export function PublishingTab({ course }: { course: ManagedCourseDetail }) {
    return (
        <div className="vy-studio-publishing">
            <PublishingReview course={course} />
            <PublishingPanel course={course} />
        </div>
    );
}

type SettingsSection = keyof typeof studio.sections;
export function SettingsTab({
    course,
    narrow,
    onCertificateDirtyChange,
    initialSection = 'details',
}: {
    course: ManagedCourseDetail;
    narrow: boolean;
    onCertificateDirtyChange?: (dirty: boolean) => void;
    initialSection?: SettingsSection;
}) {
    const config = useInstructorConfig();
    const [section, setSection] = useState<SettingsSection>(initialSection);
    const [visited, setVisited] = useState<ReadonlySet<SettingsSection>>(new Set([initialSection]));
    const select = (next: SettingsSection) => {
        setSection(next);
        setVisited((old) => new Set([...old, next]));
    };
    return (
        <div className="vy-studio-settings" data-narrow={narrow}>
            <nav aria-label={strings.settings.heading}>
                {(Object.entries(studio.sections) as [SettingsSection, readonly [string, string]][])
                    .filter(
                        ([key]) =>
                            key !== 'certificates' ||
                            hasRoute(config, '/manage/courses/{id}/certificate-policy'),
                    )
                    .map(([key, [label, description]]) => (
                        <button
                            key={key}
                            type="button"
                            aria-current={section === key ? 'page' : undefined}
                            onClick={() => select(key)}
                        >
                            <strong>{label}</strong>
                            <small>{description}</small>
                        </button>
                    ))}
            </nav>
            <div className="min-w-0">
                {visited.has('details') && (
                    <div hidden={section !== 'details'}>
                        <CourseFields course={course} />
                    </div>
                )}
                {visited.has('access') && (
                    <div hidden={section !== 'access'}>
                        <div className="grid gap-4">
                            <PrerequisitesPanel course={course} />
                            <SequencingPanel course={course} />
                            <SubjectRestrictions
                                subjectType="course"
                                subjectId={course.id}
                                courseId={course.id}
                            />
                            <CourseGrantsPanel courseId={course.id} />
                        </div>
                    </div>
                )}
                {visited.has('team') && (
                    <div hidden={section !== 'team'}>
                        <InstructorsPanel course={course} />
                    </div>
                )}
                {visited.has('certificates') && (
                    <div hidden={section !== 'certificates'}>
                        <CourseCertificatePolicyPanel
                            courseId={course.id}
                            onDirtyChange={onCertificateDirtyChange}
                        />
                    </div>
                )}
                {visited.has('publishing') && (
                    <div hidden={section !== 'publishing'}>
                        <div className="grid gap-4">
                            <PublishingReview course={course} />
                            <PublishingPanel course={course} />
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * The sequencing axis.
 *
 * Linear composes an implicit "previous required item complete" into every
 * evaluation AT READ TIME and writes it into nobody's stored rule, so
 * flipping a course from linear to free rewrites no data, needs no migration
 * and loses no hand-authored restriction. That is why it is a plain enum
 * here and not another row in the restriction box.
 */
export function SequencingPanel({ course }: { course: ManagedCourseDetail }) {
    const updateCourse = useUpdateCourse();
    const save = useSaveTracker();
    const current = course.progression ?? 'free';

    return (
        <section className="grid gap-2.5 rounded border border-border bg-surface p-3.5">
            <div>
                <h3 className="font-display text-xs font-semibold text-fg">{flowStrings.sequencing.title}</h3>
                <p className="mt-0.5 text-[11.5px] text-fg-2">{flowStrings.sequencing.intro}</p>
            </div>
            <Field label={flowStrings.sequencing.label} hint={flowStrings.sequencing.hint}>
                {({ id, describedBy }) => (
                    <Select
                        id={id}
                        aria-describedby={describedBy}
                        className="max-w-[280px]"
                        value={current}
                        disabled={save.state === 'saving'}
                        onChange={(event) => {
                            const progression = event.target.value as 'linear' | 'free';
                            void save.run((idempotencyKey) =>
                                updateCourse.mutateAsync({
                                    courseId: course.id,
                                    body: { progression },
                                    idempotencyKey,
                                }),
                            );
                        }}
                    >
                        <option value="free">{flowStrings.sequencing.modes.free}</option>
                        <option value="linear">{flowStrings.sequencing.modes.linear}</option>
                    </Select>
                )}
            </Field>
            {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
            <SaveIndicator state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
        </section>
    );
}

/** The course-scoped exceptions list, with its targets resolved once. */
export function CourseGrantsPanel({ courseId }: { courseId: number }) {
    const curriculum = useAuthoringCurriculum(courseId);
    const targets = useMemo(() => conditionTargets(curriculum.data), [curriculum.data]);
    if (targets.length === 0) {
        return null;
    }
    return <GrantsPanel courseId={courseId} targets={targets} />;
}

export function CourseFields({
    course,
    mode = 'all',
}: {
    course: ManagedCourseDetail;
    mode?: 'all' | 'details' | 'access' | 'completion' | 'schedule';
}) {
    const updateCourse = useUpdateCourse();
    const save = useSaveTracker();
    const config = useInstructorConfig();
    const {
        register,
        control,
        handleSubmit,
        setValue,
        reset,
        formState: { errors, isDirty },
    } = useForm<Values>({
        resolver: zodResolver(schema),
        defaultValues: {
            title: course.title,
            slug: course.slug,
            level: course.level ?? '',
            language: course.language ?? '',
            featured: course.featured,
            timezone: course.timezone ?? '',
            startsAt: course.starts_at?.replace(' ', 'T') ?? '',
            categoryId: course.category_id,
            summary: course.summary ?? '',
            description: course.description ?? '',
            image: course.image ?? '',
            visibility: course.visibility,
            priceMode: course.price_mode === 'paid' ? 'paid' : 'free',
            completionRule: course.completion_rule,
            threshold: course.completion_threshold === null ? '80' : String(course.completion_threshold),
        },
    });
    useNavigationGuard(isDirty);
    const rule = useWatch({ control, name: 'completionRule' });
    const draft = useWatch({ control });
    const details = mode === 'all' || mode === 'details';
    const access = mode === 'all' || mode === 'access';
    const completion = mode === 'all' || mode === 'completion';
    const schedule = mode === 'all' || mode === 'schedule';
    const categoryId = useWatch({ control, name: 'categoryId' });

    const onSubmit = handleSubmit((values) => {
        const summary = values.summary.trim();
        const description = values.description.trim();
        const image = values.image.trim();
        void save.run(async (idempotencyKey) => {
            await updateCourse.mutateAsync({
                courseId: course.id,
                body: {
                    ...(details
                        ? {
                              title: values.title.trim(),
                              slug: values.slug.trim(),
                              level: values.level.trim() || null,
                              language: values.language.trim() || null,
                              featured: values.featured,
                              category_id: values.categoryId,
                              summary: summary || null,
                              description: description || null,
                              image: image || null,
                          }
                        : {}),
                    ...(schedule
                        ? {
                              timezone: values.timezone.trim() || null,
                              starts_at:
                                  values.startsAt.trim() === '' ? null : startTimestamp(values.startsAt),
                          }
                        : {}),
                    ...(access ? { visibility: values.visibility, price_mode: values.priceMode } : {}),
                    ...(completion
                        ? {
                              completion_rule: values.completionRule,
                              completion_threshold:
                                  values.completionRule === 'percentage' ? Number(values.threshold) : null,
                          }
                        : {}),
                },
                idempotencyKey,
            });
            reset(values);
        });
    });

    const saveControls = (
        <div className="vy-builder-savebar flex items-center gap-3">
            <Button type="submit" size="sm" disabled={save.state === 'saving'}>
                {builder.save[mode]}
            </Button>
            <SaveIndicator state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
        </div>
    );

    return (
        <form
            onSubmit={onSubmit}
            aria-label={mode === 'all' ? strings.settings.heading : builder.save[mode]}
            className="vy-builder-course-fields grid gap-4 rounded-lg border border-border bg-surface p-5"
        >
            {mode !== 'all' && saveControls}
            {mode === 'details' && (
                <aside className="vy-builder-course-card" aria-label={builder.courseCard}>
                    {draft.image ? (
                        <img src={draft.image} alt="" />
                    ) : (
                        <div className="vy-builder-cover-placeholder">
                            <Icon icon={ImageIcon} size={30} />
                            <span>{builder.coverHint}</span>
                        </div>
                    )}
                    <div>
                        <small>{builder.courseCard}</small>
                        <h3>{draft.title || course.title}</h3>
                        <p>{draft.summary || builder.noDescription}</p>
                    </div>
                </aside>
            )}
            {(details || access) && (
                <FormSection
                    title={details ? strings.settings.sections.about : builder.accessSections.visibility[0]}
                >
                    {details && (
                        <>
                            <Field label={strings.settings.fields.title} error={errors.title?.message}>
                                {({ id, describedBy, invalid }) => (
                                    <TextInput
                                        id={id}
                                        aria-describedby={describedBy}
                                        invalid={invalid}
                                        {...register('title')}
                                    />
                                )}
                            </Field>
                            <Field label={studio.slug} hint={studio.slugHint} error={errors.slug?.message}>
                                {({ id, describedBy, invalid }) => (
                                    <TextInput
                                        id={id}
                                        aria-describedby={describedBy}
                                        invalid={invalid}
                                        {...register('slug')}
                                    />
                                )}
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label={studio.level}>
                                    {({ id }) => <TextInput id={id} {...register('level')} />}
                                </Field>
                                <Field label={studio.language} hint={studio.languageHint}>
                                    {({ id }) => <TextInput id={id} {...register('language')} />}
                                </Field>
                            </div>
                            <CheckboxField label={studio.featured} {...register('featured')} />
                            <Field
                                label={strings.settings.fields.summary}
                                hint={strings.settings.fields.summaryHint}
                            >
                                {({ id, describedBy }) => (
                                    <TextArea
                                        id={id}
                                        rows={2}
                                        aria-describedby={describedBy}
                                        {...register('summary')}
                                    />
                                )}
                            </Field>
                            <Field
                                label={strings.settings.fields.description}
                                hint={strings.settings.fields.descriptionHint}
                            >
                                {({ id, describedBy }) =>
                                    mode === 'details' ? (
                                        <Suspense fallback={<p>{strings.states.loading}</p>}>
                                            <CourseArticleEditor
                                                id={id}
                                                describedBy={describedBy}
                                                label={strings.settings.fields.description}
                                                value={draft.description ?? ''}
                                                onChange={(value) =>
                                                    setValue('description', value, { shouldDirty: true })
                                                }
                                            />
                                        </Suspense>
                                    ) : (
                                        <TextArea
                                            id={id}
                                            rows={6}
                                            className="font-mono text-xs"
                                            aria-describedby={describedBy}
                                            {...register('description')}
                                        />
                                    )
                                }
                            </Field>
                            <CourseCategoryPicker
                                label={strings.settings.fields.category}
                                hint={strings.settings.fields.categoryHint}
                                value={categoryId}
                                onChange={(value) => setValue('categoryId', value, { shouldDirty: true })}
                                disabled={save.state === 'saving'}
                            />
                            <Field label={strings.settings.fields.image}>
                                {({ id, describedBy }) => (
                                    <TextInput
                                        id={id}
                                        inputMode="url"
                                        aria-describedby={describedBy}
                                        {...register('image')}
                                    />
                                )}
                            </Field>
                            {/* The picker is an addition, never a replacement: a host
                    with no upload endpoint leaves the typed address as the
                    only way in, which is exactly how this worked before. */}
                            {config.urls.upload !== undefined && (
                                <FilePicker
                                    endpoint={config.urls.upload}
                                    headers={config.api.headers}
                                    label={strings.settings.fields.imageUpload}
                                    hint={strings.settings.fields.imageUploadHint}
                                    policy={policyFor(IMAGE_POLICY, config.uploadPurposes)}
                                    requires="url"
                                    onUploaded={(url) => setValue('image', url, { shouldDirty: true })}
                                />
                            )}
                        </>
                    )}
                    {access && (
                        <Field label={strings.settings.fields.visibility}>
                            {({ id, describedBy }) => (
                                <Select id={id} aria-describedby={describedBy} {...register('visibility')}>
                                    <option value="public">{strings.settings.fields.visibilityPublic}</option>
                                    <option value="unlisted">
                                        {strings.settings.fields.visibilityUnlisted}
                                    </option>
                                    <option value="private">
                                        {strings.settings.fields.visibilityPrivate}
                                    </option>
                                </Select>
                            )}
                        </Field>
                    )}
                </FormSection>
            )}

            {access && (
                <FormSection title={uiText('vidyalms.ui.coursePaymentAccess', 'Free or paid access')}>
                    <Field label={uiText('vidyalms.ui.courseAccessType', 'Course access')}
                        hint={uiText('vidyalms.ui.courseAccessTypeHint', 'Choose whether learners need a purchase. Existing enrolments are not changed by this setting.')}>
                        {({ id, describedBy }) => (
                            <Select id={id} aria-describedby={describedBy} {...register('priceMode')}>
                                <option value="free">{uiText('vidyalms.ui.courseAccessFree', 'Free: no purchase required')}</option>
                                <option value="paid">{uiText('vidyalms.ui.courseAccessPaid', 'Paid: purchase through your shop')}</option>
                            </Select>
                        )}
                    </Field>
                    {draft.priceMode === 'paid' ? (
                        <>
                            <p className="text-sm text-fg-2">{uiText('vidyalms.ui.coursePaidSetupSteps', 'Save this course as Paid, then connect a shop product to it. The shop supplies the displayed price and purchase link; confirmed purchases grant access automatically.')}</p>
                            <ShopSetup shop={config.shop} />
                        </>
                    ) : (
                        <p className="text-sm text-fg-2">{uiText('vidyalms.ui.courseFreeAccessHint', 'Free courses still follow your enrolment policy and access conditions. Selecting Free does not publish the course or enrol learners.')}</p>
                    )}
                </FormSection>
            )}
            {schedule && (
                <FormSection title={studio.schedule}>
                    <Field
                        label={studio.timezone}
                        hint={studio.timezoneHint}
                        error={errors.timezone?.message}
                    >
                        {({ id, describedBy, invalid }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                {...register('timezone')}
                            />
                        )}
                    </Field>
                    <Field
                        label={studio.startsAt}
                        hint={studio.startsAtHint}
                        error={errors.startsAt?.message}
                    >
                        {({ id, describedBy, invalid }) => (
                            <TextInput
                                id={id}
                                aria-describedby={describedBy}
                                invalid={invalid}
                                type="datetime-local"
                                step="1"
                                {...register('startsAt')}
                            />
                        )}
                    </Field>
                </FormSection>
            )}
            {completion && (
                <FormSection title={strings.settings.sections.completion}>
                    <Field label={strings.settings.fields.completionRule}>
                        {({ id, describedBy }) => (
                            <Select id={id} aria-describedby={describedBy} {...register('completionRule')}>
                                <option value="all_lessons">{strings.settings.fields.ruleAllLessons}</option>
                                <option value="percentage">{strings.settings.fields.rulePercentage}</option>
                                <option value="pass_final">{strings.settings.fields.rulePassFinal}</option>
                            </Select>
                        )}
                    </Field>
                    {rule === 'percentage' && (
                        <Field
                            label={strings.settings.fields.threshold}
                            hint={strings.settings.fields.thresholdHint}
                            error={errors.threshold?.message}
                        >
                            {({ id, describedBy, invalid }) => (
                                <TextInput
                                    id={id}
                                    inputMode="numeric"
                                    className="w-28"
                                    aria-describedby={describedBy}
                                    invalid={invalid}
                                    {...register('threshold')}
                                />
                            )}
                        </Field>
                    )}
                    {rule === 'pass_final' && (
                        <p className="text-[11.5px] text-fg-2">{strings.settings.fields.passFinalHint}</p>
                    )}
                </FormSection>
            )}

            {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}

            {mode === 'all' && saveControls}
        </form>
    );
}

function PublishingPanel({ course }: { course: ManagedCourseDetail }) {
    const setCourseState = useSetCourseState();
    const save = useSaveTracker();
    const [confirmArchive, setConfirmArchive] = useState(false);

    const move = (action: 'publish' | 'archive') => {
        void save
            .run((idempotencyKey) =>
                setCourseState.mutateAsync({ courseId: course.id, action, idempotencyKey }),
            )
            .then((ok) => {
                if (ok) {
                    setConfirmArchive(false);
                }
            });
    };

    return (
        <section className="grid gap-3 rounded-lg border border-border bg-surface p-5">
            <h3 className="font-display text-xs font-semibold text-fg">{strings.settings.sections.state}</h3>
            <p className="text-[12.5px] text-fg-2">{strings.settings.state[course.state]}</p>
            {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
            <div className="flex flex-wrap items-center gap-2">
                {course.state !== 'published' && (
                    <Button size="sm" onClick={() => move('publish')} disabled={save.state === 'saving'}>
                        {course.state === 'archived'
                            ? strings.settings.state.republish
                            : strings.settings.state.publish}
                    </Button>
                )}
                {course.state !== 'archived' && (
                    <Button variant="outline" size="sm" onClick={() => setConfirmArchive(true)}>
                        {strings.settings.state.archive}
                    </Button>
                )}
                <SaveIndicator state={save.state} savedAt={save.savedAt} />
            </div>
            {course.state !== 'published' && course.lesson_count === 0 && (
                <p className="text-[11.5px] text-fg-2">{strings.settings.state.needsLesson}</p>
            )}
            {confirmArchive && (
                <Dialog open onOpenChange={(open) => !open && setConfirmArchive(false)}>
                    <DialogContent className="max-w-md">
                        <DialogTitle>{strings.settings.state.archiveTitle}</DialogTitle>
                        <DialogDescription>{strings.settings.state.archiveBody}</DialogDescription>
                        <DialogFooter>
                            <Button variant="outline" size="sm" onClick={() => setConfirmArchive(false)}>
                                {strings.form.cancel}
                            </Button>
                            <Button
                                variant="destructive"
                                size="sm"
                                onClick={() => move('archive')}
                                disabled={save.state === 'saving'}
                            >
                                {strings.settings.state.archiveConfirm}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </section>
    );
}

export function PrerequisitesPanel({ course }: { course: ManagedCourseDetail }) {
    const setPrerequisites = useSetPrerequisites();
    const save = useSaveTracker();
    const [ids, setIds] = useState<number[]>(course.prerequisites.map((entry) => entry.id));
    const [choice, setChoice] = useState('');
    const [search, setSearch] = useState('');
    const [query, setQuery] = useState('');
    useEffect(() => {
        const timer = window.setTimeout(() => setQuery(search.trim()), 300);
        return () => window.clearTimeout(timer);
    }, [search]);
    const coursesQuery = useManagedCourses({
        ...(query === '' ? {} : { q: query }),
        page: 1,
        per_page: 20,
        sort: 'title',
        order: 'asc',
    });

    const known = new Map(
        [
            ...(coursesQuery.data?.items ?? []),
            ...course.prerequisites.map((entry) => ({ ...entry, id: entry.id })),
        ].map((entry) => [entry.id, entry.title] as const),
    );
    const selectable = (coursesQuery.data?.items ?? []).filter(
        (candidate) => candidate.id !== course.id && !ids.includes(candidate.id),
    );

    /*
     * The list is shown optimistically, so a refused write has to put it back:
     * a prerequisite gates every learner of this course, and leaving one on
     * screen that the server never stored is the worst way to be wrong about
     * it. The retry keeps the failed write's key, so accepting it later still
     * replays the same logical write.
     */
    const commit = (next: number[]) => {
        const shown = ids;
        setIds(next);
        void save
            .run((idempotencyKey) =>
                setPrerequisites.mutateAsync({ courseId: course.id, courseIds: next, idempotencyKey }),
            )
            .then((ok) => {
                if (!ok) {
                    setIds(shown);
                }
            });
    };

    return (
        <section className="grid gap-3 rounded-lg border border-border bg-surface p-5">
            <h3 className="font-display text-xs font-semibold text-fg">
                {strings.settings.sections.prerequisites}
            </h3>
            <p className="text-[11.5px] text-fg-2">{strings.settings.prerequisites.hint}</p>
            {ids.length === 0 ? (
                <p className="text-[12.5px] text-fg-2">{strings.settings.prerequisites.none}</p>
            ) : (
                <ul className="grid gap-1.5">
                    {ids.map((id) => {
                        const title = known.get(id) ?? String(id);
                        return (
                            <li
                                key={id}
                                className="flex items-center gap-2 rounded border border-border px-2.5 py-1.5 text-[12.5px]"
                            >
                                <span className="min-w-0 flex-1 truncate">{title}</span>
                                <button
                                    type="button"
                                    aria-label={strings.settings.prerequisites.remove(title)}
                                    onClick={() => commit(ids.filter((entry) => entry !== id))}
                                    className="cursor-pointer text-fg-2 hover:text-fg"
                                >
                                    <Icon icon={X} size={14} />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <Field label={strings.settings.prerequisites.searchLabel} hideLabel>
                {({ id }) => (
                    <TextInput
                        id={id}
                        type="search"
                        value={search}
                        placeholder={strings.settings.prerequisites.searchPlaceholder}
                        aria-label={strings.settings.prerequisites.searchLabel}
                        onChange={(event) => {
                            setSearch(event.target.value);
                            setChoice('');
                        }}
                    />
                )}
            </Field>
            {coursesQuery.isError && (
                <ErrorState error={coursesQuery.error} onRetry={() => void coursesQuery.refetch()} />
            )}
            {coursesQuery.isSuccess && selectable.length === 0 && (
                <p className="text-[11.5px] text-fg-2">{strings.settings.prerequisites.noMatches}</p>
            )}
            <div className="flex items-end gap-2">
                <Field className="min-w-0 flex-1" label={strings.settings.prerequisites.addLabel} hideLabel>
                    {({ id }) => (
                        <Select
                            id={id}
                            className="h-[34px]"
                            aria-label={strings.settings.prerequisites.addLabel}
                            value={choice}
                            onChange={(event) => setChoice(event.target.value)}
                        >
                            <option value="">{strings.settings.prerequisites.addLabel}</option>
                            {selectable.map((candidate) => (
                                <option key={candidate.id} value={String(candidate.id)}>
                                    {candidate.title}
                                </option>
                            ))}
                        </Select>
                    )}
                </Field>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={choice === ''}
                    onClick={() => {
                        commit([...ids, Number(choice)]);
                        setChoice('');
                    }}
                >
                    <Icon icon={Plus} size={13} />
                    {strings.settings.prerequisites.add}
                </Button>
            </div>
            {save.state === 'failed' && <SaveError error={save.error} onRetry={save.retry} />}
            <SaveIndicator state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
        </section>
    );
}

export function InstructorsPanel({ course }: { course: ManagedCourseDetail }) {
    const instructorsQuery = useInstructors(course.id);
    const assignInstructor = useAssignInstructor();
    const removeInstructor = useRemoveInstructor();
    const save = useSaveTracker();
    const [term, setTerm] = useState('');
    const searchQuery = useUserSearch(term);
    const instructors = instructorsQuery.data ?? course.instructors;

    const add = (userId: number) => {
        setTerm('');
        void save.run((idempotencyKey) =>
            assignInstructor.mutateAsync({ courseId: course.id, userId, idempotencyKey }),
        );
    };

    return (
        <section className="grid gap-3 rounded-lg border border-border bg-surface p-5">
            <h3 className="font-display text-xs font-semibold text-fg">
                {strings.settings.sections.instructors}
            </h3>
            <p className="text-[11.5px] text-fg-2">{strings.settings.instructors.hint}</p>
            {instructors.length === 0 ? (
                <p className="text-[12.5px] text-fg-2">{strings.settings.instructors.none}</p>
            ) : (
                <ul className="grid gap-1.5">
                    {instructors.map((instructor) => {
                        const name = instructor.name ?? strings.settings.instructors.unknown;
                        return (
                            <li
                                key={instructor.user_id}
                                className="flex items-center gap-2 rounded border border-border px-2.5 py-1.5 text-[12.5px]"
                            >
                                <span className="min-w-0 flex-1 truncate">{name}</span>
                                <Badge variant="neutral" glyph={null}>
                                    {instructor.role}
                                </Badge>
                                <button
                                    type="button"
                                    aria-label={strings.settings.instructors.remove(name)}
                                    onClick={() =>
                                        void save.run((idempotencyKey) =>
                                            removeInstructor.mutateAsync({
                                                courseId: course.id,
                                                userId: instructor.user_id,
                                                idempotencyKey,
                                            }),
                                        )
                                    }
                                    className="cursor-pointer text-fg-2 hover:text-fg"
                                >
                                    <Icon icon={X} size={14} />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
            <Field label={strings.settings.instructors.add} hint={strings.enrolDialog.searchHint}>
                {({ id, describedBy }) => (
                    <TextInput
                        id={id}
                        type="search"
                        autoComplete="off"
                        aria-describedby={describedBy}
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                    />
                )}
            </Field>
            {searchQuery.data !== undefined && searchQuery.data.length > 0 && (
                <ul aria-label={strings.enrolDialog.results} className="grid gap-1.5">
                    {searchQuery.data.map((account) => (
                        <li key={account.id}>
                            <button
                                type="button"
                                onClick={() => add(account.id)}
                                className="flex w-full cursor-pointer items-center justify-between gap-2 rounded border border-border px-2.5 py-1.5 text-left text-[12.5px] hover:bg-bg"
                            >
                                <span className="min-w-0 truncate">{account.name}</span>
                                <span className="min-w-0 truncate text-fg-2">{account.email}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {save.state === 'failed' && (
                <SaveError
                    error={save.error}
                    onRetry={save.retry}
                    deniedTitle={strings.settings.instructors.denied}
                />
            )}
            <SaveIndicator state={save.state} savedAt={save.savedAt} onRetry={save.retry} />
        </section>
    );
}
