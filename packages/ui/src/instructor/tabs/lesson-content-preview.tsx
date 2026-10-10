import { useWatch, type Control } from 'react-hook-form';
import { sanitizeHtml } from '@/player/sanitize';
import { videoEmbedSrc } from '@/player/lib';
import type { LessonFormValues } from '../lib/lesson-content';
import { studioStrings as s } from '../studio-strings';

export function LessonContentPreview({
    type,
    control,
}: {
    type: string;
    control: Control<LessonFormValues>;
}) {
    const values = useWatch({ control });
    const embed =
        values.url && values.provider && values.provider !== 'url'
            ? videoEmbedSrc(values.provider, values.url)
            : null;
    return (
        <div className="vy-lesson-preview">
            <p className="mb-4 text-xs text-fg-2">{s.previewHint}</p>
            <h3 className="mb-4 font-display text-lg text-fg">{values.title}</h3>
            {type === 'reading' && (
                <div
                    className="vy-prose"
                    dangerouslySetInnerHTML={{ __html: sanitizeHtml(values.body ?? '') }}
                />
            )}
            {type === 'video' &&
                (values.provider === 'url' ? (
                    <video
                        className="aspect-video w-full"
                        controls
                        src={values.url}
                        poster={values.presentation?.poster_url}
                    />
                ) : (
                    embed && (
                        <iframe
                            className="aspect-video w-full"
                            title={values.title}
                            src={embed}
                            allowFullScreen
                        />
                    )
                ))}
            {type === 'resource' && (
                <ul>
                    {values.files?.map((file, index) => (
                        <li key={index} className="border-b border-border p-3">
                            {file?.name}
                        </li>
                    ))}
                </ul>
            )}
            {type === 'assignment' && <p className="whitespace-pre-wrap text-sm">{values.instructions}</p>}
            {type === 'quiz' && <p>{s.noPreview}</p>}
        </div>
    );
}
