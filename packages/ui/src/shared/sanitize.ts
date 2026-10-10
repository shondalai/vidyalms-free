import DOMPurify from 'dompurify';

let hooked = false;

/** Links that open a new tab must not hand the opener to the target page. */
function ensureHook(): void {
    if (hooked) {
        return;
    }
    hooked = true;
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (node instanceof HTMLAnchorElement && node.target !== '') {
            node.setAttribute('rel', 'noopener noreferrer');
        }
    });
}

/**
 * Every piece of authored HTML (reading bodies, assignment instructions)
 * passes through here before dangerouslySetInnerHTML. The profile keeps
 * ordinary article markup and strips scripts, event handlers and foreign
 * content.
 */
export function sanitizeHtml(html: string): string {
    ensureHook();
    return DOMPurify.sanitize(html, {
        USE_PROFILES: { html: true },
        FORBID_TAGS: ['style', 'form', 'input', 'button', 'select', 'textarea', 'dialog'],
        FORBID_ATTR: ['style'],
        ALLOW_DATA_ATTR: false,
        ADD_ATTR: ['target'],
    });
}
