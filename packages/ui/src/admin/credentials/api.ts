import { useRef } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type {
    BadgeAwardRequest,
    BadgeRequest,
    CertificatePreviewRequest,
    CertificateReissueOneRequest,
    CertificateReissueRequest,
    CertificateReissueStatus,
    CertificateTemplateDetail,
    CertificateTemplateElementRequest,
    CertificateTemplatePageRequest,
    CertificateTemplateRequest,
    CertificateTemplateUpdateRequest,
    CourseCreditRequest,
} from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { idempotency, keepPrevious, must } from '@/shared/manage';
import { newIdempotencyKey } from '@/shared/save-state';

/*
 * The credential half of the admin surface, over the generated SDK and
 * nothing else: the template aggregate, the issuance records and their
 * lifecycle, continuing-education credit and badges.
 *
 * Its own module rather than an addition to admin/api.ts, because the
 * credential screens are one feature with one invalidation subtree and
 * because two agents editing one file is a merge, not a design.
 *
 * INVALIDATION. Every write here can change more than the row it names: a
 * reissue writes a new certificate and supersedes an old one, a template
 * edit bumps a revision that the certificate list filters on, and a badge
 * award changes a count on the badge. So a mutation invalidates the whole
 * credential subtree rather than guessing which list moved, which is the
 * same rule the rest of the admin app follows.
 */

export const credentialKeys = {
    root: ['vidyalms', 'admin', 'credentials'] as const,
    templates: (params: TemplateListParams) => ['vidyalms', 'admin', 'credentials', 'templates', params] as const,
    template: (id: number) => ['vidyalms', 'admin', 'credentials', 'template', id] as const,
    elementTypes: (id: number) => ['vidyalms', 'admin', 'credentials', 'element-types', id] as const,
    certificate: (id: number) => ['vidyalms', 'admin', 'credentials', 'certificate', id] as const,
    creditSchemes: ['vidyalms', 'admin', 'credentials', 'credit-schemes'] as const,
    courseCredits: (courseId: number) => ['vidyalms', 'admin', 'credentials', 'course-credits', courseId] as const,
    badges: (params: BadgeListParams) => ['vidyalms', 'admin', 'credentials', 'badges', params] as const,
    badgeAwards: (badgeId: number, page: number) =>
        ['vidyalms', 'admin', 'credentials', 'badge-awards', badgeId, page] as const,
};

export interface TemplateListParams {
    search?: string;
    category?: string;
    renderer?: string;
    state?: 'draft' | 'published' | 'archived';
    format?: 'html' | 'elements';
    page?: number;
    per_page?: number;
}

export interface BadgeListParams {
    state?: 'draft' | 'published' | 'archived';
    course_id?: number;
    page?: number;
    per_page?: number;
}

export interface CapturedCertificateWrite<T> {
    input: T;
    idempotencyKey: string;
    fingerprint: string;
}

function canonicalWriteValue(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value.map(canonicalWriteValue);
    }
    if (value !== null && typeof value === 'object') {
        return Object.fromEntries(
            Object.entries(value as Record<string, unknown>)
                .filter(([, field]) => field !== undefined)
                .sort(([left], [right]) => left.localeCompare(right))
                .map(([key, field]) => [key, canonicalWriteValue(field)]),
        );
    }
    return value;
}

/**
 * Captures one logical certificate write across an ambiguous failed response.
 *
 * The intent fingerprint deliberately omits the server revision. If a
 * background refetch observes that the first request actually committed, a
 * retry must replay its original body/revision/key, not turn into a second
 * add at the newly observed revision. A deliberate payload change has a new
 * key, but a dirty editor keeps the revision on which that draft began so the
 * server can surface a conflict instead of accepting a lost update.
 */
