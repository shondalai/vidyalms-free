import { translate as uiText } from '@/shared/i18n';
import { useEffect, useState } from 'react';
import { ExternalLink, Mail } from 'lucide-react';
import { emailTemplateStudioUrl } from '@/admin/email-templates/routes';
import { emailTemplateStrings } from '@/admin/email-templates/strings';
import { Icon, buttonVariants } from '@/shared/components';
import { Field, Select } from '../../components/field';
import { useInstructorConfig } from '../../config';

/** Placeholders written as {name} anywhere in a structured text field. */
export function placeholdersIn(text: string): string[] {
    const found = new Set<string>();
    const pattern = /\{([a-zA-Z0-9_.]+)\}/g;
    let match = pattern.exec(text);
    while (match !== null) {
        if (match[1] !== undefined) {
            found.add(match[1]);
        }
        match = pattern.exec(text);
    }
    return [...found];
}

/** Every placeholder used that the trigger does not offer. Retained for imported legacy validation. */
export function unknownPlaceholders(parts: readonly string[], declared: readonly string[]): string[] {
    const offered = new Set(declared);
    const used = new Set<string>();
    for (const part of parts) {
        for (const name of placeholdersIn(part)) {
            if (!offered.has(name)) {
                used.add(name);
            }
        }
    }
    return [...used];
}

/**
 * A rule chooses a template identity but never edits raw message parts.
 * Authoring belongs to the shared structured studio where HTML is compiled
 * and previewed by the server. The adapter owns the Joomla/WordPress URL.
 */
export function TemplateEditor({
    templateKey,
    channelKeys,
    locale,
}: {
    templateKey: string;
    channelKeys: readonly string[];
    locale: string;
}) {
    const config = useInstructorConfig();
    const [channel, setChannel] = useState(channelKeys[0] ?? 'email');
    const studio = emailTemplateStrings.link;

    useEffect(() => {
        if (!channelKeys.includes(channel)) {
            setChannel(channelKeys[0] ?? 'email');
        }
    }, [channel, channelKeys]);

    if (templateKey === '') {
        return null;
    }

    const base = config.urls.emailTemplates;
    const href = base === undefined
        ? null
        : emailTemplateStudioUrl(base, { templateKey, locale, channelKey: channel });

    return (
        <section className="grid gap-3 rounded border border-border bg-bg p-3">
            <div className="flex items-start gap-3">
                <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded bg-accent-soft text-accent">
                    <Icon icon={Mail} size={17} />
                </span>
                <div className="min-w-0 flex-1">
                    <h3 className="font-display text-xs font-semibold text-fg">{studio.title}</h3>
                    <p className="mt-0.5 text-[11.5px] text-fg-2">{studio.body}</p>
                </div>
            </div>
            <div className="flex flex-wrap items-end gap-2">
                <Field className="min-w-[180px] max-w-[240px]" label={uiText("vidyalms.ui.ce4683e7013a18cd", "Channel")}>
                    {({ id }) => (
                        <Select id={id} value={channel} onChange={(event) => setChannel(event.target.value)}>
                            {channelKeys.map((key) => <option key={key} value={key}>{key}</option>)}
                        </Select>
                    )}
                </Field>
                {href === null ? (
                    <p role="status" className="text-[11.5px] text-fg-2">{studio.unavailable}</p>
                ) : (
                    <a className={buttonVariants({ variant: 'outline', size: 'sm' })} href={href}>
                        {studio.open}
                        <Icon icon={ExternalLink} size={14} />
                    </a>
                )}
            </div>
        </section>
    );
}
