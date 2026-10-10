import { translate as uiText } from '@/shared/i18n';
import { useState, type MouseEvent } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Icon } from '@/shared/components';
import { primitiveStrings as strings } from '../strings';
import { InlineError, LinearAlternative, describedBy, type PrimitiveProps } from './common';
import { buttonTarget } from './styles';

/*
 * RegionSet: the client counterpart of the point primitive and the media
 * surface of the select, order and associate primitives.
 *
 * The parallel linear list is not an option and not a preference. It ships
 * with every media surface, which is what makes a small hotspot qualify
 * under SC 2.5.8 Equivalent, what carries SC 1.1.1 for the image and what
 * makes the interaction usable at 320 CSS pixels: one component, four
 * criteria. The image canvas may invoke the two-dimensional scrolling
 * exception of SC 1.4.10; the list around it may not.
 *
 * Region outlines carry a 3:1 indicator against adjacent colour plus a
 * non-colour channel (border weight and a marker glyph), and no label is
 * absolutely positioned over the image, because that loses content at line
 * height 1.5 and letter spacing 0.12em (SC 1.4.12).
 */

export interface Region {
    key: string;
    label: string;
    /** Percentages of the canvas box, so the outline reflows with the image. */
    left: number;
    top: number;
    width: number;
    height: number;
    shape?: 'rect' | 'ellipse';
}

export interface MarkedPoint {
    x: number;
    y: number;
    /**
     * What the learner put here, when the mark stands for something they
     * chose rather than for a bare coordinate.
     *
     * Graphic association places named markers, and the parallel list read
     * "Point at 30, 40" for every one of them, so the list a learner uses
     * instead of the image could not tell one marker from another and the
     * remove button beside it was named by an index. The label is optional
     * because point marking really does place bare coordinates.
     */
    label?: string;
}

export interface RegionSetProps extends PrimitiveProps {
    image: { src: string; alt: string; longDescription?: string };
    /** Regions mode selects named areas; points mode marks coordinates. */
    mode?: 'regions' | 'points';
    regions?: readonly Region[];
    selection?: 'single' | 'multiple';
    value?: readonly string[];
    onChange?: (keys: string[]) => void;
    points?: readonly MarkedPoint[];
    onPointsChange?: (points: MarkedPoint[]) => void;
    maxChoices?: number;
    maxMessage?: string;
}