export function useCertificateWriteAttempt() {
    const current = useRef<CapturedCertificateWrite<unknown> | null>(null);
    const authoringRevision = useRef<number | null>(null);

    return {
        /**
         * The first deliberate edit owns its source revision until that draft
         * is accepted. A passive query refresh may reveal a newer aggregate,
         * but silently adopting it would let this older draft overwrite the
         * other author's fields instead of receiving the expected 409.
         */
        pinRevision(revision: number): number {
            authoringRevision.current ??= revision;
            return authoringRevision.current;
        },
        capture<T>(intent: unknown, makeInput: () => T, attemptIdentity?: unknown): CapturedCertificateWrite<T> {
            /* A dirty form supplies an identity advanced only by a deliberate
               author edit. Passive merges may change its displayed clean
               fields, but an ambiguous retry must retain its original bytes,
               revision and key until the author actually edits again. */
            const fingerprint = JSON.stringify(canonicalWriteValue(attemptIdentity ?? intent)) ?? 'undefined';
            if (current.current?.fingerprint === fingerprint) {
                return current.current as CapturedCertificateWrite<T>;
            }
            const attempt: CapturedCertificateWrite<T> = {
                input: makeInput(),
                idempotencyKey: newIdempotencyKey(),
                fingerprint,
            };
            current.current = attempt;
            return attempt;
        },
        complete(attempt: CapturedCertificateWrite<unknown>): void {
            if (current.current?.idempotencyKey === attempt.idempotencyKey) {
                current.current = null;
                authoringRevision.current = null;
            }
        },
    };
}

/* ── The template aggregate ─────────────────────────────────────────── */

export function useCertificateTemplates(params: TemplateListParams, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: credentialKeys.templates(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/certificate-templates', {
                params: { query: params },
                signal,
            });
            return must(data);
        },
    });
}

export function useCertificateTemplate(id: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: id !== null,
        queryKey: credentialKeys.template(id ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/certificate-templates/{id}', {
                params: { path: { id: id as number } },
                signal,
            });
            return must(data);
        },
    });
}

/**
 * The palette, which is the element registry as this template sees it. It is
 * per template rather than per site: an element may refuse to be offered on
 * a template that lacks what it needs, which is how the credit statement
 * stays out of the palette on a template whose course carries no scheme.
 */
export function useCertificateElementTypes(templateId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: templateId !== null,
        queryKey: credentialKeys.elementTypes(templateId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/certificate-templates/{id}/element-types', {
                params: { path: { id: templateId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCredentialInvalidation() {
    const queryClient = useQueryClient();
    return () => {
        void queryClient.invalidateQueries({ queryKey: credentialKeys.root });
        /* A reissue and a revocation both move rows on the certificates
           list, which lives under the admin root rather than this one. */
        void queryClient.invalidateQueries({ queryKey: ['vidyalms', 'admin'] });
    };
}

/**
 * Child writes return the aggregate revision that won the compare-and-swap.
 * Put it into the detail cache before invalidating so a second deliberate
 * edit cannot leave with the stale revision while the refetch is in flight.
 * A replay response may arrive after another write has already advanced the
 * cache, so an older result is never allowed to roll the aggregate backward.
 */
export function useTemplateWriteSuccess() {
    const queryClient = useQueryClient();
    const invalidate = useCredentialInvalidation();

    return (
        templateId: number,
        revision: number,
        update: (current: CertificateTemplateDetail) => CertificateTemplateDetail = (current) => current,
    ) => {
        queryClient.setQueryData<CertificateTemplateDetail>(credentialKeys.template(templateId), (current) => {
            if (current === undefined || current.revision > revision) {
                return current;
            }
            return { ...update(current), revision };
        });
        invalidate();
    };
}

export function useCreateCertificateTemplate() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({ body, idempotencyKey }: { body: CertificateTemplateRequest; idempotencyKey: string }) => {
            const { data } = await client.POST('/manage/certificate-templates', {
                body,
                params: { header: { 'Idempotency-Key': idempotencyKey } },
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

export function useUpdateCertificateTemplate() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            id,
            body,
            idempotencyKey,
        }: {
            id: number;
            body: CertificateTemplateUpdateRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PATCH('/manage/certificate-templates/{id}', {
                params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: (template) => writeSucceeded(template.id, template.revision, (current) => ({ ...current, ...template })),
    });
}

/**
 * Archiving, not deleting. A template a certificate was issued from is
 * evidence: the issuance row froze its own snapshot, but the template still
 * answers "which one was this", so the route archives rather than removes.
 */
export function useArchiveCertificateTemplate() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({
            id,
            expectedRevision,
            idempotencyKey,
        }: {
            id: number;
            expectedRevision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.DELETE('/manage/certificate-templates/{id}', {
                params: {
                    path: { id },
                    query: { expected_revision: expectedRevision },
                    header: { 'Idempotency-Key': idempotencyKey },
                },
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

export function useAddTemplatePage() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            templateId,
            body,
            idempotencyKey,
        }: {
            templateId: number;
            body: CertificateTemplatePageRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/manage/certificate-templates/{id}/pages', {
                params: {
                    path: { id: templateId },
                    header: { 'Idempotency-Key': idempotencyKey },
                },
                body,
            });
            return must(data);
        },
        onSuccess: (result, { templateId }) =>
            writeSucceeded(templateId, result.template_revision, (current) => ({
                ...current,
                pages: current.pages.some((page) => page.id === result.item.id)
                    ? current.pages.map((page) => (page.id === result.item.id ? result.item : page))
                    : [...current.pages, result.item],
            })),
    });
}

export function useUpdateTemplatePage() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            pageId,
            body,
            idempotencyKey,
        }: {
            templateId: number;
            pageId: number;
            body: CertificateTemplatePageRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PATCH('/manage/certificate-template-pages/{id}', {
                params: { path: { id: pageId }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: (result, { templateId }) =>
            writeSucceeded(templateId, result.template_revision, (current) => ({
                ...current,
                pages: current.pages.map((page) => (page.id === result.item.id ? result.item : page)),
            })),
    });
}

export function useDeleteTemplatePage() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            pageId,
            expectedRevision,
            idempotencyKey,
        }: {
            templateId: number;
            pageId: number;
            expectedRevision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.DELETE('/manage/certificate-template-pages/{id}', {
                params: {
                    path: { id: pageId },
                    query: { expected_revision: expectedRevision },
                    header: { 'Idempotency-Key': idempotencyKey },
                },
            });
            return must(data);
        },
        onSuccess: (result, { templateId, pageId }) =>
            writeSucceeded(templateId, result.template_revision, (current) => ({
                ...current,
                pages: current.pages.filter((page) => page.id !== pageId),
            })),
    });
}

