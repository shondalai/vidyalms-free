import { ArrowLeft, Maximize2, Minimize2, PanelLeft } from 'lucide-react';
import { Button, Icon, SaveIndicator, type SaveState } from '@/shared/components';
import { playerStrings } from '../strings';
import { ProgressRing } from './progress-ring';
import { presentationStrings as copy } from '../presentation-strings';

/*
 * The course header strip (artboard 1a): progress ring, course title, the
 * "3 of 12 lessons complete · <rule>" meta line, and the SaveIndicator on
 * the right. In the narrow layout a Course map button replaces the
 * persistent rail (the drawer pattern of the artboard).
 */
export interface CourseHeaderProps {
    title: string;
    percent: number;
    lessonsDone: number;
    lessonsTotal: number;
    ruleText: string;
    saveState: SaveState;
    savedAt: number | null;
    onRetrySave: () => void;
    narrow: boolean;
    onOpenMap: () => void;
    courseUrl: string;
    focused: boolean;
    onToggleFocus: () => void;
    /**
     * True when the outline carries more items than the completion
     * denominator counts.
     *
     * The denominator excludes items a learner can never reach and items
     * whose window has closed, so the two numbers legitimately differ and a
     * learner counting rows would otherwise think the course was broken.
     * WHICH items are excluded is deliberately not said: that distinction is
     * a denominator fact, and publishing it would tell the learner which
     * exclusions were administrative.
     */
    someNotCounted?: boolean;
}

export function CourseHeader({
    title,
    percent,
    lessonsDone,
    lessonsTotal,
    ruleText,
    saveState,
    savedAt,
    onRetrySave,
    narrow,
    onOpenMap,
    courseUrl,
    focused,
    onToggleFocus,
    someNotCounted = false,
}: CourseHeaderProps) {
    return (
        <header className="vy-player-header">
            <a className="vy-study-back" href={courseUrl}>
                <ArrowLeft size={17} aria-hidden="true" />
                <span>{copy.backToCourse}</span>
            </a>
            <div className="vy-player-course-title">
                <span className="vy-study-eyebrow">{copy.learningSpace}</span>
                <h2>{title}</h2>
            </div>
            <div className="vy-header-progress">
                <ProgressRing percent={percent} label={playerStrings.header.progressLabel} />
                <span>{playerStrings.header.lessonsComplete(lessonsDone, lessonsTotal)}</span>
            </div>
            <SaveIndicator className="vy-header-save" state={saveState} savedAt={savedAt} onRetry={onRetrySave} />
            {narrow && (
                <Button variant="outline" size="sm" onClick={onOpenMap}>
                    <Icon icon={PanelLeft} size={14} />
                    {playerStrings.header.courseMap}
                </Button>
            )}
            {!narrow && (
                <Button variant="ghost" size="sm" onClick={onToggleFocus} aria-pressed={focused}>
                    <Icon icon={focused ? Minimize2 : Maximize2} size={16} />
                    {focused ? copy.exitFocus : copy.focus}
                </Button>
            )}
            <details className="vy-completion-details">
                <summary>{copy.completion}</summary>
                <p>{ruleText}{someNotCounted && <> · {playerStrings.availability.notCounted}</>}</p>
            </details>
        </header>
    );
}
