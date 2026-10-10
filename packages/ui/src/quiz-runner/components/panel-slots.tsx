import { useAnnouncer } from '@/shared/a11y';
import { Button } from '@/shared/components';
import { useRunnerPanel } from '@/runner-panels';
import type { CertaintyScale } from '@/runner-panels';
import { runnerRuntimeStrings as strings } from '../runtime-strings';

/*
 * The two places the runner offers a panel it does not itself draw.
 *
 * Confidence capture and the per question feedback panel belong to the
 * attempt BEHAVIOUR, which is a quiz level policy provider. The free build
 * registers deferred_feedback and manual, neither of which asks for either
 * panel, so neither renderer is in this bundle and the bundle is the same
 * bytes whether or not Pro is installed.
 *
 * When a behaviour asks for a panel nothing registered, the slot renders a
 * placeholder rather than nothing at all. The placeholder is an
 * accessibility surface in its own right (12-assessment-types.md, "The
 * removal placeholder is an accessibility surface"): it names what is
 * missing, states that the learner's work is preserved and says exactly what
 * happens instead, so a half installed site is legible rather than silently
 * different.
 */

function Placeholder({ title, body, note }: { title: string; body: string; note?: string }) {
    return (
        <div
            role="status"
            className="mt-4 rounded border border-border border-l-[3px] border-l-warn bg-surface px-4 py-3 text-[12.5px] leading-[1.55] text-fg-2"
        >
            <strong className="font-semibold text-warn-strong">{title}</strong> {body}
            {note !== undefined && <p className="mt-1.5">{note}</p>}
        </div>
    );
}

export interface CertaintySlotProps {
    scale: CertaintyScale;
    value: number | null;
    onChange: (value: number | null) => void;
    controlId: (local: string) => string;
    readOnly: boolean;
    locale: string;
    /** Named in the placeholder so a support call starts from the right fact. */
    behaviour: string;
}

/**
 * The confidence scale beside one answer.
 *
 * The scale is data on the frozen snapshot rather than a provider decision,
 * so the panel renders whatever levels arrived. Without a registered panel
 * the answer is still saved and still marked; only the confidence is not
 * collected, which is what the server does anyway once it cannot resolve the
 * behaviour that wanted it.
 */
export function CertaintySlot({
    scale,
    value,
    onChange,
    controlId,
    readOnly,
    locale,
    behaviour,
}: CertaintySlotProps) {
    const announce = useAnnouncer();
    const panel = useRunnerPanel('certainty');

    if (panel === null) {
        return (
            <Placeholder
                title={strings.panels.certaintyMissingTitle}
                body={strings.panels.certaintyMissingBody}
                note={
                    scale.required
                        ? `${strings.panels.certaintyRequiredNote} ${strings.panels.missingPackage(behaviour)}`
                        : strings.panels.missingPackage(behaviour)
                }
            />
        );
    }

    const Panel = panel.Panel;
    return (
        <Panel
            scale={scale}
            value={value}
            onChange={onChange}
            controlId={controlId}
            readOnly={readOnly}
            locale={locale}
            announce={announce}
        />
    );
}

export interface ItemFeedbackSlotProps {
    behaviour: string;
    triesUsed: number;
    maxAttempts: number;
    showFeedback: boolean;
    showSolution: boolean;
    feedback: string | null;
    checking: boolean;
    onCheck: (() => void) | null;
    locale: string;
}

/**
 * The panel between two tries of one item.
 *
 * The check control lives inside the panel rather than beside it: a shell
 * that drew its own "Check answer" button with no panel to show the outcome
 * would ask the learner to spend a try for nothing.
 */
export function ItemFeedbackSlot({
    behaviour,
    triesUsed,
    maxAttempts,
    showFeedback,
    showSolution,
    feedback,
    checking,
    onCheck,
    locale,
}: ItemFeedbackSlotProps) {
    const announce = useAnnouncer();
    const panel = useRunnerPanel('item_feedback');

    if (panel === null) {
        return (
            <Placeholder
                title={strings.panels.feedbackMissingTitle}
                body={strings.panels.feedbackMissingBody}
                note={strings.panels.missingPackage(behaviour)}
            />
        );
    }

    const Panel = panel.Panel;
    return (
        <Panel
            behaviour={behaviour}
            triesUsed={triesUsed}
            maxAttempts={maxAttempts}
            showFeedback={showFeedback}
            showSolution={showSolution}
            feedback={feedback}
            checking={checking}
            onCheck={onCheck}
            locale={locale}
            announce={announce}
        />
    );
}

/**
 * The countdown control SC 2.2.2 asks for on a timed exam.
 *
 * Hiding the display is not removing the limit, and the copy says so: the
 * limit is an essential exception under SC 2.2.1 and stays exactly where it
 * was. What the control removes is the moving object, which is what the
 * success criterion is about.
 */
export function TimerVisibilityToggle({ hidden, onToggle }: { hidden: boolean; onToggle: () => void }) {
    return (
        <Button variant="ghost" size="sm" onClick={onToggle} aria-pressed={hidden}>
            {hidden ? strings.timer.show : strings.timer.hide}
        </Button>
    );
}