/**
 * The whole ordered list, never a swap: a list is one write and one
 * conflict, while N sequence patches are N races. The contract refuses a
 * list that is not exactly the template's pages, because a partial reorder
 * would silently drop a page from the render.
 */
export function useReorderTemplatePages() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            templateId,
            ids,
            expectedRevision,
            idempotencyKey,
        }: {
            templateId: number;
            ids: number[];
            expectedRevision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PUT('/manage/certificate-templates/{id}/pages/order', {
                params: {
                    path: { id: templateId },
                    header: { 'Idempotency-Key': idempotencyKey },
                },
                body: { ids, expected_revision: expectedRevision },
            });
            return must(data);
        },
        onSuccess: (result, { templateId }) =>
            writeSucceeded(templateId, result.template_revision, (current) => ({ ...current, pages: result.items })),
    });
}

export function useAddTemplateElement() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            pageId,
            body,
            idempotencyKey,
        }: {
            pageId: number;
            body: CertificateTemplateElementRequest;
            templateId: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.POST('/manage/certificate-template-pages/{id}/elements', {
                params: { path: { id: pageId }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: (result, { templateId, pageId }) =>
            writeSucceeded(templateId, result.template_revision, (current) => ({
                ...current,
                pages: current.pages.map((page) =>
                    page.id === pageId
                        ? {
                              ...page,
                              elements: page.elements.some((element) => element.id === result.item.id)
                                  ? page.elements.map((element) =>
                                        element.id === result.item.id ? result.item : element,
                                    )
                                  : [...page.elements, result.item],
                          }
                        : page,
                ),
            })),
    });
}

export function useUpdateTemplateElement() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            elementId,
            body,
            idempotencyKey,
        }: {
            templateId: number;
            pageId: number;
            elementId: number;
            body: CertificateTemplateElementRequest;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PATCH('/manage/certificate-template-elements/{id}', {
                params: { path: { id: elementId }, header: { 'Idempotency-Key': idempotencyKey } },
                body,
            });
            return must(data);
        },
        onSuccess: (result, { templateId, pageId }) =>
            writeSucceeded(templateId, result.template_revision, (current) => ({
                ...current,
                pages: current.pages.map((page) =>
                    page.id === pageId
                        ? {
                              ...page,
                              elements: page.elements.map((element) =>
                                  element.id === result.item.id ? result.item : element,
                              ),
                          }
                        : page,
                ),
            })),
    });
}