export function RegionSet({
    image,
    mode = 'regions',
    regions = [],
    selection = 'multiple',
    value = [],
    onChange,
    points = [],
    onPointsChange,
    controlId,
    labelledBy,
    label,
    announce,
    readOnly = false,
    presentation = 'rich',
    error,
    maxChoices,
    maxMessage,
    className,
}: RegionSetProps) {
    const errorId = controlId('error');
    const describedId = controlId('longdesc');
    const [draft, setDraft] = useState<{ x: string; y: string }>({ x: '', y: '' });

    const labelOf = (key: string) => regions.find((r) => r.key === key)?.label ?? key;

    const toggleRegion = (key: string) => {
        if (onChange === undefined) {
            return;
        }
        if (selection === 'single') {
            onChange(value.includes(key) ? [] : [key]);
            announce('status', value.includes(key) ? strings.regionCleared(labelOf(key)) : strings.regionSelected(labelOf(key)));
            return;
        }
        if (value.includes(key)) {
            onChange(value.filter((k) => k !== key));
            announce('status', strings.regionCleared(labelOf(key)));
            return;
        }
        if (maxChoices !== undefined && maxChoices > 0 && value.length >= maxChoices) {
            announce('status', maxMessage ?? strings.maxReached(maxChoices));
            return;
        }
        onChange([...value, key]);
        announce('status', strings.regionSelected(labelOf(key)));
    };

    const addPoint = (x: number, y: number) => {
        if (onPointsChange === undefined) {
            return;
        }
        if (maxChoices !== undefined && maxChoices > 0 && points.length >= maxChoices) {
            announce('status', maxMessage ?? strings.maxReached(maxChoices));
            return;
        }
        onPointsChange([...points, { x, y }]);
        announce('status', strings.pointAdded(x, y));
    };

    const onCanvasClick = (event: MouseEvent<HTMLDivElement>) => {
        if (readOnly || mode !== 'points' || onPointsChange === undefined) {
            return;
        }
        const box = event.currentTarget.getBoundingClientRect();
        if (box.width === 0 || box.height === 0) {
            return;
        }
        addPoint(
            Math.round(((event.clientX - box.left) / box.width) * 100),
            Math.round(((event.clientY - box.top) / box.height) * 100),
        );
    };

    const canvas = (
        <div className="relative max-w-full overflow-x-auto">
            {/* The canvas takes a pointer placement in points mode. It is
                deliberately not focusable and carries no role: the operable
                controls are the hotspot buttons and the coordinate inputs of
                the linear alternative below, which is what carries the
                keyboard and single-input routes. */}
            <div
                className="relative inline-block max-w-full"
                onClick={onCanvasClick}
                data-vy-canvas={mode}
            >
                <img
                    src={image.src}
                    alt={image.alt}
                    aria-describedby={describedBy(image.longDescription !== undefined && describedId)}
                    className="block h-auto max-w-full"
                />
                {mode === 'regions'
                    ? regions.map((region) => {
                          const selected = value.includes(region.key);
                          return (
                              <button
                                  key={region.key}
                                  type="button"
                                  aria-pressed={selected}
                                  aria-label={region.label}
                                  disabled={readOnly}
                                  onClick={() => toggleRegion(region.key)}
                                  style={{
                                      left: `${region.left}%`,
                                      top: `${region.top}%`,
                                      width: `${region.width}%`,
                                      height: `${region.height}%`,
                                  }}
                                  className={cn(
                                      'absolute flex min-h-[24px] min-w-[24px] cursor-pointer items-center justify-center',
                                      region.shape === 'ellipse' ? 'rounded-full' : 'rounded',
                                      // A two-tone stroke, because a single
                                      // mid-grey hairline over author-supplied
                                      // photography has no guaranteed contrast:
                                      // over a mid-grey area of a diagram the
                                      // ratio approaches 1:1 (SC 1.4.11, which
                                      // the design names for a hotspot outline
                                      // by name). The halo the ring class adds
                                      // is the opposite tone, so one of the two
                                      // always reads whatever the backdrop is,
                                      // and the unselected weight went from 1px
                                      // to 2px so the border-weight channel
                                      // still reads at 200 percent zoom.
                                      'ring-2 ring-surface ring-offset-0',
                                      selected
                                          ? 'border-[3px] border-accent bg-accent-soft/60 font-bold text-accent'
                                          : 'border-2 border-dashed border-fg bg-surface/10',
                                  )}
                              >
                                  <span aria-hidden="true" className="text-[13px] font-bold">
                                      {selected ? '✓' : ''}
                                  </span>
                              </button>
                          );
                      })
                    : points.map((point, index) => (
                          <span
                              key={`${point.x}:${point.y}:${index}`}
                              aria-hidden="true"
                              style={{ left: `${point.x}%`, top: `${point.y}%` }}
                              className="absolute -ml-3 -mt-3 flex h-6 w-6 items-center justify-center rounded-full border-2 border-accent bg-accent-soft text-[11px] font-bold text-accent ring-2 ring-surface"
                          >
                              {index + 1}
                          </span>
                      ))}
            </div>
            {image.longDescription !== undefined && (
                <p id={describedId} className="mt-2 max-w-[62ch] text-[12.5px] leading-[1.6] text-fg-2">
                    {image.longDescription}
                </p>
            )}
        </div>
    );

    const linearList =
        mode === 'regions' ? (
            <ul className="grid gap-1.5">
                {regions.map((region) => {
                    const selected = value.includes(region.key);
                    return (
                        <li key={region.key}>
                            <label className="flex min-h-[44px] items-center gap-2.5 text-sm">
                                <input
                                    type={selection === 'single' ? 'radio' : 'checkbox'}
                                    name={controlId('linear')}
                                    checked={selected}
                                    disabled={readOnly}
                                    onChange={() => toggleRegion(region.key)}
                                    className="h-4 w-4 accent-accent"
                                />
                                <span className={cn(selected && 'font-medium')}>{region.label}</span>
                            </label>
                        </li>
                    );
                })}
            </ul>
        ) : (
            <div className="grid gap-2">
                <ul className="grid gap-1.5">
                    {points.map((point, index) => (
                        <li key={`${point.x}:${point.y}:${index}`} className="flex min-h-[44px] items-center gap-2.5 text-sm">
                            <span className="min-w-0 flex-1">
                                {point.label === undefined || point.label === ''
                                    ? strings.pointAt(point.x, point.y)
                                    : strings.namedPointAt(point.label, point.x, point.y)}
                            </span>
                            <button
                                type="button"
                                aria-label={
                                    point.label === undefined || point.label === ''
                                        ? strings.removePoint(index + 1)
                                        : strings.removeNamedPoint(point.label)
                                }
                                disabled={readOnly}
                                onClick={() => {
                                    onPointsChange?.(points.filter((_p, i) => i !== index));
                                    announce('status', strings.pointRemoved(index + 1));
                                }}
                                className={buttonTarget}
                            >
                                <Icon icon={X} size={14} />
                            </button>
                        </li>
                    ))}
                    {points.length === 0 && <li className="text-[12.5px] text-fg-2">{strings.noPoints}</li>}
                </ul>
                <div className="flex flex-wrap items-end gap-2">
                    <label className="grid gap-1 text-[11.5px] text-fg-2">
                        {uiText("vidyalms.ui.2d711642b726b044", "x")}<input
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={100}
                            value={draft.x}
                            disabled={readOnly}
                            aria-label={uiText("vidyalms.ui.8cab4b4d92c83e3a", "{p0} x", { p0: strings.valueLabel })}
                            onChange={(event) => setDraft((d) => ({ ...d, x: event.target.value }))}
                            className="h-[34px] w-20 rounded border border-border bg-surface px-2 text-sm"
                        />
                    </label>
                    <label className="grid gap-1 text-[11.5px] text-fg-2">
                        {uiText("vidyalms.ui.a1fce4363854ff88", "y")}<input
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={100}
                            value={draft.y}
                            disabled={readOnly}
                            aria-label={uiText("vidyalms.ui.7d99072287a4635a", "{p0} y", { p0: strings.valueLabel })}
                            onChange={(event) => setDraft((d) => ({ ...d, y: event.target.value }))}
                            className="h-[34px] w-20 rounded border border-border bg-surface px-2 text-sm"
                        />
                    </label>
                    <button
                        type="button"
                        disabled={readOnly}
                        onClick={() => {
                            const x = Number(draft.x);
                            const y = Number(draft.y);
                            if (draft.x === '' || draft.y === '' || !Number.isFinite(x) || !Number.isFinite(y)) {
                                return;
                            }
                            addPoint(Math.round(x), Math.round(y));
                            setDraft({ x: '', y: '' });
                        }}
                        className="min-h-[44px] cursor-pointer rounded border border-border bg-surface px-3 text-sm font-semibold"
                    >
                        {strings.addPoint}
                    </button>
                </div>
            </div>
        );

    return (
        <div
            className={className}
            role="group"
            aria-labelledby={labelledBy}
            aria-label={labelledBy === undefined ? label : undefined}
            aria-describedby={describedBy(error !== undefined && errorId)}
        >
            {presentation === 'rich' ? canvas : null}
            <LinearAlternative>{linearList}</LinearAlternative>
            <InlineError id={errorId} message={error} />
        </div>
    );
}
