import { Button, Card, EmptyState, ErrorState, Skeleton, buttonVariants } from '@/shared/components';
import { useAnnounce } from '@/shared/a11y';
import { useUnsubscribe, useUnsubscribePreview } from './api';
import { useNotificationConfig } from './config';
import { notificationStrings as strings } from './strings';

/*
 * The unsubscribe confirmation.
 *
 * THE PAGE ITSELF ACTS ON NOTHING. It reads the token, says what pressing
 * the button would stop, and waits. Mail security scanners follow links,
 * which is the whole reason the one-click mechanism is a POST; a page that
 * unsubscribed on load would turn a scanner into an opt-out and the person
 * would never know.
 *
 * Nothing beyond the token is demanded and nothing is charged: no sign-in,
 * no address to retype, no reason to give. A person who cannot be identified
 * still gets an honest refusal and a way to reach their settings.
 *
 * The outcome names what KEEPS arriving, because an unsubscribe from one
 * kind of message is not a block on an address: a certificate mail is
 * transactional and a site may be obliged to send it.
 */

export function UnsubscribePage() {
    const config = useNotificationConfig();
    const token = config.token;
    const preview = useUnsubscribePreview(token);
    const act = useUnsubscribe();
    const announce = useAnnounce();

    if (token === '') {
        return (
            <Card className="p-4">
                <EmptyState
                    title={strings.unsubscribe.title}
                    description={strings.unsubscribe.noToken}
                    action={
                        config.urls.preferences === '' ? undefined : (
                            <a
                                className={buttonVariants({ variant: 'outline', size: 'sm' })}
                                href={config.urls.preferences}
                            >
                                {strings.unsubscribe.managePreferences}
                            </a>
                        )
                    }
                />
            </Card>
        );
    }

    if (preview.isPending) {
        return (
            <Card className="p-4" aria-busy="true">
                <Skeleton className="h-4 w-40" />
                <Skeleton className="mt-3 h-16 w-full" />
            </Card>
        );
    }

    if (preview.isError) {
        /* A token that does not verify, or one older than the site accepts.
           The page says so and offers the settings page, because refusing
           without a way forward is what makes a person mark mail as spam. */
        return (
            <Card className="p-4">
                <EmptyState
                    title={strings.unsubscribe.invalidTitle}
                    description={strings.unsubscribe.invalidBody}
                    action={
                        config.urls.preferences === '' ? undefined : (
                            <a
                                className={buttonVariants({ variant: 'outline', size: 'sm' })}
                                href={config.urls.preferences}
                            >
                                {strings.unsubscribe.managePreferences}
                            </a>
                        )
                    }
                />
            </Card>
        );
    }

    const detail = preview.data;
    const outcome = act.data ?? null;

    return (
        <Card>
            <div className="border-b border-border p-4">
                <h2 className="font-display text-sm font-bold text-fg">{strings.unsubscribe.title}</h2>
                <p className="mt-1 text-[13px] text-fg">
                    {outcome !== null
                        ? strings.unsubscribe.done(detail.category_label)
                        : detail.already_unsubscribed
                          ? strings.unsubscribe.already
                          : strings.unsubscribe.intro(detail.category_label)}
                </p>
                {outcome === null && !detail.already_unsubscribed && (
                    <p className="mt-1 text-[12.5px] text-fg-2">{strings.unsubscribe.nothingYet}</p>
                )}
                {detail.channels.length > 0 && (
                    <p className="mt-1 text-[12px] text-fg-2">
                        {strings.unsubscribe.channels}: {detail.channels.join(', ')}
                    </p>
                )}
            </div>

            {(detail.keeps_receiving ?? []).length > 0 && (
                <div className="border-b border-border p-4">
                    <p className="font-display text-[11.5px] font-semibold text-fg">{strings.unsubscribe.keeps}</p>
                    <p className="mt-0.5 text-[12px] text-fg-2">{strings.unsubscribe.keepsHint}</p>
                    <ul className="mt-1 grid gap-0.5">
                        {(detail.keeps_receiving ?? []).map((entry) => (
                            <li key={entry} className="text-[12.5px] text-fg-2">
                                {entry}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {outcome !== null && outcome.still_receiving.length > 0 && (
                <div className="border-b border-border p-4">
                    <p className="font-display text-[11.5px] font-semibold text-fg">
                        {strings.unsubscribe.stillReceiving}
                    </p>
                    <ul className="mt-1 grid gap-0.5">
                        {outcome.still_receiving.map((entry) => (
                            <li key={entry} className="text-[12.5px] text-fg-2">
                                {entry}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="flex flex-wrap items-center gap-3 p-4">
                {outcome === null && !detail.already_unsubscribed && (
                    <Button
                        disabled={act.isPending}
                        onClick={() =>
                            act.mutate(
                                { token },
                                {
                                    onSuccess: () =>
                                        announce(strings.unsubscribe.done(detail.category_label), 'assertive'),
                                },
                            )
                        }
                    >
                        {act.isPending ? strings.unsubscribe.working : strings.unsubscribe.confirm}
                    </Button>
                )}
                {config.urls.preferences !== '' && (
                    <a
                        className={buttonVariants({ variant: 'outline', size: 'sm' })}
                        href={config.urls.preferences}
                    >
                        {strings.unsubscribe.managePreferences}
                    </a>
                )}
                {config.urls.home !== '' && (
                    <a className={buttonVariants({ variant: 'ghost', size: 'sm' })} href={config.urls.home}>
                        {strings.unsubscribe.home}
                    </a>
                )}
                {act.isError && <ErrorState error={act.error} />}
            </div>
        </Card>
    );
}
