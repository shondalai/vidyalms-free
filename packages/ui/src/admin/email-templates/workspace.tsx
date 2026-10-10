import { useEffect, useRef, useState } from 'react';
import { Mail, ArrowUpRight } from 'lucide-react';
import { Badge, Skeleton } from '@/shared/components';
import { useNotificationTemplateDetail, type NotificationTemplate, type NotificationTemplateDetail } from './api';
import { emailTemplateStrings as s } from './strings';

export function templateLabel(value: string): string {
    const raw = value.startsWith('vidyalms.') ? value.split('.').at(-1)! : value;
    return raw.replace(/[_-]/g, ' ').replace(/^./, (first) => first.toUpperCase());
}
export function languageName(locale: string): string {
    try {
        return new Intl.DisplayNames(['en'], { type: 'language' }).of(locale) ?? locale;
    } catch {
        return locale;
    }
}
export function templateStatus(status: NotificationTemplate['status']) {
    return status === 'published'
        ? s.gallery.published
        : status === 'draft_changes'
          ? s.gallery.draftChanges
          : status === 'draft_only'
            ? s.gallery.draftOnly
            : s.gallery.notAvailable;
}
export function sampleText(value: string | null | undefined, detail?: NotificationTemplateDetail): string {
    return (value ?? '').replace(/\{([^{}]+)\}/g, (token: string, key: string) =>
        String(detail?.tokens.find((entry) => entry.key === key)?.sample ?? token),
    );
}
export function templateTone(key: string): string {
    if (/certificate|credential/.test(key)) return 'lavender';
    if (/complet|grade|feedback/.test(key)) return 'sage';
    if (/access|expir/.test(key)) return 'blue';
    if (/enrol|welcome/.test(key)) return 'peach';
    return ['peach', 'sage', 'lavender', 'blue', 'rose'][
        Array.from(key).reduce((sum, c) => sum + c.charCodeAt(0), 0) % 5
    ]!;
}

export function TemplateCard({
    item,
    disabled,
    onOpen,
}: {
    item: NotificationTemplate;
    disabled?: boolean;
    onOpen: () => void;
}) {
    const card = useRef<HTMLElement>(null);
    const [visible, setVisible] = useState(typeof IntersectionObserver === 'undefined');
    useEffect(() => {
        if (visible || !card.current) return;
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    setVisible(true);
                    observer.disconnect();
                }
            },
            { rootMargin: '120px' },
        );
        observer.observe(card.current);
        return () => observer.disconnect();
    }, [visible]);
    // Load only visible cards, using the same cached exact-identity request as the editor.
    const detail = useNotificationTemplateDetail(
        visible ? { templateKey: item.template_key, locale: item.locale, channelKey: item.channel_key } : null,
    );
    const message =
        detail.data?.draft_source ??
        detail.data?.published_source ??
        (detail.data?.legacy_published ? null : detail.data?.default_source);
    const label = templateLabel(item.label?.trim() || item.template_key);
    const status =
        item.status ?? (item.has_draft ? 'draft_changes' : item.state === 'published' ? 'published' : 'draft_only');
    return (
        <article ref={card} className="vy-email-card" aria-label={label}>
            <button
                className={`vy-email-mini ${templateTone(item.template_key)}`}
                disabled={disabled}
                onClick={onOpen}
                aria-label={s.gallery.openNamed(label)}
            >
                <span className="vy-email-paper" aria-hidden="true">
                    <span className="vy-email-mini-brand">
                        <Mail size={15} />
                        {sampleText(message?.logo_alt, detail.data) || 'VidyaLMS'}
                    </span>
                    {detail.isPending ? (
                        <Skeleton className="h-20 w-full" />
                    ) : (
                        <>
                            <strong>{sampleText(message?.heading || item.subject || label, detail.data)}</strong>
                            <span className="vy-email-mini-copy">
                                {sampleText(
                                    message?.paragraphs.slice(0, 2).join(' ') ||
                                        detail.data?.live_parts?.body_text ||
                                        s.workspace.previewUnavailable,
                                    detail.data,
                                )}
                            </span>
                            {message?.action_label && (
                                <span
                                    className="vy-email-mini-action"
                                    style={{
                                        backgroundColor: /^#[a-f\d]{6}$/i.test(message.accent_color)
                                            ? message.accent_color
                                            : undefined,
                                    }}
                                >
                                    {sampleText(message.action_label, detail.data)}
                                    <ArrowUpRight size={12} />
                                </span>
                            )}
                        </>
                    )}
                </span>
                <span className="vy-email-sample">{s.workspace.sample}</span>
            </button>
            <div className="vy-email-card-body">
                <div className="vy-email-card-title">
                    <h2>{label}</h2>
                    <Badge variant={status === 'published' ? 'ok' : status === 'draft_changes' ? 'warn' : 'neutral'}>
                        {templateStatus(status)}
                    </Badge>
                </div>
                <p>
                    {languageName(item.locale)}
                    <span> · </span>
                    {templateLabel(item.channel_key)}
                </p>
                {item.update_available && <Badge variant="accent">{s.gallery.updateAvailable}</Badge>}
                {item.available === false && (
                    <p className="vy-email-muted">{item.unavailable_reason || s.gallery.notAvailable}</p>
                )}
                {item.delivery_ready === false && <p className="vy-email-delivery-note">{s.delivery.setupNeeded}</p>}
                {item.delivery_warnings?.[0] && (
                    <p className="vy-email-delivery-note">{item.delivery_warnings[0].message}</p>
                )}
                <button disabled={disabled} className="vy-email-text-link" onClick={onOpen}>
                    {s.gallery.open}
                    <ArrowUpRight size={15} />
                </button>
            </div>
        </article>
    );
}
