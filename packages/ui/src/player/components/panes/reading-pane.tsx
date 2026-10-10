import { readPresentation } from '@/shared/lesson-presentation';
import { prepareReading, printReading } from '../../lesson-reading';
import { presentationStrings as presentationCopy } from '../../presentation-strings';
import { useEffect, useMemo, useRef } from 'react';
import { Button, EmptyState, SaveIndicator, type SaveState } from '@/shared/components';
import type { LessonView } from '@/shared/api';
import { playerStrings } from '../../strings';

/*
 * Reading lesson (artboard 2a): sanitized authored HTML at content width,
 * the "reading position saved" strip with the live save state, completion
 * when the end of the content has been reached. Scroll progress is read
 * with rect math against the window, so it works no matter which ancestor
 * actually scrolls the host page.
 */
export interface ReadingPaneProps {
    lesson: LessonView;
    saveState: SaveState;
    savedAt: number | null;
    onRetrySave: () => void;
    /** Reports the monotonic read fraction and the reached-the-end claim. */
    onProgress: (percent: number, completed: boolean) => void;
}

export function ReadingPane({ lesson, saveState, savedAt, onRetrySave, onProgress }: ReadingPaneProps) {
    const articleRef = useRef<HTMLDivElement>(null);
    const onProgressRef = useRef(onProgress);
    onProgressRef.current = onProgress;

    const body = typeof lesson.content?.body === 'string' ? lesson.content.body : null;
    const presentation = readPresentation(lesson.content);
    const reading = useMemo(
        () => (body !== null ? prepareReading(body, lesson.id) : null),
        [body, lesson.id],
    );
    const html = reading?.html ?? null;

    useEffect(() => {
        if (html === null) {
            return;
        }
        const measure = () => {
            const el = articleRef.current;
            if (el === null) {
                return;
            }
            const rect = el.getBoundingClientRect();
            if (rect.height <= 0) {
                return;
            }
            const viewportBottom = window.innerHeight;
            const fraction = Math.min(1, Math.max(0, (viewportBottom - rect.top) / rect.height));
            const atEnd = rect.bottom <= viewportBottom + 2;
            onProgressRef.current(Math.round(fraction * 100), atEnd);
        };
        /* Capture-phase scroll hears every scrolling ancestor, not only the
           window; the host page decides who scrolls. */
        const raf = window.requestAnimationFrame(measure);
        window.addEventListener('scroll', measure, { capture: true, passive: true });
        window.addEventListener('resize', measure);
        return () => {
            window.cancelAnimationFrame(raf);
            window.removeEventListener('scroll', measure, { capture: true });
            window.removeEventListener('resize', measure);
        };
    }, [html, lesson.id]);

    if (html === null) {
        return (
            <EmptyState
                title={playerStrings.reading.noContent.title}
                description={playerStrings.reading.noContent.body}
            />
        );
    }

    return (
        <div>
            {presentation.allow_print && (
                <div className="mb-4 flex justify-end">
                    <Button variant="outline" size="sm" onClick={() => printReading(lesson.title, html)}>
                        {presentationCopy.print}
                    </Button>
                </div>
            )}
            {presentation.show_contents && reading !== null && reading.headings.length > 0 && (
                <nav
                    aria-label={presentationCopy.contents}
                    className="mb-5 rounded-lg border border-border bg-bg p-4"
                >
                    <h3 className="mb-2 text-sm font-semibold">{presentationCopy.contents}</h3>
                    <ul>
                        {reading.headings.map((heading) => (
                            <li key={heading.id} className={heading.level === 3 ? 'pl-4' : ''}>
                                <button
                                    type="button"
                                    className="py-1 text-left text-sm text-accent"
                                    onClick={() =>
                                        articleRef.current
                                            ?.querySelector(`#${heading.id}`)
                                            ?.scrollIntoView({ block: 'start' })
                                    }
                                >
                                    {heading.title}
                                </button>
                            </li>
                        ))}
                    </ul>
                </nav>
            )}
            <div
                ref={articleRef}
                className="vy-prose"
                style={{
                    maxWidth:
                        presentation.reading_width === 'comfortable'
                            ? '64ch'
                            : presentation.reading_width === 'wide'
                              ? '88ch'
                              : '100%',
                }}
                /* Sanitized above with DOMPurify; raw HTML never reaches here. */
                dangerouslySetInnerHTML={{ __html: html }}
            />
            <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded border border-border bg-bg px-4 py-3 text-[12.5px] text-fg-2">
                <span>{playerStrings.reading.positionSaved}</span>
                <SaveIndicator state={saveState} savedAt={savedAt} onRetry={onRetrySave} />
            </div>
        </div>
    );
}
