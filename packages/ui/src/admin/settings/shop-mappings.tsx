import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { ArrowRight, BookOpen, Link2, Pencil, Plus, Trash2, X } from 'lucide-react';
import { translate as uiText } from '@/shared/i18n';
import { Button, Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle, Skeleton } from '@/shared/components';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { TextField } from '../components/field';
import { useDebounced } from '../hooks';

const itemSchema = z.object({
    id: z.number().int().positive(), title: z.string().nullable().optional(), sku: z.string().nullable().optional(),
    product_id: z.number().optional(), type: z.string().optional(), price_mode: z.string().nullable().optional(),
    state: z.string().nullable().optional(),
});
const mappingSchema = z.object({
    product_id: z.number(), variation_id: z.number(), courses: z.array(z.number()),
    product: itemSchema.nullable(), variation: itemSchema.nullable(), course_details: z.array(itemSchema),
});
const settingsSchema = z.object({ revision: z.number().int(), mappings: z.array(mappingSchema) });
const resultsSchema = z.object({ items: z.array(itemSchema), more: z.boolean() });
type Item = z.infer<typeof itemSchema>;
type Mapping = z.infer<typeof mappingSchema>;
type Transport = { endpoint: string; headers: Record<string, string> };
type Change = { action: 'create' | 'update' | 'remove'; expected_revision: number; product_id: number; variation_id: number; courses: number[] };

