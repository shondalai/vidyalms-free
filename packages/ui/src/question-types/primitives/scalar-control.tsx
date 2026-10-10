import { Minus, Plus } from 'lucide-react';
import { Icon } from '@/shared/components';
import { primitiveStrings as strings } from '../strings';
import { InlineError, describedBy, type PrimitiveProps } from './common';
import { buttonTarget } from './styles';

/*
 * ScalarControl: the client counterpart of the scalar primitive, and the
 * one a single-integer engagement signal reads through as well.
 *
 * A bare slider is not enough. Track click plus steppers plus a numeric
 * input is the required component set: the track click and the steppers are
 * the pointer-without-drag route (SC 2.5.7), the numeric input is the
 * single-input route and the keyboard route that does not depend on
 * repeated arrow presses across a long range, and the native range input
 * carries the role, the value and the arrow keys (SC 4.1.2).
 *
 * The track and the thumb carry a 3:1 indicator, and the value is always
 * shown as text as well, so the state is never colour or position alone.
 */

export interface ScalarControlProps extends PrimitiveProps {
    value: number | null;
    onChange: (value: number | null) => void;
    min: number;
    max: number;
    step?: number;
    /** Rendered under the ends of the track, never over the image of one. */
    minLabel?: string;
    maxLabel?: string;
    unit?: string;
    /**
     * The words beside the number field, which are also its accessible name.
     *
     * SC 2.5.3 Label in Name: the visible caption and the accessible name
     * have to be one string, and they were not. The caption was the fixed
     * word "Value" while the name came from the caller, so the field beside
     * that word was announced as the prompt and a speech-recognition user
     * saying "click Value" could not reach it. A caller with words of its own
     * for this field passes them here.
     */
    fieldLabel?: string;
}

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

export function ScalarControl({
    value,
    onChange,
    min,
    max,
    step = 1,
    minLabel,
    maxLabel,
    unit,
    controlId,
    labelledBy,
    label,
    announce,
    readOnly = false,
    presentation = 'rich',
    fieldLabel,
    error,
    className,
}: ScalarControlProps) {
    const errorId = controlId('error');
    const sliderId = controlId('slider');
    const numberId = controlId('value');
    const current = value ?? min;

    const commit = (next: number) => {
        const bounded = clamp(next, min, max);
        onChange(bounded);
        announce('status', `${strings.valueLabel}: ${bounded}${unit === undefined ? '' : ` ${unit}`}`);
    };

    /*
     * The visible caption and the accessible name are one string (SC 2.5.3
     * Label in Name).
     *
     * The caption used to be the fixed word "Value" while the accessible name
     * came from the caller through aria-labelledby, so the field beside the
     * word "Value" was announced as "Estimate the pH of the solution." and a
     * speech-recognition user saying "click Value" could not reach it. The
     * caller's own words win: fieldLabel when it gives one, its group label
     * otherwise, and the generic word only when it names nothing.
     */
    const captionText = fieldLabel ?? label ?? strings.valueLabel;
    const captionId = controlId('caption');
    const numberInput = (
        <label className="flex items-center gap-2 text-[12px] text-fg-2">
            <span id={captionId}>{captionText}</span>
            <input
                id={numberId}
                type="number"
                inputMode="decimal"
                min={min}
                max={max}
                step={step}
                value={value === null ? '' : value}
                disabled={readOnly}
                aria-labelledby={captionId}
                aria-describedby={describedBy(error !== undefined && errorId)}
                aria-invalid={error !== undefined ? true : undefined}
                onChange={(event) => {
                    const raw = event.target.value;
                    if (raw === '') {
                        onChange(null);
                        return;
                    }
                    const parsed = Number(raw);
                    if (Number.isFinite(parsed)) {
                        onChange(clamp(parsed, min, max));
                    }
                }}
                className="h-[38px] w-24 rounded border border-border bg-surface px-2 text-center text-sm font-semibold text-fg"
            />
            {unit !== undefined && <span>{unit}</span>}
        </label>
    );

    if (presentation === 'linear') {
        return (
            <div className={className}>
                {numberInput}
                <InlineError id={errorId} message={error} />
            </div>
        );
    }

    return (
        <div className={className}>
            <div className="flex max-w-[520px] flex-wrap items-center gap-3">
                <button
                    type="button"
                    aria-label={strings.stepDown}
                    disabled={readOnly || current <= min}
                    onClick={() => commit(current - step)}
                    className={buttonTarget}
                >
                    <Icon icon={Minus} size={14} />
                </button>
                <input
                    id={sliderId}
                    type="range"
                    min={min}
                    max={max}
                    step={step}
                    value={current}
                    disabled={readOnly}
                    aria-labelledby={labelledBy}
                    aria-label={labelledBy === undefined ? label : undefined}
                    aria-describedby={describedBy(error !== undefined && errorId)}
                    onChange={(event) => commit(Number(event.target.value))}
                    className="h-6 min-w-[160px] flex-1 accent-accent"
                />
                <button
                    type="button"
                    aria-label={strings.stepUp}
                    disabled={readOnly || current >= max}
                    onClick={() => commit(current + step)}
                    className={buttonTarget}
                >
                    <Icon icon={Plus} size={14} />
                </button>
                {numberInput}
            </div>
            {(minLabel !== undefined || maxLabel !== undefined) && (
                <div className="mt-1 flex max-w-[520px] justify-between text-[11.5px] text-fg-2">
                    <span>{minLabel ?? min}</span>
                    <span>{maxLabel ?? max}</span>
                </div>
            )}
            <InlineError id={errorId} message={error} />
        </div>
    );
}
