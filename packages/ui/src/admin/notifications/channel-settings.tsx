import { translate as uiText } from '@/shared/i18n';
import { useEffect, useId, useRef, useState } from 'react';
import type { components } from '@vidyalms/api-sdk';
import { Bell, Mail, MessageSquare, Radio, Settings2, Webhook, ArrowUpRight } from 'lucide-react';
import { Badge, Button, ErrorState, Skeleton } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { useRegistryProviders, useSetProviderSettings } from '@/shared/flow';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { SelectField, TextField } from '../components/field';
import { useAdminConfig } from '../config';
import { channelStrings as s } from './channel-strings';

type Provider = components['schemas']['RegistryProvider'];
type Field = components['schemas']['SettingsField'];
type Draft = { params: Record<string, unknown>; enabled: boolean; secret: string };

const channels: Record<string, { label: string; description: string; icon: typeof Bell }> = {
    email: { label: s.email, description: s.emailHelp, icon: Mail },
    in_app: { label: s.in_app, description: s.in_appHelp, icon: Bell },
    sms: { label: s.sms, description: s.smsHelp, icon: MessageSquare },
    webhook: { label: s.webhook, description: s.webhookHelp, icon: Webhook },
    push: { label: s.push, description: s.pushHelp, icon: Radio },
};

export function notificationChannelLabel(key: string): string {
    return channels[key]?.label ?? key.replaceAll('_', ' ');
}

function seed(provider: Provider): Draft {
    return {
        params: Object.fromEntries(provider.groups.flatMap((group) => group.fields.map((field) => [
            field.key, field.write_only ? '' : field.current ?? field.default ?? null,
        ]))),
        enabled: provider.enabled !== false,
        secret: '',
    };
}

function SettingInput({ field, value, onChange, disabled, required }: {
    field: Field; value: unknown; onChange: (value: unknown) => void; disabled: boolean; required: boolean;
}) {
    const id = useId();
    const common = { label: field.label, hint: field.description ?? undefined, disabled, required };
    if (field.type === 'boolean') return <label className="vy-notification-boolean" htmlFor={id}>
        <input id={id} type="checkbox" role="switch" checked={value === true} disabled={disabled}
            onChange={(event) => onChange(event.target.checked)} />
        <span><strong>{field.label}</strong>{field.description && <small>{field.description}</small>}</span>
    </label>;
    if (field.type === 'enum') return <SelectField {...common} value={String(value ?? '')}
        onChange={(event) => onChange(event.target.value)}>
        <option value="" />
        {field.options?.map((option) => <option key={option.key} value={option.key}>{option.label}</option>)}
    </SelectField>;
    const numeric = field.type === 'integer' || field.type === 'number' || field.type === 'duration';
    return <TextField {...common}
        type={field.write_only ? 'password' : numeric ? 'number' : field.key.endsWith('_url') ? 'url' : 'text'}
        min={field.minimum ?? undefined} max={field.maximum ?? undefined}
        step={field.type === 'number' ? 'any' : numeric ? 1 : undefined}
        pattern={field.key.endsWith('_secret_ref') ? '(env|store):[^\\s]+' : undefined}
        value={value === null || value === undefined ? '' : typeof value === 'object' ? JSON.stringify(value) : String(value)}
        onChange={(event) => onChange(numeric ? event.target.value === '' ? null : Number(event.target.value) : event.target.value)} />;
}

