import { translate as uiText } from '@/shared/i18n';

/**
 * A fetched page is content, not a trusted executable asset manifest. A new
 * mount bundle requires native navigation so the CMS emits its own scripts
 * under the page's CSP. Never copy a nonce onto authored page content.
 */
export function createLearnerAssetLoader() {
    const loaded = new Set<string>();
    document.querySelectorAll<HTMLScriptElement | HTMLLinkElement>('script[src],link[rel=stylesheet][href]').forEach((el) => {
        if (!el.closest('[data-vy-learner-shell]')) loaded.add(el instanceof HTMLScriptElement ? el.src : el.href);
    });
    const requireLoaded = async (page: Document, base: string, selector: string, attribute: string) => {
        for (const source of page.querySelectorAll(selector)) {
            const url = new URL(source.getAttribute(attribute)!, base).href;
            if (loaded.has(url)) continue;
            if ([...loaded].some((prior) => new URL(prior).pathname === new URL(url).pathname)) {
                throw new Error(uiText('vidyalms.ui.f4722d0bc5e030fa', 'The learning application was updated.'));
            }
            throw new Error('This learning page needs a full page load.');
        }
    };
    return {
        styles: (page: Document, base: string) => requireLoaded(page, base, 'link[data-vy-spa-asset][rel=stylesheet][href]', 'href'),
        scripts: (page: Document, base: string) => requireLoaded(page, base, 'script[data-vy-spa-asset][src]', 'src'),
    };
}
