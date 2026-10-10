import { readPresentation } from '@/shared/lesson-presentation';
import { presentationStrings } from '../../presentation-strings';
import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { cn } from '@/shared/cn';
import { EmptyState } from '@/shared/components';
import type { LessonView } from '@/shared/api';
import { playerStrings } from '../../strings';
import { clampPercent, videoEmbedSrc } from '../../lib';

/*
 * Video lesson (artboard 1a): the embed fills a 16:9 stage; completion at
 * 90% watched. Below the stage sit the artboard's Transcript / About this
 * lesson / Downloads tabs, built strictly from contract fields: the lesson
 * summary fills About, and the sections the contract has no field for
 * (transcript text, download files) render their empty states instead of
 * invented data. The watched-progress hint lives in the pane footer, lifted
 * through onProgress.
 *
 * provider "url" plays through a native <video>: real position and duration
 * feed position_seconds and progress_percent, playback resumes at the
 * stored position, and the native controls carry the keyboard operability
 * and captions the artboard asks for (caption_url becomes a track).
 *
 * provider "youtube"/"vimeo" is a plain iframe embed: the page cannot read
 * playback state without the vendors' scripts, so watched progress falls
 * back to time visibly spent on the lesson against the stored duration,
 * the same clock the server's seconds_spent counts.
 */
export interface VideoPaneProps {
    lesson: LessonView;
    /** Reports percent watched, the playback position, completion at 90%. */
    onProgress: (percent: number, positionSeconds: number | null, completed: boolean) => void;
}

const COMPLETE_AT_PERCENT = 90;

function useOpenTimeProgress(
    enabled: boolean,
    lesson: LessonView,
    onProgress: VideoPaneProps['onProgress'],
): void {
    const onProgressRef = useRef(onProgress);
    onProgressRef.current = onProgress;
    useEffect(() => {
        if (!enabled) {
            return;
        }
        const duration = lesson.duration_seconds;
        if (duration === null || duration <= 0) {
            return;
        }
        let openSeconds = lesson.progress?.seconds_spent ?? 0;
        const timer = window.setInterval(() => {
            if (document.visibilityState !== 'visible') {
                return;
            }
            openSeconds += 1;
            const percent = clampPercent((openSeconds / duration) * 100);
            onProgressRef.current(Math.round(percent), null, percent >= COMPLETE_AT_PERCENT);
        }, 1000);
        return () => window.clearInterval(timer);
    }, [enabled, lesson.id, lesson.duration_seconds, lesson.progress?.seconds_spent]);
}

/**
 * The tabs strip of artboard 1a (Foundations piece 9): underline style,
 * arrow-key operable, one panel at a time. Local state only; the content
 * comes from the caller as [label, panel] pairs.
 */
function LessonTabs({ tabs }: { tabs: { id: string; label: string; panel: ReactNode }[] }) {
    const [active, setActive] = useState(0);
    const buttonsRef = useRef<(HTMLButtonElement | null)[]>([]);

    const focusTab = (index: number) => {
        const bounded = (index + tabs.length) % tabs.length;
        setActive(bounded);
        buttonsRef.current[bounded]?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLButtonElement>, index: number) => {
        switch (event.key) {
            case 'ArrowRight':
                event.preventDefault();
                focusTab(index + 1);
                break;
            case 'ArrowLeft':
                event.preventDefault();
                focusTab(index - 1);
                break;
            case 'Home':
                event.preventDefault();
                focusTab(0);
                break;
            case 'End':
                event.preventDefault();
                focusTab(tabs.length - 1);
                break;
        }
    };

    const current = tabs[active] ?? tabs[0];
    if (current === undefined) {
        return null;
    }
    return (
        <div className="mt-[18px] border-t border-border pt-3.5">
            <div
                role="tablist"
                aria-label={playerStrings.video.tabs.label}
                className="mb-3 flex gap-0.5 border-b border-border text-[13px] font-medium"
            >
                {tabs.map((tab, index) => (
                    <button
                        key={tab.id}
                        ref={(el) => {
                            buttonsRef.current[index] = el;
                        }}
                        type="button"
                        role="tab"
                        id={`vy-tab-${tab.id}`}
                        aria-selected={index === active}
                        aria-controls={`vy-tabpanel-${tab.id}`}
                        tabIndex={index === active ? 0 : -1}
                        onClick={() => setActive(index)}
                        onKeyDown={(event) => onKeyDown(event, index)}
                        className={cn(
                            '-mb-px cursor-pointer px-3.5 py-2',
                            index === active
                                ? 'border-b-2 border-b-accent font-semibold text-fg'
                                : 'text-fg-2 hover:text-fg',
                        )}
                    >
                        {tab.label}
                    </button>
                ))}
            </div>
            <div
                role="tabpanel"
                id={`vy-tabpanel-${current.id}`}
                aria-labelledby={`vy-tab-${current.id}`}
                className="text-[13.5px] leading-relaxed text-fg"
            >
                {current.panel}
            </div>
        </div>
    );
}

