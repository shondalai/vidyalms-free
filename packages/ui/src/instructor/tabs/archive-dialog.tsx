import { useEffect, useRef } from 'react';
import {
    Button,
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    SaveIndicator,
} from '@/shared/components';
import { useArchiveLesson, useArchiveModule } from '../api';
import { archiveMessage } from '../lib/archive';
import { instructorStrings as strings } from '../strings';
import { useSaveTracker } from '../lib/save-state';
import { SaveError } from '../components/states';

/*
 * The confirmation in front of archiving, which states the consequence
 * instead of asking whether the author is sure: what leaves the learner
 * curriculum, what is kept, and that this can complete the course for a
 * learner who had done everything else. Radix owns the focus trap, Escape
 * and the focus restore.
 *
 * One dialog for both kinds. A module and a lesson differ in the route they
 * archive through and in one sentence of copy, which is not two components.
 */

export interface ArchiveTarget {
    kind: 'module' | 'lesson';
    id: number;
    title: string;
}

const COPY = {
    module: {
        title: strings.curriculum.archive.moduleTitle,
        body: strings.curriculum.archive.moduleBody,
        confirm: strings.curriculum.archive.confirmModule,
        done: strings.curriculum.archive.archivedModule,
    },
    lesson: {
        title: strings.curriculum.archive.lessonTitle,
        body: strings.curriculum.archive.lessonBody,
        confirm: strings.curriculum.archive.confirmLesson,
        done: strings.curriculum.archive.archivedLesson,
    },
} as const;

export function ArchiveDialog({
    courseId,
    target,
    onClose,
    onArchived, draftWarning = false,
}: {
    courseId: number;
    target: ArchiveTarget;
    onClose: () => void;
    /** The sentence to surface: the server's note when it wrote one. */
    onArchived: (message: string) => void;
    draftWarning?: boolean;
}) {
    const archiveModule = useArchiveModule();
    const archiveLesson = useArchiveLesson();
    const save = useSaveTracker();
    /* The save tracker answers whether the write landed, not what it
       answered, and the retry has to keep the same key. The outcome is
       picked up here so both stay true. */
    const messageRef = useRef<string | null>(null);
    const settledRef = useRef(false);
    const copy = COPY[target.kind];

    const confirm = () => {
        void save.run(async (idempotencyKey) => {
            const outcome =
                target.kind === 'module'
                    ? await archiveModule.mutateAsync({ courseId, moduleId: target.id, idempotencyKey })
                    : await archiveLesson.mutateAsync({ courseId, lessonId: target.id, idempotencyKey });
            messageRef.current = archiveMessage(target.title, target.kind, outcome);
            return outcome;
        });
    };

    /*
     * The write lands here rather than on the promise the confirm button
     * returns, because the retry of a failed write does not go through that
     * button: a save that failed once and succeeded on its retry has still
     * archived the row, and a dialog left open over it would be lying. The
     * ref makes the finish happen once whatever else re-renders.
     */
    useEffect(() => {
        if (save.state !== 'saved' || settledRef.current) {
            return;
        }
        settledRef.current = true;
        onArchived(messageRef.current ?? copy.done(target.title));
        onClose();
    }, [save.state, onArchived, onClose, copy, target.title]);

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogTitle>{copy.title}</DialogTitle>
                <DialogDescription>{copy.body}</DialogDescription>
                {draftWarning && <p role="alert" className="mt-3 text-sm text-warn-strong">{strings.lesson.unsaved}</p>}
                {save.state === 'failed' && (
                    <div className="mt-3">
                        <SaveError error={save.error} onRetry={save.retry} />
                    </div>
                )}
                <DialogFooter className="items-center">
                    <SaveIndicator className="mr-auto" state={save.state} savedAt={save.savedAt} />
                    <Button variant="outline" size="sm" onClick={onClose}>
                        {strings.curriculum.archive.keep}
                    </Button>
                    <Button
                        variant="destructive"
                        size="sm"
                        onClick={confirm}
                        disabled={save.state === 'saving'}
                    >
                        {copy.confirm}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
