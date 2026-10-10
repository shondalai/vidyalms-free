import { translate as uiText } from '@/shared/i18n';
import { cn } from '@/shared/cn';
import { questionTypeStrings } from '../strings';
import { InlineError, describedBy, type PrimitiveProps } from './common';
import { textBoxClass } from './styles';

/*
 * TextEntry: the client counterpart of the text primitive, used by short
 * answer, the gaps of a cloze, numeric entry and extended response.
 *
 * The box grows with its content and never has a fixed height, because a
 * fixed-height box loses content at line height 1.5, paragraph spacing 2,
 * letter spacing 0.12em and word spacing 0.16em (SC 1.4.12). The character
 * or word count is a status, not a warning, and it is described to the field
 * rather than announced on every keystroke.
 *
 * Nothing here ever disables spell checking, the clipboard, text selection
 * or the context menu: those break read-aloud, braille review, glossary
 * lookup and dictation, all of which are named AfA and Smarter Balanced
 * supports.
 */

export interface TextEntryProps extends PrimitiveProps {
    value: string;
    onChange: (value: string) => void;
    multiline?: boolean;
    rows?: number;
    maxLength?: number;
    placeholder?: string;
    /** Inline gaps size themselves to the expected answer, never absolutely. */
    inline?: boolean;
    widthChars?: number;
    hint?: string;
    /** The number is shown, not enforced by truncation. */
    counter?: 'characters' | 'words' | 'none';
    /**
     * Which soft keyboard a touch device offers. "decimal" is what a typed
     * number needs, and it is a hint to the keyboard rather than a filter on
     * the field.
     */
    inputMode?: 'text' | 'decimal' | 'numeric';
    /**
     * A typing mask, passed through as the native pattern attribute. It
     * never intercepts an input event, so paste keeps working: blocking
     * paste breaks password managers and speech to text.
     *
     * A mask is resolved against the learner's locale before it gets here.
     * A decimal separator is a property of the locale, not of the item.
     */
    pattern?: string;
}

function wordCount(text: string): number {
    const trimmed = text.trim();
    return trimmed === '' ? 0 : trimmed.split(/\s+/u).length;
}

export function TextEntry({
    value,
    onChange,
    multiline = false,
    rows = 4,
    maxLength,
    placeholder,
    inline = false,
    widthChars,
    hint,
    counter = 'none',
    inputMode,
    pattern,
    controlId,
    labelledBy,
    label,
    readOnly = false,
    error,
    className,
}: TextEntryProps) {
    const fieldId = controlId('field');
    const errorId = controlId('error');
    const hintId = controlId('hint');
    const countId = controlId('count');

    const described = describedBy(
        hint !== undefined && hintId,
        counter !== 'none' && countId,
        error !== undefined && errorId,
    );

    const shared = {
        id: fieldId,
        value,
        disabled: readOnly,
        placeholder,
        inputMode,
        'aria-labelledby': labelledBy,
        'aria-label': labelledBy === undefined ? label : undefined,
        'aria-describedby': described,
        'aria-invalid': error !== undefined ? true : undefined,
        onChange: (event: { target: { value: string } }) => onChange(event.target.value),
    };

    if (inline) {
        const filled = value.trim() !== '';
        return (
            <>
                <input
                    {...shared}
                    type="text"
                    maxLength={maxLength}
                    pattern={pattern}
                    size={widthChars}
                    className={cn(
                        'mx-0.5 inline-block h-[34px] min-w-[24px] rounded border bg-surface px-2 text-center align-middle font-display text-sm font-semibold text-fg',
                        widthChars === undefined && 'w-16',
                        error !== undefined
                            ? 'border-2 border-danger'
                            : filled
                              ? 'border-accent bg-accent-soft'
                              : 'border-border',
                    )}
                />
                <InlineError id={errorId} message={error} />
            </>
        );
    }

    return (
        <div className={className}>
            {hint !== undefined && (
                <p id={hintId} className="mb-1.5 text-[12px] text-fg-2">
                    {hint}
                </p>
            )}
            {multiline ? (
                <textarea
                    {...shared}
                    rows={rows}
                    maxLength={maxLength}
                    className={cn(textBoxClass(error !== undefined), 'max-w-[620px] resize-y')}
                />
            ) : (
                <input
                    {...shared}
                    type="text"
                    maxLength={maxLength}
                    pattern={pattern}
                    className={cn(textBoxClass(error !== undefined), 'max-w-[620px]')}
                />
            )}
            {counter !== 'none' && (
                <div id={countId} className="mt-1 flex max-w-[620px] justify-end text-[11.5px] text-fg-2">
                    {counter === 'characters'
                        ? questionTypeStrings.charCount(value.length, maxLength ?? 0)
                        : uiText("vidyalms.ui.8af35f198b7f67e5", "{p0}", { p0: wordCount(value) })}
                </div>
            )}
            <InlineError id={errorId} message={error} />
        </div>
    );
}