function ProviderCard({ provider, registry, channel }: { provider: Provider; registry: string; channel: boolean }) {
    const save = useSetProviderSettings();
    const announce = useAnnounce();
    const id = useId();
    const [base, setBase] = useState(() => seed(provider));
    const [draft, setDraft] = useState(base);
    const [error, setError] = useState<string>();
    const [saved, setSaved] = useState(false);
    const dirty = JSON.stringify(base) !== JSON.stringify(draft);
    useNavigationGuard(dirty);
    const previousProvider = useRef(provider);
    useEffect(() => {
        if (previousProvider.current === provider) return;
        previousProvider.current = provider;
        if (!dirty && !save.isPending) {
            const next = seed(provider);
            setBase(next);
            setDraft(next);
        }
    }, [provider, dirty, save.isPending]);
    const display = channel ? channels[provider.key] : undefined;
    const title = display?.label ?? provider.label;
    const Icon = display?.icon ?? Settings2;
    const fields = provider.groups.flatMap((group) => group.fields);
    const needsSetup = fields.some((field) => field.required && (draft.params[field.key] === '' || draft.params[field.key] == null));
    const feedback = channel && ['email', 'webhook', 'sms'].includes(provider.key);
    const change = (next: Draft) => {
        setDraft(next);
        setSaved(false);
        setError(undefined);
    };
    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        if (channel && draft.enabled && needsSetup) {
            setError(s.required);
            return;
        }
        const params = { ...draft.params };
        try {
            for (const field of fields) {
                if (field.write_only && !params[field.key]) delete params[field.key];
                if (field.type === 'object' && typeof params[field.key] === 'string') {
                    const value: unknown = JSON.parse(params[field.key] as string);
                    if (value === null || typeof value !== 'object' || Array.isArray(value)) throw new Error();
                    params[field.key] = value;
                }
            }
        } catch {
            setError(s.jsonError);
            return;
        }
        save.mutate({ registryKey: registry, providerKey: provider.key,
            body: { scope_type: 'site', scope_id: 0, params,
                ...(channel ? { enabled: draft.enabled } : {}),
                ...(draft.secret.trim() ? { secret_ref: draft.secret.trim() } : {}),
            },
        }, { onSuccess: (response) => {
            const stored = response.providers.find((item) => item.key === provider.key);
            const next = stored ? seed(stored) : { ...draft, secret: '' };
            setBase(next);
            setDraft(next);
            setSaved(true);
            announce(s.saved);
        } });
    };
    return <form className="vy-notification-card" onSubmit={submit} aria-labelledby={`${id}-title`}
        onInvalidCapture={() => document.getElementById(`${id}-config`)?.setAttribute('open', '')}>
        <div className="vy-notification-card-heading">
            <span className="vy-notification-icon"><Icon size={21} /></span>
            <div className="vy-notification-card-title"><h3 id={`${id}-title`}>{title}</h3>
                <Badge variant={provider.available === false ? 'warn' : needsSetup ? 'warn' : draft.enabled ? 'ok' : 'neutral'}>
                    {provider.available === false ? s.unavailable : channel && !draft.enabled ? s.disabled
                        : needsSetup ? s.setupNeeded : channel ? s.enabled : s.systemManaged}
                </Badge>
            </div>
            {channel && <label className="vy-notification-switch" title={s.enable}>
                <input type="checkbox" role="switch" aria-label={uiText("vidyalms.ui.07e3a63ea0ed5b58", "{p0}: {p1}", { p0: s.enable, p1: title })}
                    checked={draft.enabled} disabled={save.isPending || provider.available === false}
                    onChange={(event) => change({ ...draft, enabled: event.target.checked })} />
                <span aria-hidden="true" />
            </label>}
        </div>
        <p className="vy-notification-card-description">{display?.description ?? provider.description ?? provider.groups.find((group) => group.description)?.description ?? (channel ? s.otherHelp : '')}</p>
        {channel && !draft.enabled && dirty && <p className="vy-notification-notice">{s.disabledHelp}</p>}
        {(fields.length > 0 || feedback) ? <details id={`${id}-config`} className="vy-notification-config" open={channel ? undefined : true}>
            <summary><Settings2 size={15} />{s.configure}</summary>
            <div className="vy-notification-fields">
                {provider.groups.map((group) => <fieldset key={group.key}>
                    <legend>{group.label}</legend>
                    {group.description && <p>{group.description}</p>}
                    <div className="vy-notification-field-grid">{group.fields.map((field) => <SettingInput key={field.key} field={field}
                        value={draft.params[field.key]} disabled={save.isPending}
                        required={field.required === true && (!channel || draft.enabled)}
                        onChange={(value) => change({ ...draft, params: { ...draft.params, [field.key]: value } })} />)}</div>
                </fieldset>)}
                {feedback && <TextField label={s.feedbackReference} hint={uiText("vidyalms.ui.8cbaa55a52ef61e2", "{p0} {p1}", { p0: s.referenceHelp, p1: provider.secret_ref_set ? s.referenceSet : s.referenceMissing })}
                    type="password" autoComplete="new-password" pattern="(env|store):[^\s]+" value={draft.secret}
                    disabled={save.isPending} onChange={(event) => change({ ...draft, secret: event.target.value })} />}
            </div>
        </details> : <p className="vy-notification-muted">{channel ? s.noSettings : s.safeguards}</p>}
        {(dirty || saved || save.isError) && <footer className="vy-notification-save">
            <span role="status">{dirty ? s.unsaved : saved ? s.saved : ''}</span>
            {dirty && <><Button type="button" variant="outline" size="sm" disabled={save.isPending}
                onClick={() => {
                    setDraft(base);
                    setError(undefined);
                    save.reset();
                }}>{s.reset}</Button>
            <Button type="submit" size="sm" disabled={save.isPending || provider.available === false}>{s.save}</Button></>}
        </footer>}
        {error && <p role="alert" className="text-danger">{error}</p>}
        {save.isError && <ErrorState error={save.error} />}
    </form>;
}

export function NotificationSetupLinks() {
    const config = useAdminConfig();
    const links = [
        { title: s.rules, description: s.rulesHelp, action: s.courses, url: config.urls.screens.courses },
        { title: s.templates, description: s.templatesHelp, action: s.templates, url: config.urls.screens['email-templates'] },
        { title: s.automations, description: s.automationsHelp, action: s.automations, url: config.urls.screens.automations },
        { title: s.learningSettings, description: s.learningSettingsHelp, action: s.openSettings, url: config.urls.screens.settings },
    ];
    return <div className="vy-notification-links">{links.filter((link) => link.url).map((link) => <a key={link.title} href={link.url}>
        <strong>{link.title}<ArrowUpRight size={16} /></strong><span>{link.description}</span>
        <small>{link.action}</small>
    </a>)}</div>;
}

export function NotificationChannelsPanel({ controls = false }: { controls?: boolean }) {
    const registry = controls ? 'NotificationPolicy' : 'Notification';
    const query = useRegistryProviders(registry, 'site', 0);
    const providers = query.data?.providers ?? [];
    return <section className="vy-notification-settings">
        <p>{controls ? s.controlsIntro : s.channelsIntro}</p>
        {controls && <p className="vy-notification-muted">{s.safeguards}</p>}
        {query.isPending && <Skeleton className="h-48" />}
        {query.isError && <ErrorState error={query.error} onRetry={() => void query.refetch()} />}
        {!query.isPending && !query.isError && providers.length === 0 && <p>{s.empty}</p>}
        <div className="vy-notification-card-list">{providers.filter((provider) => !controls || provider.groups.length > 0)
            .map((provider) => <ProviderCard key={provider.key} provider={provider} registry={registry} channel={!controls} />)}</div>
        {!controls && <NotificationSetupLinks />}
    </section>;
}

export function NotificationDeliveryControlsPanel() {
    return <NotificationChannelsPanel controls />;
}