export function useDeleteTemplateElement() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            elementId,
            expectedRevision,
            idempotencyKey,
        }: {
            templateId: number;
            pageId: number;
            elementId: number;
            expectedRevision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.DELETE('/manage/certificate-template-elements/{id}', {
                params: {
                    path: { id: elementId },
                    query: { expected_revision: expectedRevision },
                    header: { 'Idempotency-Key': idempotencyKey },
                },
            });
            return must(data);
        },
        onSuccess: (result, { templateId, pageId, elementId }) =>
            writeSucceeded(templateId, result.template_revision, (current) => ({
                ...current,
                pages: current.pages.map((page) =>
                    page.id === pageId
                        ? { ...page, elements: page.elements.filter((element) => element.id !== elementId) }
                        : page,
                ),
            })),
    });
}

/** Draw order, which is what puts a border behind a name rather than over it. */
export function useReorderTemplateElements() {
    const client = useApiClient();
    const writeSucceeded = useTemplateWriteSuccess();
    return useMutation({
        mutationFn: async ({
            pageId,
            ids,
            expectedRevision,
            idempotencyKey,
        }: {
            templateId: number;
            pageId: number;
            ids: number[];
            expectedRevision: number;
            idempotencyKey: string;
        }) => {
            const { data } = await client.PUT('/manage/certificate-template-pages/{id}/elements/order', {
                params: { path: { id: pageId }, header: { 'Idempotency-Key': idempotencyKey } },
                body: { ids, expected_revision: expectedRevision },
            });
            return must(data);
        },
        onSuccess: (result, { templateId, pageId }) =>
            writeSucceeded(templateId, result.template_revision, (current) => ({
                ...current,
                pages: current.pages.map((page) =>
                    page.id === pageId ? { ...page, elements: result.items } : page,
                ),
            })),
    });
}

/**
 * Purpose "preview": nothing is written, and a certificate previewed against
 * this template keeps rendering from its own frozen snapshot.
 */
export function usePreviewCertificateTemplate() {
    const client = useApiClient();
    return useMutation({
        mutationFn: async ({ templateId, body }: { templateId: number; body: CertificatePreviewRequest }) => {
            const { data } = await client.POST('/manage/certificate-templates/{id}/preview', {
                params: { path: { id: templateId } },
                body,
            });
            return must(data);
        },
    });
}

/**
 * Bulk reissue. The 200 answer means no package registers it and nothing was
 * queued, which is the registry miss applied to a route; the 202 answer
 * carries the fan-out key a cancellation names.
 */
export function useBulkReissueStatus(templateId: number) {
    const client = useApiClient();
    return useQuery<CertificateReissueStatus>({
        queryKey: [...credentialKeys.root, 'reissue-status', templateId],
        refetchInterval: (query) => ['queued', 'running'].includes(query.state.data?.state ?? '') ? 5000 : false,
        queryFn: async ({ signal }) => must((await client.GET('/manage/certificate-templates/{id}/reissue', {
            params: { path: { id: templateId } }, signal,
        })).data),
    });
}

export function useStartBulkReissue() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({
            templateId,
            body,
            idempotencyKey,
        }: {
            templateId: number;
            body: CertificateReissueRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/certificate-templates/{id}/reissue', {
                params: { path: { id: templateId }, header: { 'Idempotency-Key': idempotencyKey ?? newIdempotencyKey() } },
                body,
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

export function useCancelBulkReissue() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({ templateId, revision, idempotencyKey }: { templateId: number; revision: number; idempotencyKey: string }) => {
            await client.DELETE('/manage/certificate-templates/{id}/reissue', {
                params: { path: { id: templateId }, query: { revision }, header: { 'Idempotency-Key': idempotencyKey } },
            });
        },
        onSuccess: invalidate,
    });
}

