import { useId, useState } from 'react';
import { ChevronDown, Search } from 'lucide-react';
import { presentationStrings as copy } from '../presentation-strings';
import { formatDuration } from '../lib';
import { cn } from '@/shared/cn';
import { VisuallyHidden } from '@/shared/components';
import { isBlocked } from '@/shared/availability';
import type { CurriculumModule } from '@/shared/api';
import { playerStrings } from '../strings';
import { AvailabilityNote, BlockedGlyph } from './availability-block';
import { LessonStateIcon, type LessonUiState } from './lesson-state-icon';
import { LessonTypeChip } from './lesson-type-chip';

/*
 * The curriculum rail (artboard 1a): modules as uppercase group headings,
 * one button row per lesson carrying state icon, title, hidden state label
 * and the type chip. The current lesson gets the accent-soft fill and the
 * 3px accent edge. Rows are real buttons in a list, so the rail is fully
 * keyboard operable with the native tab order and Enter/Space.
 *
 * A blocked row keeps every one of those properties. It is a real button, it
 * is in the tab order, it is never aria-disabled, and it carries its reason
 * as a second line the row's accessible description points at. Selecting it
 * opens the pane's blocked state, which is a state and not a refusal: a
 * learner who cannot see why an item is closed cannot plan around it, which
 * is the failure D2L's own guidance names.
 *
 * A blocked module still lists its lessons, because the contract sends them
 * and the shape of what is coming is the point. A hidden item is absent from
 * the payload entirely and never reaches here.
 */
export interface CurriculumRailProps {
    modules: CurriculumModule[];
    currentLessonId: number | null;
    onSelect: (lessonId: number) => void;
    /**
     * Assignment lessons known to hold a submitted-but-ungraded submission
     * (lifted from the open lesson's data): the rail shows the dashed
     * awaiting-grade ring for them instead of the in-progress ring.
     */
    awaitingGradeIds?: ReadonlySet<number>;
    /** The mount locale, for the date readings on blocked rows. */
    locale: string;
    className?: string;
    percent?: number;
}

function stateOf(status: string | undefined, awaitingGrade: boolean): LessonUiState {
    if (status === 'completed') {
        return 'completed';
    }
    if (awaitingGrade) {
        return 'awaiting_grade';
    }
    if (status === 'in_progress') {
        return 'in_progress';
    }
    return 'not_started';
}

function stateLabel(state: LessonUiState): string {
    switch (state) {
        case 'completed':
            return playerStrings.lessonState.completed;
        case 'in_progress':
            return playerStrings.lessonState.inProgress;
        case 'awaiting_grade':
            return playerStrings.lessonState.awaitingGrade;
        default:
            return playerStrings.lessonState.notStarted;
    }
}

export function CurriculumRail({ modules, currentLessonId, onSelect, awaitingGradeIds, locale, className, percent }: CurriculumRailProps) {
    const noteId = useId();
    const [search, setSearch] = useState('');
    const [collapsed, setCollapsed] = useState<ReadonlySet<number>>(new Set());
    const query = search.trim().toLocaleLowerCase(locale);
    const total = modules.reduce((count, module) => count + module.lessons.length, 0);
    const matched = modules.flatMap((module, index) => {
        const lessons = module.title.toLocaleLowerCase(locale).includes(query)
            ? module.lessons
            : module.lessons.filter((lesson) => lesson.title.toLocaleLowerCase(locale).includes(query));
        return lessons.length > 0 || query === '' ? [{ module, index, lessons }] : [];
    });
    return (
        <nav aria-label={playerStrings.rail.label} className={cn('vy-curriculum-rail', className)}>
            <div className="vy-journey-heading">
                <span className="vy-study-eyebrow">{copy.journey}</span>
                {percent !== undefined && <><strong>{copy.progress(percent)}</strong><div className="vy-journey-track" aria-hidden="true"><span style={{ width: `${Math.min(100, Math.max(0, percent))}%` }} /></div></>}
                <span>{copy.lessonCount(total)}</span>
            </div>
            <label className="vy-curriculum-search">
                <Search size={16} aria-hidden="true" />
                <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} aria-label={copy.searchLessons} placeholder={copy.searchLessons} />
            </label>
            {matched.length === 0 && <div className="vy-curriculum-empty"><p role="status">{copy.noMatches}</p><button type="button" onClick={() => setSearch('')}>{copy.clearSearch}</button></div>}
            {matched.map(({ module, index: moduleIndex, lessons }) => {
                const moduleBlocked = isBlocked(module.access);
                const expanded = query !== '' || !collapsed.has(module.id);
                const listId = `${noteId}-module-${module.id}`;
                return (
                    <section key={module.id} className="vy-curriculum-chapter">
                        <h3>
                            <button type="button" aria-expanded={expanded} aria-controls={listId}
                                aria-label={playerStrings.rail.module(moduleIndex + 1, module.title)}
                                onClick={() => setCollapsed((value) => {
                                    const next = new Set(value);
                                    if (next.has(module.id)) {
                                        next.delete(module.id);
                                    } else {
                                        next.add(module.id);
                                    }
                                    return next;
                                })}>
                                <span><small>{copy.chapter(moduleIndex + 1)}</small>{module.title}</span>
                                <ChevronDown size={16} aria-hidden="true" />
                            </button>
                        </h3>
                        <div id={listId} hidden={!expanded}>
                            {moduleBlocked && <p className="px-4 pb-2"><AvailabilityNote access={module.access} locale={locale} /></p>}
                            <ul>
                                {lessons.map((lesson) => {
                                    const current = lesson.id === currentLessonId;
                                    const blocked = isBlocked(lesson.access);
                                    const state = stateOf(lesson.progress?.status, awaitingGradeIds?.has(lesson.id) === true);
                                    const rowNoteId = `${noteId}-${lesson.id}`;
                                    const duration = formatDuration(lesson.duration_seconds, lesson.type);
                                    return (
                                        <li key={lesson.id}>
                                            <button type="button" aria-current={current ? 'true' : undefined}
                                                aria-describedby={blocked ? rowNoteId : undefined}
                                                onClick={() => onSelect(lesson.id)}
                                                className="vy-curriculum-lesson">
                                                <span className="vy-curriculum-state">
                                                    {blocked ? <BlockedGlyph /> : (
                                                        <LessonStateIcon state={state} percent={lesson.progress?.progress_percent ?? 0} />
                                                    )}
                                                </span>
                                                <span className="vy-curriculum-lesson-copy">
                                                    <span>{lesson.title}</span>
                                                    <span className="vy-curriculum-lesson-meta"><LessonTypeChip type={lesson.type} form="rail" current={current} />{duration !== null && <span> · {duration}</span>}</span>
                                                    {blocked && <AvailabilityNote id={rowNoteId} access={lesson.access} locale={locale} className="mt-1" />}
                                                </span>
                                                <VisuallyHidden> · {blocked ? playerStrings.lessonState.blocked : stateLabel(state)}</VisuallyHidden>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    </section>
                );
            })}
        </nav>
    );
}
