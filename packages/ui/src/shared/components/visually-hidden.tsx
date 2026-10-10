import type { ComponentProps, CSSProperties } from 'react';

/*
 * Inline styles on purpose: this must hide correctly even where the
 * stylesheet has not arrived (portals, tests, the config error path).
 */
const hiddenStyle: CSSProperties = {
    position: 'absolute',
    width: 1,
    height: 1,
    padding: 0,
    margin: -1,
    overflow: 'hidden',
    clip: 'rect(0 0 0 0)',
    whiteSpace: 'nowrap',
    border: 0,
};

/** Text for assistive technology only. */
export function VisuallyHidden({ style, ...props }: ComponentProps<'span'>) {
    return <span style={{ ...hiddenStyle, ...style }} {...props} />;
}