/* ── One issuance record and its lifecycle ──────────────────────────── */

export function useCertificateDetail(id: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: id !== null,
        queryKey: credentialKeys.certificate(id ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/admin/certificates/{id}', {
                params: { path: { id: id as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useSuspendCertificate() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({
            certificateId,
            reason,
            note,
        }: {
            certificateId: number;
            reason: string;
            note?: string;
        }) => {
            const { data } = await client.POST('/admin/certificates/{id}/suspend', {
                params: { path: { id: certificateId } },
                body: { reason, note },
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

export function useReinstateCertificate() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({ certificateId }: { certificateId: number }) => {
            const { data } = await client.POST('/admin/certificates/{id}/reinstate', {
                params: { path: { id: certificateId } },
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

export function useReissueCertificate() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({
            certificateId,
            body,
            idempotencyKey,
        }: {
            certificateId: number;
            body: CertificateReissueOneRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/admin/certificates/{id}/reissue', {
                params: { path: { id: certificateId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

/* ── Continuing-education credit ────────────────────────────────────── */

export function useCreditSchemes(options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: credentialKeys.creditSchemes,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/credit-schemes', { signal });
            return must(data);
        },
    });
}

export function useCourseCredits(courseId: number | null) {
    const client = useApiClient();
    return useQuery({
        enabled: courseId !== null,
        queryKey: credentialKeys.courseCredits(courseId ?? 0),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/courses/{id}/credits', {
                params: { path: { id: courseId as number } },
                signal,
            });
            return must(data);
        },
    });
}

export function useSetCourseCredits() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({ courseId, body }: { courseId: number; body: CourseCreditRequest }) => {
            const { data } = await client.PUT('/manage/courses/{id}/credits', {
                params: { path: { id: courseId } },
                body,
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

/* ── Badges, the second credential type over the same machinery ─────── */

export function useBadges(params: BadgeListParams, options: { enabled?: boolean } = {}) {
    const client = useApiClient();
    return useQuery({
        enabled: options.enabled ?? true,
        queryKey: credentialKeys.badges(params),
        placeholderData: keepPrevious,
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/badges', { params: { query: params }, signal });
            return must(data);
        },
    });
}

export function useBadgeAwards(badgeId: number | null, page = 1) {
    const client = useApiClient();
    return useQuery({
        enabled: badgeId !== null,
        queryKey: credentialKeys.badgeAwards(badgeId ?? 0, page),
        queryFn: async ({ signal }) => {
            const { data } = await client.GET('/manage/badges/{id}/awards', {
                params: { path: { id: badgeId as number }, query: { page } },
                signal,
            });
            return must(data);
        },
    });
}

export function useCreateBadge() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({ body, idempotencyKey }: { body: BadgeRequest; idempotencyKey?: string }) => {
            const { data } = await client.POST('/manage/badges', { body, headers: idempotency(idempotencyKey) });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

export function useUpdateBadge() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({ id, body }: { id: number; body: BadgeRequest }) => {
            const { data } = await client.PATCH('/manage/badges/{id}', { params: { path: { id } }, body });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

export function useArchiveBadge() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({ id }: { id: number }) => {
            await client.DELETE('/manage/badges/{id}', { params: { path: { id } } });
        },
        onSuccess: invalidate,
    });
}

export function useAwardBadge() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({
            badgeId,
            body,
            idempotencyKey,
        }: {
            badgeId: number;
            body: BadgeAwardRequest;
            idempotencyKey?: string;
        }) => {
            const { data } = await client.POST('/manage/badges/{id}/awards', {
                params: { path: { id: badgeId } },
                body,
                headers: idempotency(idempotencyKey),
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}

export function useRevokeBadgeAward() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({
        mutationFn: async ({ awardId, reason, note }: { awardId: number; reason: string; note?: string }) => {
            const { data } = await client.POST('/manage/badge-awards/{id}/revoke', {
                params: { path: { id: awardId } },
                body: { reason, note },
            });
            return must(data);
        },
        onSuccess: invalidate,
    });
}
