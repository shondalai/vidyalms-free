import { useState } from 'react';
import { Mail } from 'lucide-react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
    EmptyState,
    ErrorState,
    Pagination,
    Skeleton,
} from '@/shared/components';
import { useNotificationTemplatePage } from '../email-templates/api';
import { useDebounced } from '../hooks';
import { readable } from './workspace';
import { automationStrings as s } from './strings';

export function AutomationTemplatePicker({
    triggerKey,
    value,
    onChange,
}: {
    triggerKey: string;
    value: Record<string, unknown>;
    onChange: (params: Record<string, unknown>) => void;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [matching, setMatching] = useState(true);
    const debounced = useDebounced(search);
    const templates = useNotificationTemplatePage(
        {
            search: debounced || undefined,
            trigger_key: matching ? triggerKey || undefined : undefined,
            page,
            per_page: 12,
        },
        { enabled: open },
    );
    return (
        <>
            <Button variant="outline" className="justify-self-start" onClick={() => setOpen(true)}>
                <Mail size={15} />
                {s.workspace.chooseTemplate}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="vy-auto-dialog">
                    <DialogTitle>{s.workspace.chooseTemplate}</DialogTitle>
                    <DialogDescription>{s.workspace.chooseTemplateHint}</DialogDescription>
                    <input
                        type="search"
                        aria-label={s.workspace.searchTemplates}
                        placeholder={s.workspace.searchTemplates}
                        value={search}
                        onChange={(e) => {
                            setSearch(e.target.value);
                            setPage(1);
                        }}
                    />
                    <label className="vy-auto-check">
                        <input
                            type="checkbox"
                            checked={matching}
                            onChange={(e) => {
                                setMatching(e.target.checked);
                                setPage(1);
                            }}
                        />
                        {s.workspace.matchingTemplates}
                    </label>
                    {templates.isError ? (
                        <ErrorState error={templates.error} onRetry={() => void templates.refetch()} />
                    ) : templates.isPending ? (
                        <Skeleton className="h-40 w-full" />
                    ) : (
                        <>
                            <ul className="vy-auto-picker-list">
                                {templates.data.items.map((item) => (
                                    <li key={`${item.template_key}:${item.locale}:${item.channel_key}`}>
                                        <Button
                                            variant="ghost"
                                            disabled={
                                                templates.isFetching ||
                                                search !== debounced ||
                                                item.available === false ||
                                                item.state !== 'published'
                                            }
                                            onClick={() => {
                                                const next = {
                                                    ...value,
                                                    template_key: item.template_key,
                                                    channel_key: item.channel_key,
                                                };
                                                onChange({
                                                    ...next,
                                                    ...(item.category_key ? { category_key: item.category_key } : {}),
                                                    ...(item.class ? { class: item.class } : {}),
                                                });
                                                setOpen(false);
                                            }}
                                        >
                                            <span>
                                                {readable(item.label || item.template_key)}
                                                <small>
                                                    {item.template_key} · {item.locale} · {readable(item.channel_key)}
                                                </small>
                                                {(item.available === false || item.state !== 'published') && (
                                                    <small>{s.workspace.templateUnavailable}</small>
                                                )}
                                            </span>
                                            {value.template_key === item.template_key &&
                                                value.channel_key === item.channel_key &&
                                                '✓'}
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            {!templates.data.items.length && <EmptyState title={s.workspace.noTemplates} />}
                            <Pagination
                                label={s.workspace.chooseTemplate}
                                page={page}
                                perPage={12}
                                total={templates.data.total}
                                onPageChange={setPage}
                            />
                        </>
                    )}
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        {s.editor.cancel}
                    </Button>
                </DialogContent>
            </Dialog>
        </>
    );
}
