import { translate as uiText } from '@/shared/i18n';
import { useState } from 'react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    ErrorState,
    buttonVariants,
} from '@/shared/components';
import { useApiClient } from '@/shared/api';
import { must } from '@/shared/manage';
import { useMutation } from '@tanstack/react-query';
import { flowStrings } from '../flow-strings';
import { Field, Select } from '../components/field';
import type { components } from '@vidyalms/api-sdk';

/*
 * Export one quiz.
 *
 * The notice comes BEFORE the download and not after it, because after it is
 * too late: the package carries the correct responses, is useless without
 * them, and anybody who can open the file can read the answer key. The
 * contract states the same fact on the report as `contains_correct_responses`
 * and the screen says it first.
 *
 * The bytes never travel in the response. The route answers a signed,
 * expiring link, which is what the storage port promises for every private
 * file, so the download is an ordinary link the host serves.
 */

const strings = flowStrings.export;

type QuizExportReport = components['schemas']['QuizExportReport'];

export function ExportDialog({ quizId, onClose }: { quizId: number; onClose: () => void }) {
    const client = useApiClient();
    const [format, setFormat] = useState('qti3');
    const [profile, setProfile] = useState<'strict' | 'extended'>('strict');
    const [report, setReport] = useState<QuizExportReport | null>(null);

    const run = useMutation({
        mutationFn: async () => {
            const { data } = await client.GET('/manage/quizzes/{id}/export', {
                params: { path: { id: quizId }, query: { format, profile } },
            });
            return must(data);
        },
        onSuccess: setReport,
    });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogTitle>{strings.title}</DialogTitle>
                <DialogDescription>{strings.correctResponses}</DialogDescription>
                <div className="mt-3.5 grid gap-3.5">
                    <Field label={strings.format}>
                        {({ id }) => (
                            <Select id={id} value={format} onChange={(event) => setFormat(event.target.value)}>
                                <option value="qti3">{uiText("vidyalms.ui.c3b1d30806d84fd9", "QTI 3.0")}</option>
                                <option value="qti2">{uiText("vidyalms.ui.5c34edced7b7b55e", "QTI 2.1")}</option>
                            </Select>
                        )}
                    </Field>
                    <Field label={uiText("vidyalms.ui.d696a35bdd1883da", "Profile")}>
                        {({ id }) => (
                            <Select
                                id={id}
                                value={profile}
                                onChange={(event) => setProfile(event.target.value as 'strict' | 'extended')}
                            >
                                <option value="strict">{uiText("vidyalms.ui.3a3127f5ea0269b5", "strict")}</option>
                                <option value="extended">{uiText("vidyalms.ui.4d7120eb6c796b04", "extended")}</option>
                            </Select>
                        )}
                    </Field>
                    {run.isError && <ErrorState error={run.error} />}
                    {report !== null && (
                        <div className="grid gap-1.5 rounded border border-border bg-bg p-3 text-[12px]">
                            <p className="text-fg-2">
                                {report.format} · {report.items} {uiText("vidyalms.ui.1dc8b0a1c43cc932", " items · ")}{report.media} {uiText("vidyalms.ui.fe2026f79566617d", " media")}</p>
                            {report.downgrades.length > 0 && (
                                <ul className="grid gap-0.5">
                                    {report.downgrades.map((downgrade) => (
                                        <li key={`${downgrade.question}-${downgrade.policy}`} className="text-warn-strong">
                                            {downgrade.question}: {downgrade.policy} {uiText("vidyalms.ui.218e25697aa7f8b3", " became ")}{downgrade.became},{' '}
                                            {downgrade.lost}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {report.warnings.map((warning) => (
                                <p key={warning} className="text-warn-strong">
                                    {warning}
                                </p>
                            ))}
                            <p>
                                <a
                                    className={buttonVariants({ size: 'sm' })}
                                    href={report.download_url}
                                    rel="noreferrer"
                                >
                                    {strings.download}
                                </a>
                            </p>
                        </div>
                    )}
                </div>
                <DialogFooter>
                    <Button variant="ghost" onClick={onClose}>
                        {strings.cancel}
                    </Button>
                    <Button disabled={run.isPending} onClick={() => run.mutate()}>
                        {strings.button}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
