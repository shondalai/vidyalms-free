import { cn } from '@/shared/cn';
import { clampPercent } from '../lib';

/*
 * Foundations artboard, piece 5, ring form: conic accent fill over the
 * surface-2 track, percent readable in the middle. The muted variant is
 * the suspended state's grey ring (artboard 3e).
 */
export interface ProgressRingProps {
    percent: number;
    label: string;
    size?: number;
    muted?: boolean;
    className?: string;
}

export function ProgressRing({ percent, label, size = 44, muted = false, className }: ProgressRingProps) {
    const clamped = Math.round(clampPercent(percent));
    const fill = muted ? 'var(--_vy-border)' : 'var(--_vy-accent)';
    const inner = Math.round(size * 0.75);
    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={clamped}
            className={cn('flex shrink-0 items-center justify-center rounded-full', className)}
            style={{
                width: size,
                height: size,
                background: `conic-gradient(${fill} 0 ${clamped}%, var(--_vy-surface-2) ${clamped}%)`,
            }}
        >
            <span
                className={cn(
                    'flex items-center justify-center rounded-full bg-surface font-display text-[10.5px] font-bold',
                    muted ? 'text-fg-2' : 'text-fg',
                )}
                style={{ width: inner, height: inner }}
            >
                {clamped}%
            </span>
        </div>
    );
}
