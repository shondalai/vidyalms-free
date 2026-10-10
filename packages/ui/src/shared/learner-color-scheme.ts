/** Resolve the closest host theme without storing a second learner preference. */
type Scheme = 'light' | 'dark' | 'auto';
const attributes = ['data-color-scheme', 'data-bs-theme', 'data-theme'];
const darkClasses = ['dark', 'dark-mode', 'theme-dark'];
const lightClasses = ['light', 'light-mode', 'theme-light'];

export function learnerColorScheme(root: HTMLElement): Scheme {
    for (let node: HTMLElement | null = root; node; node = node.parentElement) {
        for (const attribute of attributes) {
            const value = node.getAttribute(attribute)?.toLowerCase();
            if (value === 'light' || value === 'dark') return value;
            if (value === 'auto' || value === 'system') return 'auto';
        }
        if (darkClasses.some((name) => node.classList.contains(name))) return 'dark';
        if (lightClasses.some((name) => node.classList.contains(name))) return 'light';
    }
    // CSS-only templates can declare a used scheme without a data attribute.
    // Read the parent, never our own resolved value (which would latch it).
    const parent = root.parentElement;
    const cssScheme = parent ? (getComputedStyle(parent).colorScheme || '').split(/\s+/) : [];
    // "normal" is what a host that never opted into dark computes: it paints light.
    if (cssScheme.length === 1 && cssScheme[0] === 'normal') return 'light';
    const light = cssScheme.includes('light');
    const dark = cssScheme.includes('dark');
    return light !== dark ? (dark ? 'dark' : 'light') : 'auto';
}

export function observeLearnerColorScheme(root: HTMLElement): () => void {
    const previous = root.getAttribute('data-vy-color-scheme');
    const sync = () => {
        const scheme = learnerColorScheme(root);
        if (root.dataset.vyColorScheme !== scheme) root.dataset.vyColorScheme = scheme;
    };
    sync();
    const observer = new MutationObserver(sync);
    for (let node: HTMLElement | null = root; node; node = node.parentElement) {
        observer.observe(node, { attributes: true, attributeFilter: [...attributes, 'class', 'style'] });
    }
    const media = window.matchMedia?.('(prefers-color-scheme: dark)');
    media?.addEventListener('change', sync);
    return () => {
        observer.disconnect();
        media?.removeEventListener('change', sync);
        if (previous === null) delete root.dataset.vyColorScheme;
        else root.dataset.vyColorScheme = previous;
    };
}