async function request({ endpoint, headers }: Transport, query: Record<string, string> = {}, body?: Change, signal?: AbortSignal) {
    const url = new URL(endpoint, window.location.href);
    Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, value));
    const response = await fetch(url.href, {
        method: body ? 'POST' : 'GET', credentials: 'same-origin', signal,
        headers: { ...headers, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const data: unknown = await response.json();
    if (!response.ok) {
        if (response.status === 409) throw new Error(uiText('vidyalms.ui.mappingConflict', 'Mappings have changed. Close this dialog and reload mappings before trying again.'));
        const problem = z.object({ detail: z.string().optional() }).safeParse(data);
        throw new Error(problem.success && problem.data.detail ? problem.data.detail : uiText('vidyalms.ui.mappingRequestError', 'Could not load or save mappings. Please try again.'));
    }
    return data;
}

function itemTitle(item: Item, variation = false): string {
    return item.title || (variation
        ? uiText('vidyalms.ui.mappingVariationName', 'Variation {p0}', { p0: item.id })
        : uiText('vidyalms.ui.mappingUnavailableName', 'Unavailable item #{p0}', { p0: item.id }));
}

/** A labelled search with ordinary buttons/checkboxes supports keyboard and screen-reader use. */
function CataloguePicker({ transport, kind, productId = 0, selected, onSelect }: {
    transport: Transport; kind: 'products' | 'variations' | 'courses'; productId?: number;
    selected: Item[]; onSelect: (item: Item) => void;
}) {
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const debounced = useDebounced(search);
    const results = useQuery({
        queryKey: ['shop-catalogue', transport.endpoint, kind, productId, debounced, page],
        queryFn: async ({ signal }) => resultsSchema.parse(await request(transport,
            { action: kind, search: debounced, page: String(page), product_id: String(productId) }, undefined, signal)),
        retry: false,
    });
    const label = kind === 'products' ? uiText('vidyalms.ui.mappingSearchProducts', 'Search products')
        : kind === 'variations' ? uiText('vidyalms.ui.mappingSearchVariations', 'Search variations by SKU')
        : uiText('vidyalms.ui.mappingSearchCourses', 'Search paid courses');
    return <div className="grid gap-3">
        <TextField type="search" label={label} value={search} onChange={(event) => {
            setSearch(event.target.value);
            setPage(1);
        }} />
        {results.isFetching || search !== debounced ? <div aria-label={uiText('vidyalms.ui.mappingLoadingOptions', 'Loading choices')} aria-busy="true"><Skeleton className="h-20 w-full" /></div>
            : results.isError ? <div role="alert"><p>{results.error.message}</p><Button variant="outline" size="sm" onClick={() => void results.refetch()}>{uiText('vidyalms.ui.mappingRetry', 'Try again')}</Button></div>
            : <>
                <div aria-live="polite" className="text-xs text-fg-2">
                    {!results.data?.items.length && (kind === 'courses'
                        ? uiText('vidyalms.ui.mappingNoPaidCourses', 'No matching paid courses. Set a course to Paid under Access → Visibility & pricing, then return here.')
                        : uiText('vidyalms.ui.mappingNoProducts', 'No matches. Create the product in your shop or try another search.'))}
                </div>
                <ul aria-label={label} className="m-0 grid max-h-52 list-none gap-1 overflow-y-auto p-0">
                    {results.data?.items.map((item) => <li key={item.id}>
                        {kind === 'courses' ? <label className="flex cursor-pointer items-start gap-3 rounded border border-border p-3 text-sm hover:bg-surface-2">
                            <input type="checkbox" className="mt-1" checked={selected.some((choice) => choice.id === item.id)} onChange={() => onSelect(item)} />
                            <span>{itemTitle(item)}</span>
                        </label> : <button type="button" className="w-full rounded border border-border p-3 text-left text-sm hover:bg-surface-2" onClick={() => onSelect(item)}>
                            <strong className="block">{itemTitle(item, kind === 'variations')}</strong>
                            <span className="text-xs text-fg-2">{item.sku ? `${item.sku} · ` : ''}#{item.id}</span>
                        </button>}
                    </li>)}
                </ul>
                {(page > 1 || results.data?.more) && <div className="flex items-center justify-between gap-2">
                    <Button variant="ghost" size="sm" disabled={page === 1} onClick={() => setPage(page - 1)}>{uiText('vidyalms.ui.mappingPrevious', 'Previous')}</Button>
                    <span className="text-xs text-fg-2">{uiText('vidyalms.ui.mappingPage', 'Page {p0}', { p0: page })}</span>
                    <Button variant="ghost" size="sm" disabled={!results.data?.more} onClick={() => setPage(page + 1)}>{uiText('vidyalms.ui.mappingNext', 'Next')}</Button>
                </div>}
            </>}
    </div>;
}

export function ShopMappings(transport: Transport) {
    const cache = useQueryClient();
    const key = ['shop-mappings', transport.endpoint];
    const query = useQuery({
        queryKey: key,
        queryFn: async ({ signal }) => settingsSchema.parse(await request(transport, {}, undefined, signal)), retry: false,
    });
    const [editor, setEditor] = useState<{ mapping: Mapping | null; revision: number } | null>(null);
    const [removal, setRemoval] = useState<{ mapping: Mapping; revision: number } | null>(null);
    const [notice, setNotice] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const mutation = useMutation({
        mutationFn: async (change: Change) => settingsSchema.parse(await request(transport, {}, change)),
        onSuccess: (data) => {
            cache.setQueryData(key, data);
            setEditor(null);
            setRemoval(null);
            setNotice(uiText('vidyalms.ui.mappingSaved', 'Product mappings saved.'));
            setPage(1);
        },
    });
    useNavigationGuard(editor !== null || removal !== null);
    const rows = (query.data?.mappings ?? []).filter((row) =>
        [row.product?.title, row.product?.sku, String(row.product_id), ...row.course_details.map((course) => course.title)].join(' ').toLowerCase().includes(search.toLowerCase()));
    const pageCount = Math.max(1, Math.ceil(rows.length / 8));
    const currentPage = Math.min(page, pageCount);
    const openEditor = (mapping: Mapping | null) => {
        mutation.reset();
        setNotice('');
        if (query.data) setEditor({ mapping, revision: query.data.revision });
    };
    return <section className="my-4 border-y border-border py-6" aria-labelledby="shop-mappings-title">
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h3 id="shop-mappings-title" className="flex items-center gap-2"><Link2 size={19} />{uiText('vidyalms.ui.mappingTitle', 'Product & course mappings')}</h3>
                <p className="mt-2 max-w-2xl text-sm text-fg-2">{uiText('vidyalms.ui.mappingIntro', 'Choose what each purchase unlocks. A product can grant access to one course or a bundle of courses.')}</p>
            </div>
            <Button disabled={!query.data || query.isError} onClick={() => openEditor(null)}><Plus size={16} />{uiText('vidyalms.ui.mappingAdd', 'Add mapping')}</Button>
        </div>
        {notice && <p role="status" className="mt-3 text-sm">{notice}</p>}
        {query.isPending ? <Skeleton className="mt-4 h-24 w-full" /> : query.isError ? <div role="alert" className="mt-4"><p>{query.error.message}</p><Button variant="outline" onClick={() => void query.refetch()}>{uiText('vidyalms.ui.mappingRetry', 'Try again')}</Button></div> : <>
            {query.data.mappings.length > 0 ? <div className="mt-5 grid gap-3">
                <TextField type="search" label={uiText('vidyalms.ui.mappingFilter', 'Find a mapping')} value={search} onChange={(event) => {
                    setSearch(event.target.value);
                    setPage(1);
                }} />
                {rows.slice((currentPage - 1) * 8, currentPage * 8).map((row) => <article key={`${row.product_id}:${row.variation_id}`} className="grid gap-4 rounded-lg border border-border bg-surface p-4 lg:grid-cols-[1fr_auto_1fr_auto] lg:items-center">
                    <div className="min-w-0">
                        <strong>{itemTitle(row.product ?? { id: row.product_id })}</strong>
                        <p className="mt-1 text-xs text-fg-2">{row.variation_id
                            ? `${itemTitle(row.variation ?? { id: row.variation_id }, true)}${row.variation?.sku ? ` · ${row.variation.sku}` : ''}`
                            : row.product?.type?.startsWith('variable')
                              ? uiText('vidyalms.ui.mappingAllVariations', 'Product default · all variations')
                              : uiText('vidyalms.ui.mappingProductPurchase', 'Product purchase')}</p>
                    </div>
                    <ArrowRight size={18} className="hidden text-fg-2 lg:block" aria-hidden="true" />
                    <ul className="m-0 grid list-none gap-1 p-0 text-sm">
                        {row.course_details.map((course) => <li key={course.id} className="flex items-start gap-2"><BookOpen size={15} className="mt-0.5 shrink-0 text-accent" /><span>{itemTitle(course)}
                            {(course.price_mode !== 'paid' || course.state === 'trashed') && <span className="block text-xs text-danger">{uiText('vidyalms.ui.mappingCourseUnavailable', 'Unavailable for new paid access. Edit this mapping.')}</span>}
                        </span></li>)}
                    </ul>
                    <div className="flex gap-1">
                        <Button variant="ghost" size="sm" onClick={() => openEditor(row)} aria-label={uiText('vidyalms.ui.mappingEditNamed', 'Edit mapping for {p0}', { p0: itemTitle(row.product ?? { id: row.product_id }) })}><Pencil size={15} />{uiText('vidyalms.ui.mappingEdit', 'Edit')}</Button>
                        <Button variant="ghost" size="sm" onClick={() => {
                            mutation.reset();
                            setRemoval({ mapping: row, revision: query.data.revision });
                        }} aria-label={uiText('vidyalms.ui.mappingRemoveNamed', 'Remove mapping for {p0}', { p0: itemTitle(row.product ?? { id: row.product_id }) })}><Trash2 size={15} /></Button>
                    </div>
                </article>)}
                {!rows.length && <p className="text-sm text-fg-2">{uiText('vidyalms.ui.mappingNoMatches', 'No mappings match your search.')}</p>}
                {pageCount > 1 && <div className="flex items-center justify-between">
                    <Button variant="outline" size="sm" disabled={currentPage === 1} onClick={() => setPage(currentPage - 1)}>{uiText('vidyalms.ui.mappingPrevious', 'Previous')}</Button>
                    <span className="text-xs">{uiText('vidyalms.ui.mappingPage', 'Page {p0}', { p0: currentPage })}</span>
                    <Button variant="outline" size="sm" disabled={currentPage === pageCount} onClick={() => setPage(currentPage + 1)}>{uiText('vidyalms.ui.mappingNext', 'Next')}</Button>
                </div>}
            </div> : <div className="mt-5 rounded-lg border border-dashed border-border p-6 text-center">
                <Link2 size={24} className="mx-auto mb-2 text-accent" />
                <strong>{uiText('vidyalms.ui.mappingEmpty', 'Connect your first product')}</strong>
                <p className="mt-2 text-sm text-fg-2">{uiText('vidyalms.ui.mappingEmptyHint', 'Your shop handles checkout. A mapping tells VidyaLMS which courses to unlock after a successful payment.')}</p>
            </div>}
            <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p className="max-w-2xl text-xs text-fg-2">{uiText('vidyalms.ui.mappingEffect', 'Mapping changes apply when purchases are first synchronized. Access already linked to a purchase keeps its saved mapping.')}</p>
                <Button variant="ghost" size="sm" disabled={query.isFetching} onClick={() => void query.refetch()}>{uiText('vidyalms.ui.mappingReload', 'Reload mappings')}</Button>
            </div>
        </>}
        <Dialog open={editor !== null} onOpenChange={(open) => { if (!open && !mutation.isPending) setEditor(null); }}>
            {editor && <DialogContent className="max-w-2xl" showClose={!mutation.isPending} onInteractOutside={(event) => event.preventDefault()}>
                <MappingForm transport={transport} mapping={editor.mapping} pending={mutation.isPending} error={mutation.error?.message}
                    onCancel={() => setEditor(null)} onSave={(product, variation, courses) => mutation.mutate({
                        action: editor.mapping ? 'update' : 'create', expected_revision: editor.revision, product_id: product, variation_id: variation, courses,
                    })} />
            </DialogContent>}
        </Dialog>
        <Dialog open={removal !== null} onOpenChange={(open) => { if (!open && !mutation.isPending) setRemoval(null); }}>
            {removal && <DialogContent showClose={!mutation.isPending}>
                <DialogTitle>{uiText('vidyalms.ui.mappingRemoveTitle', 'Remove this mapping?')}</DialogTitle>
                <DialogDescription>{uiText('vidyalms.ui.mappingRemoveHint', 'Future purchases will no longer use this connection. Existing purchase access and learning progress are preserved.')}</DialogDescription>
                {mutation.error && <p role="alert" className="mt-3 text-sm text-danger">{mutation.error.message}</p>}
                <DialogFooter>
                    <Button variant="outline" disabled={mutation.isPending} onClick={() => setRemoval(null)}>{uiText('vidyalms.ui.mappingCancel', 'Cancel')}</Button>
                    <Button variant="destructive" disabled={mutation.isPending} onClick={() => mutation.mutate({
                        action: 'remove', expected_revision: removal.revision, product_id: removal.mapping.product_id, variation_id: removal.mapping.variation_id, courses: [],
                    })}>{uiText('vidyalms.ui.mappingRemove', 'Remove mapping')}</Button>
                </DialogFooter>
            </DialogContent>}
        </Dialog>
    </section>;
}

function MappingForm({ transport, mapping, pending, error, onCancel, onSave }: {
    transport: Transport; mapping: Mapping | null; pending: boolean; error?: string; onCancel: () => void;
    onSave: (product: number, variation: number, courses: number[]) => void;
}) {
    const [product, setProduct] = useState<Item | null>(mapping ? mapping.product ?? { id: mapping.product_id } : null);
    const [variation, setVariation] = useState<Item | null>(
        mapping?.variation_id ? mapping.variation ?? { id: mapping.variation_id } : null,
    );
    const [specific, setSpecific] = useState(Boolean(mapping?.variation_id));
    const [courses, setCourses] = useState<Item[]>(mapping?.course_details ?? []);
    const toggleCourse = (course: Item) => setCourses((current) => current.some((item) => item.id === course.id)
        ? current.filter((item) => item.id !== course.id) : [...current, course]);
    return <form onSubmit={(event) => {
        event.preventDefault();
        if (product && courses.length && (!specific || variation)) {
            onSave(product.id, specific ? variation!.id : 0, courses.map((course) => course.id));
        }
    }}>
        <DialogTitle>{mapping ? uiText('vidyalms.ui.mappingEditTitle', 'Edit product mapping') : uiText('vidyalms.ui.mappingCreateTitle', 'Connect a product to learning')}</DialogTitle>
        <DialogDescription>{uiText('vidyalms.ui.mappingFormHint', 'Select the shop product and the paid courses it unlocks. Prices and billing stay in your shop.')}</DialogDescription>
        <fieldset disabled={pending} className="mt-5 grid min-w-0 gap-5 border-0 p-0">
            <div className="grid gap-3">
                <strong className="text-sm">{uiText('vidyalms.ui.mappingProductStep', '1. Shop product')}</strong>
                {product ? <div className="flex items-center justify-between gap-3 rounded-lg border border-border p-3 text-sm">
                    <span><strong>{itemTitle(product)}</strong><span className="block text-xs text-fg-2">{product.sku ? `${product.sku} · ` : ''}#{product.id}</span></span>
                    {!mapping && <Button size="sm" variant="ghost" onClick={() => {
                        setProduct(null);
                        setVariation(null);
                        setSpecific(false);
                    }}>{uiText('vidyalms.ui.mappingChange', 'Change')}</Button>}
                </div> : <CataloguePicker transport={transport} kind="products" selected={[]} onSelect={setProduct} />}
                {product && (specific || product.type?.startsWith('variable')) && <>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={specific} disabled={mapping !== null} onChange={(event) => {
                        setSpecific(event.target.checked);
                        setVariation(null);
                    }} />{uiText('vidyalms.ui.mappingSpecificVariation', 'Apply to one variation only')}</label>
                    <p className="text-xs text-fg-2">{uiText('vidyalms.ui.mappingVariationHint', 'The product default covers all variations. A variation mapping replaces that default for its purchases.')}</p>
                    {specific && (variation ? <div className="flex items-center justify-between gap-3 rounded border border-border p-3 text-sm"><span>{itemTitle(variation, true)}{variation.sku ? ` · ${variation.sku}` : ''}</span>{!mapping && <Button size="sm" variant="ghost" onClick={() => setVariation(null)}>{uiText('vidyalms.ui.mappingChange', 'Change')}</Button>}</div>
                        : <CataloguePicker key={product.id} transport={transport} kind="variations" productId={product.id} selected={[]} onSelect={setVariation} />)}
                </>}
            </div>
            <div className="grid gap-3 border-t border-border pt-4">
                <strong className="text-sm">{uiText('vidyalms.ui.mappingCoursesStep', '2. Courses to unlock')}</strong>
                {courses.length > 0 && <ul className="m-0 flex list-none flex-wrap gap-2 p-0" aria-label={uiText('vidyalms.ui.mappingSelectedCourses', 'Selected courses')}>
                    {courses.map((course) => <li key={course.id} className="flex items-center gap-2 rounded bg-surface-2 px-3 py-2 text-xs"><span>{itemTitle(course)}</span><button type="button" onClick={() => toggleCourse(course)} aria-label={uiText('vidyalms.ui.mappingDeselect', 'Deselect {p0}', { p0: itemTitle(course) })}><X size={14} /></button></li>)}
                </ul>}
                <CataloguePicker transport={transport} kind="courses" selected={courses} onSelect={toggleCourse} />
            </div>
        </fieldset>
        {error && <p role="alert" className="mt-3 text-sm text-danger">{error}</p>}
        <DialogFooter>
            <Button variant="outline" disabled={pending} onClick={onCancel}>{uiText('vidyalms.ui.mappingCancel', 'Cancel')}</Button>
            <Button type="submit" disabled={pending || !product || !courses.length || courses.length > 50 || (specific && !variation)}>{pending ? uiText('vidyalms.ui.mappingSaving', 'Saving…') : uiText('vidyalms.ui.mappingSave', 'Save mapping')}</Button>
        </DialogFooter>
    </form>;
}
