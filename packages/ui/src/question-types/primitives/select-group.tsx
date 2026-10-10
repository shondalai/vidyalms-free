import { useId } from 'react';
import { cn } from '@/shared/cn';
import { primitiveStrings as strings } from '../strings';
import { InlineError, describedBy, type PrimitiveProps } from './common';
import { bigChoiceClass, checkBoxClass, optionRowClass, radioDotClass } from './styles';

/*
 * SingleSelectGroup and MultiSelectGroup: the client counterparts of the
 * select primitive.
 *
 * Native inputs carry the arrow-key behaviour of a radio group and the
 * checked state of a checkbox, so exactly one element of a radio group is in
 * the tab sequence and the group is operable with no script at all. The
 * input is visually hidden inside its label rather than replaced, which is
 * what keeps the accessible name, the role and the state real.
 *
 * There is never auto-advance on selection, which is failure F36 against SC
 * 3.2.2.
 */

export interface SelectOption {
    key: string;
    label: string;
    /** A "None of the above" option that shuffling must leave in place. */
    fixed?: boolean;
}

export interface SingleSelectGroupProps extends PrimitiveProps {
    options: readonly SelectOption[];
    value: string | null;
    onChange: (key: string) => void;
    /** Two large targets instead of rows, for a two-option item. */
    variant?: 'rows' | 'pair';
    /**
     * The empty option's words, when this select is not an association.
     *
     * It used to be "Not matched" for every caller, because the primitive
     * had one string and the association family needed that one. So a pen
     * colour picker, a tool picker and an inline gap all opened with "Not
     * matched", which is wrong instruction text in the one control that has
     * to be self-explanatory (SC 3.3.2). The association caller passes its
     * own words; everything else gets a neutral one.
     */
    placeholder?: string;
}

export function SingleSelectGroup({
    options,
    value,
    onChange,
    controlId,
    labelledBy,
    label,
    readOnly = false,
    presentation = 'rich',
    error,
    variant = 'rows',
    placeholder,
    className,
}: SingleSelectGroupProps) {
    const name = useId();
    const errorId = controlId('error');
    const groupId = controlId('group');

    if (presentation === 'linear') {
        return (
            <div className={className}>
                <select
                    id={groupId}
                    aria-labelledby={labelledBy}
                    aria-label={labelledBy === undefined ? label : undefined}
                    aria-describedby={describedBy(error !== undefined && errorId)}
                    aria-invalid={error !== undefined ? true : undefined}
                    disabled={readOnly}
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value)}
                    className="min-h-[44px] w-full max-w-[420px] rounded border border-border bg-surface px-3 text-sm text-fg"
                >
                    <option value="">{placeholder ?? strings.notChosen}</option>
                    {options.map((option) => (
                        <option key={option.key} value={option.key}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <InlineError id={errorId} message={error} />
            </div>
        );
    }

    return (
        <div className={className}>
            <div
                id={groupId}
                role="radiogroup"
                aria-labelledby={labelledBy}
                aria-label={labelledBy === undefined ? label : undefined}
                aria-describedby={describedBy(error !== undefined && errorId)}
                aria-invalid={error !== undefined ? true : undefined}
                className={cn(
                    'max-w-[620px] gap-2',
                    variant === 'pair' ? 'grid grid-cols-2 gap-2.5' : 'grid',
                )}
            >
                {options.map((option) => {
                    const selected = value === option.key;
                    return (
                        <label
                            key={option.key}
                            className={variant === 'pair' ? bigChoiceClass(selected) : optionRowClass(selected)}
                        >
                            <input
                                type="radio"
                                name={name}
                                value={option.key}
                                checked={selected}
                                disabled={readOnly}
                                onChange={() => onChange(option.key)}
                                className="sr-only"
                            />
                            {variant === 'pair' ? null : (
                                <span aria-hidden="true" className={radioDotClass(selected)} />
                            )}
                            {option.label}
                            {variant === 'pair' && selected ? <span aria-hidden="true">●</span> : null}
                        </label>
                    );
                })}
            </div>
            <InlineError id={errorId} message={error} />
        </div>
    );
}

export interface MultiSelectGroupProps extends PrimitiveProps {
    options: readonly SelectOption[];
    value: readonly string[];
    onChange: (keys: string[]) => void;
    minChoices?: number;
    maxChoices?: number;
    /** Authored per item, so the limit message is translatable per question. */
    minMessage?: string;
    maxMessage?: string;
}

export function MultiSelectGroup({
    options,
    value,
    onChange,
    controlId,
    labelledBy,
    label,
    announce,
    readOnly = false,
    presentation = 'rich',
    error,
    minChoices,
    maxChoices,
    maxMessage,
    className,
}: MultiSelectGroupProps) {
    const errorId = controlId('error');
    const groupId = controlId('group');

    /*
     * Hitting max_choices announces and refuses the extra selection; it never
     * disables the remaining controls. Silently disabling them makes the
     * interface read as broken to a screen reader user and provides no error
     * text (SC 3.3.1).
     */
    const toggle = (key: string) => {
        if (value.includes(key)) {
            onChange(value.filter((k) => k !== key));
            return;
        }
        if (maxChoices !== undefined && maxChoices > 0 && value.length >= maxChoices) {
            announce('status', maxMessage ?? strings.maxReached(maxChoices));
            return;
        }
        const next = [...value, key];
        onChange(next);
        if (maxChoices !== undefined && maxChoices > 0 && next.length === maxChoices) {
            announce('status', maxMessage ?? strings.maxReached(maxChoices));
        }
    };

    const rows = options.map((option) => {
        const selected = value.includes(option.key);
        return (
            <label
                key={option.key}
                className={cn(
                    presentation === 'linear'
                        ? cn('flex min-h-[44px] items-center gap-3 py-1 text-sm', selected && 'font-medium')
                        : optionRowClass(selected),
                )}
            >
                <input
                    type="checkbox"
                    value={option.key}
                    checked={selected}
                    disabled={readOnly}
                    onChange={() => toggle(option.key)}
                    className={presentation === 'linear' ? 'h-4 w-4 accent-accent' : 'sr-only'}
                />
                {presentation === 'linear' ? null : (
                    <span aria-hidden="true" className={checkBoxClass(selected)}>
                        {selected ? '✓' : null}
                    </span>
                )}
                {option.label}
            </label>
        );
    });

    return (
        <div className={className}>
            <div
                id={groupId}
                role="group"
                aria-labelledby={labelledBy}
                aria-label={labelledBy === undefined ? label : undefined}
                aria-describedby={describedBy(error !== undefined && errorId)}
                aria-invalid={error !== undefined ? true : undefined}
                className="grid max-w-[620px] gap-2"
            >
                {rows}
            </div>
            {minChoices !== undefined && minChoices > 0 && maxChoices !== undefined && maxChoices > 0 && (
                <p className="mt-1.5 text-[11.5px] text-fg-2">{strings.selectedOf(value.length, maxChoices)}</p>
            )}
            <InlineError id={errorId} message={error} />
        </div>
    );
}
