import { translate as uiText } from '@/shared/i18n';
import { useState, type ReactNode } from 'react';
import { QueryClientProvider } from '@tanstack/react-query';
import { ApiClientContext, MountElContext } from '@/shared/context';
import { createAppQueryClient } from '@/shared/query';
import { Button } from '@/shared/components';
import { AdminConfigContext } from '../config';
import type { AdminScreenRuntime } from '../screens/registry';
import { QuestionBankForm } from './bank-form';
import { QuestionBankList } from './bank-list';
import { QuestionEditor } from './question-editor';
import { QuestionList } from './question-list';
import { parseQuestionBankRoute } from './routes';
import { bankStrings as strings } from './strings';
import { useQuestionBank } from '@/instructor/assessment-api';
import { ResourceLifecycleActions } from '../components/resource-lifecycle-actions';

function RuntimeProviders({ runtime, children }: { runtime: AdminScreenRuntime; children: ReactNode }) {
    /* A contributed IIFE has its own context identities. Seed those local
       providers from the validated runtime the free shell hands it rather
       than trying to read the free bundle's private contexts. */
    const [queryClient] = useState(createAppQueryClient);
    return (
        <MountElContext.Provider value={runtime.mountEl}>
            <AdminConfigContext.Provider value={runtime.config}>
                <ApiClientContext.Provider value={runtime.client}>
                    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
                </ApiClientContext.Provider>
            </AdminConfigContext.Provider>
        </MountElContext.Provider>
    );
}

function RoutedQuestionBanks({ runtime }: { runtime: AdminScreenRuntime }) {
    const route = parseQuestionBankRoute(runtime.hash);
    const bank = useQuestionBank('bankId' in route ? route.bankId : null);
    if (bank.data?.state === 'trashed') {
        return <section className="rounded-lg border border-border bg-surface p-6">
            <h1 className="font-display text-xl font-semibold text-fg">{bank.data.name} {uiText("vidyalms.ui.54fd722030db310e", " is in Trash")}</h1>
            <p className="my-4 text-sm text-fg-2">{uiText("vidyalms.ui.94484c0cd81fad40", "Restore this bank as unpublished before editing its questions.")}</p>
            <div className="flex items-center gap-2">
                <Button variant="outline" onClick={() => runtime.navigate('/?state=trashed')}>{uiText("vidyalms.ui.519342c9099cf565", "View Trash")}</Button>
                <ResourceLifecycleActions kind="bank" id={bank.data.id} name={bank.data.name} state="trashed"
                    onChanged={() => runtime.navigate(`/banks/${bank.data?.id}`)} />
            </div>
        </section>;
    }
    switch (route.kind) {
        case 'banks':
            return <QuestionBankList query={route.query} navigate={runtime.navigate} replace={runtime.replace} />;
        case 'new-bank':
            return <QuestionBankForm key="new-bank" bankId={null} navigate={runtime.navigate} />;
        case 'edit-bank':
            return <QuestionBankForm key={route.bankId} bankId={route.bankId} navigate={runtime.navigate} />;
        case 'questions':
            return <QuestionList key={route.bankId} bankId={route.bankId} userId={runtime.config.user.id} query={route.query} navigate={runtime.navigate} replace={runtime.replace} />;
        case 'new-question':
            return <QuestionEditor key={`new-${route.bankId}`} bankId={route.bankId} questionId={null} preset={route.preset} locale={runtime.config.locale} navigate={runtime.navigate} />;
        case 'edit-question':
            return <QuestionEditor key={`${route.bankId}-${route.questionId}`} bankId={route.bankId} questionId={route.questionId} preset={null} locale={runtime.config.locale} navigate={runtime.navigate} />;
        case 'not-found':
            return (
                <section role="alert" className="rounded-lg border border-border bg-surface p-5">
                    <h1 className="font-display text-xl font-semibold text-fg">{strings.state.missingTitle}</h1>
                    <p className="mt-1.5 text-sm text-fg-2">{strings.state.missingBody}</p>
                    <Button className="mt-4" variant="outline" size="sm" onClick={() => runtime.navigate('/')}>
                        {strings.state.return}
                    </Button>
                </section>
            );
    }
}

/** Exported implementation registered by Pro, never by the free screen list. */
export function QuestionBanksAdminScreen({ runtime }: { runtime: AdminScreenRuntime }) {
    return (
        <RuntimeProviders runtime={runtime}>
            <RoutedQuestionBanks runtime={runtime} />
        </RuntimeProviders>
    );
}

/** Convenient registry callback with the exact AdminScreenDefinition shape. */
export function renderQuestionBanksAdminScreen(runtime: AdminScreenRuntime): ReactNode {
    return <QuestionBanksAdminScreen runtime={runtime} />;
}