/** The three artboard sections, from the fields the contract actually has. */
function lessonTabs(lesson: LessonView, captionUrl: string | null) {
    const presentation = readPresentation(lesson.content);
    const download =
        presentation.download && lesson.content?.provider === 'url' && typeof lesson.content.url === 'string'
            ? lesson.content.url
            : null;
    const summary = lesson.summary !== null && lesson.summary !== '' ? lesson.summary : null;
    return [
        {
            id: 'transcript',
            label: playerStrings.video.tabs.transcript,
            panel: (
                <div className="grid gap-3">
                    {captionUrl !== null && <p className="text-fg-2">{playerStrings.video.captionNote}</p>}
                    {presentation.transcript ? (
                        <p className="whitespace-pre-wrap">{presentation.transcript}</p>
                    ) : (
                        <EmptyState
                            title={playerStrings.video.transcriptEmpty.title}
                            description={playerStrings.video.transcriptEmpty.body}
                        />
                    )}
                </div>
            ),
        },
        {
            id: 'about',
            label: playerStrings.video.tabs.about,
            panel:
                summary !== null ? (
                    <p className="max-w-[68ch]">{summary}</p>
                ) : (
                    <EmptyState
                        title={playerStrings.video.aboutEmpty.title}
                        description={playerStrings.video.aboutEmpty.body}
                    />
                ),
        },
        {
            id: 'downloads',
            label: playerStrings.video.tabs.downloads(download ? 1 : 0),
            panel: download ? (
                <a href={download} download className="font-semibold text-accent">
                    {presentationStrings.downloadVideo}
                </a>
            ) : (
                <EmptyState
                    title={playerStrings.video.downloadsEmpty.title}
                    description={playerStrings.video.downloadsEmpty.body}
                />
            ),
        },
    ];
}

export function VideoPane({ lesson, onProgress }: VideoPaneProps) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const onProgressRef = useRef(onProgress);
    onProgressRef.current = onProgress;

    const content = lesson.content ?? {};
    const presentation = readPresentation(content);
    const provider = typeof content.provider === 'string' ? content.provider : null;
    const url = typeof content.url === 'string' ? content.url : null;
    const captionUrl = typeof content.caption_url === 'string' ? content.caption_url : null;
    const embedSrc =
        provider !== null && url !== null && provider !== 'url' ? videoEmbedSrc(provider, url) : null;

    useOpenTimeProgress(embedSrc !== null, lesson, onProgress);

    /* Resume a direct video at the stored position, a little back from it. */
    const initialPosition = lesson.progress?.position_seconds ?? null;
    useEffect(() => {
        const video = videoRef.current;
        if (!presentation.resume || video === null || initialPosition === null || initialPosition <= 0) {
            return;
        }
        const seek = () => {
            if (video.duration > 0 && initialPosition < video.duration - 5) {
                video.currentTime = Math.max(0, initialPosition - 2);
            }
        };
        if (video.readyState >= 1) {
            seek();
            return;
        }
        video.addEventListener('loadedmetadata', seek, { once: true });
        return () => video.removeEventListener('loadedmetadata', seek);
    }, [lesson.id, initialPosition, presentation.resume]);

    if (url === null) {
        return (
            <EmptyState
                title={playerStrings.video.unavailable.title}
                description={playerStrings.video.unavailable.body}
            />
        );
    }

    if (provider === 'url') {
        return (
            <div>
                <video
                    ref={videoRef}
                    className="aspect-video w-full rounded bg-fg"
                    controls
                    preload="metadata"
                    poster={presentation.poster_url || undefined}
                    disablePictureInPicture={!presentation.picture_in_picture}
                    controlsList={presentation.download ? undefined : 'nodownload'}
                    src={url}
                    aria-label={lesson.title}
                    crossOrigin={
                        captionUrl !== null || presentation.captions.length > 0 ? 'anonymous' : undefined
                    }
                    onTimeUpdate={(event) => {
                        const video = event.currentTarget;
                        if (video.duration > 0) {
                            const percent = clampPercent((video.currentTime / video.duration) * 100);
                            onProgressRef.current(
                                Math.round(percent),
                                Math.floor(video.currentTime),
                                percent >= COMPLETE_AT_PERCENT,
                            );
                        }
                    }}
                    onEnded={() => onProgressRef.current(100, null, true)}
                >
                    {captionUrl !== null && <track kind="captions" src={captionUrl} default />}
                    {presentation.captions.map((track, index) => (
                        <track
                            key={`${track.language}-${index}`}
                            kind="captions"
                            src={track.url}
                            label={track.label}
                            srcLang={track.language}
                            default={captionUrl === null && index === 0}
                        />
                    ))}
                </video>
                <LessonTabs tabs={lessonTabs(lesson, captionUrl)} />
            </div>
        );
    }

    if (embedSrc !== null) {
        return (
            <div>
                <iframe
                    className="aspect-video w-full rounded border-0 bg-fg"
                    src={embedSrc}
                    title={lesson.title}
                    allow="autoplay; encrypted-media; picture-in-picture"
                    allowFullScreen
                />
                <LessonTabs tabs={lessonTabs(lesson, captionUrl)} />
            </div>
        );
    }

    return (
        <EmptyState
            title={playerStrings.video.unavailable.title}
            description={playerStrings.video.unavailable.body}
            action={
                <a
                    className="text-[12.5px] font-semibold text-accent"
                    href={url}
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    {playerStrings.video.openExternally}
                </a>
            }
        />
    );
}
