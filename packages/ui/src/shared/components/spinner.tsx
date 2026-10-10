import type { ComponentProps } from 'react';
import { Loader2 } from 'lucide-react';
import { cn } from '../cn';
import { sharedStrings } from '../strings';
import { VisuallyHidden } from './visually-hidden';

/*
 * Skeletons are the default loading state (artboard: no spinners over
 * 300ms); the spinner is for small inline waits like a submitting button.
 */
export interface SpinnerProps extends ComponentProps<'span'> {
    /** Accessible loading text; defaults to the shared "Loading". */
    label?: string;
    size?: number;
}

export function Spinner({ label, size = 16, className, ...props }: SpinnerProps) {
    return (
        <span role="status" className={cn('inline-flex items-center', className)} {...props}>
            <Loader2 aria-hidden="true" size={size} className="animate-spin text-fg-2 motion-reduce:animate-none" />
            <VisuallyHidden>{label ?? sharedStrings.loading}</VisuallyHidden>
        </span>
    );
}
