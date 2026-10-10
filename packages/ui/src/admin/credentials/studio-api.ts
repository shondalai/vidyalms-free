import { useMutation, useQuery } from '@tanstack/react-query';
import type { CertificateBlueprintCloneRequest, CertificateCloneRequest, CertificateDefaultRequest,
    CertificatePublishRequest, CourseCertificatePolicyRequest } from '@vidyalms/api-sdk';
import { useApiClient } from '@/shared/context';
import { must } from '@/shared/manage';
import { credentialKeys, useCredentialInvalidation, useTemplateWriteSuccess } from './api';

export function useCertificateBlueprints() {
    const client = useApiClient();
    return useQuery({ queryKey: [...credentialKeys.root, 'blueprints'], queryFn: async ({ signal }) => {
        const { data } = await client.GET('/manage/certificate-blueprints', { signal });
        return must(data);
    } });
}

export function usePublishCertificateTemplate() {
    const client = useApiClient();
    const saved = useTemplateWriteSuccess();
    return useMutation({ mutationFn: async ({ id, body, idempotencyKey }: {
        id: number; body: CertificatePublishRequest; idempotencyKey: string;
    }) => {
        const { data } = await client.POST('/manage/certificate-templates/{id}/publish', {
            params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } }, body,
        });
        return must(data);
    }, onSuccess: (template) => saved(template.id, template.revision, () => template) });
}

export function useDefaultCertificateTemplate() {
    const client = useApiClient();
    const saved = useTemplateWriteSuccess();
    return useMutation({ mutationFn: async ({ id, body, idempotencyKey }: {
        id: number; body: CertificateDefaultRequest; idempotencyKey: string;
    }) => {
        const { data } = await client.POST('/manage/certificate-templates/{id}/default', {
            params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } }, body,
        });
        return must(data);
    }, onSuccess: (template) => saved(template.id, template.revision, () => template) });
}

export function useCloneCertificateBlueprint() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({ mutationFn: async ({ key, body, idempotencyKey }: {
        key: string; body: CertificateBlueprintCloneRequest; idempotencyKey: string;
    }) => {
        const { data } = await client.POST('/manage/certificate-blueprints/{key}/clone', {
            params: { path: { key }, header: { 'Idempotency-Key': idempotencyKey } }, body,
        });
        return must(data);
    }, onSuccess: invalidate });
}

export function useCloneCertificateTemplate() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({ mutationFn: async ({ id, body, idempotencyKey }: {
        id: number; body: CertificateCloneRequest; idempotencyKey: string;
    }) => {
        const { data } = await client.POST('/manage/certificate-templates/{id}/clone', {
            params: { path: { id }, header: { 'Idempotency-Key': idempotencyKey } }, body,
        });
        return must(data);
    }, onSuccess: invalidate });
}

export function useCourseCertificatePolicy(courseId: number) {
    const client = useApiClient();
    return useQuery({ queryKey: [...credentialKeys.root, 'course-policy', courseId], queryFn: async ({ signal }) => {
        const { data } = await client.GET('/manage/courses/{id}/certificate-policy', { params: { path: { id: courseId } }, signal });
        return must(data);
    } });
}

export function useCourseCertificateChoices(courseId: number, search: string, page: number) {
    const client = useApiClient();
    return useQuery({ queryKey: [...credentialKeys.root, 'course-choices', courseId, search, page], queryFn: async ({ signal }) => {
        const { data } = await client.GET('/manage/courses/{id}/certificate-templates', {
            params: { path: { id: courseId }, query: { search: search || undefined, page, per_page: 10 } }, signal,
        });
        return must(data);
    } });
}

export function useSaveCourseCertificatePolicy() {
    const client = useApiClient();
    const invalidate = useCredentialInvalidation();
    return useMutation({ mutationFn: async ({ courseId, body, idempotencyKey }: {
        courseId: number; body: CourseCertificatePolicyRequest; idempotencyKey: string;
    }) => {
        const { data } = await client.PUT('/manage/courses/{id}/certificate-policy', {
            params: { path: { id: courseId }, header: { 'Idempotency-Key': idempotencyKey } }, body,
        });
        return must(data);
    }, onSuccess: invalidate });
}
