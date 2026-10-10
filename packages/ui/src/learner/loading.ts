type SkeletonLayout = 'cards' | 'list' | 'detail';

function layoutFor(root: HTMLElement, url: string, trigger?: Element): SkeletonLayout {
    const destination = new URL(url, document.baseURI);
    const matches = (link: HTMLAnchorElement) => link.pathname === destination.pathname && link.search === destination.search;
    const page = [...root.querySelectorAll<HTMLAnchorElement>('a[data-vy-page]')].find(matches)?.dataset.vyPage;
    if (trigger?.matches('form[data-vy-page-form]')) return 'cards';
    if (page === 'catalogue' || page === 'mylearning' || page === 'achievements') return 'cards';
    return page ? 'list' : 'detail';
}

const line = (size = '') => `<span class="vy-skeleton-block vy-skeleton-line ${size}"></span>`;
const heading = `<div class="vy-skeleton-heading">${line('vy-skeleton-kicker')}${line('vy-skeleton-title')}${line('vy-skeleton-description')}</div>`;
const card = `<div class="vy-skeleton-card"><div class="vy-skeleton-block vy-skeleton-cover"></div><div class="vy-skeleton-card-body">${line()}${line('vy-skeleton-short')}${line('vy-skeleton-meta')}</div></div>`;
const rows = `<div class="vy-skeleton-rows">${Array.from({ length: 5 }, () => `<div class="vy-skeleton-row"><span class="vy-skeleton-block vy-skeleton-icon"></span><div>${line()}${line('vy-skeleton-short')}</div><span class="vy-skeleton-block vy-skeleton-action"></span></div>`).join('')}</div>`;

/** Keep the previous DOM alive for draft/error recovery while showing only placeholders. */
export function showPageSkeleton(root: HTMLElement, url: string, trigger?: Element): () => void {
    const content = root.querySelector<HTMLElement>('.vy-learning-content');
    if (!content) return () => {};
    const layout = layoutFor(root, url, trigger);
    const wasInert = content.hasAttribute('inert');
    const wasBusy = content.getAttribute('aria-busy');
    const skeleton = document.createElement('div');
    skeleton.className = 'vy-page-skeleton';
    skeleton.dataset.vySkeleton = layout;
    skeleton.setAttribute('aria-hidden', 'true');
    const frame = document.createElement('div');
    frame.className = 'vy-skeleton-frame';
    frame.innerHTML = heading + (layout === 'cards'
        ? `<div class="vy-skeleton-toolbar">${line()}<span class="vy-skeleton-block vy-skeleton-action"></span></div><div class="vy-skeleton-cards">${card.repeat(3)}</div>`
        : layout === 'list' ? rows
            : `<div class="vy-skeleton-detail"><div><div class="vy-skeleton-block vy-skeleton-media"></div>${line()}${line('vy-skeleton-short')}</div><div class="vy-skeleton-aside">${line()}${rows}</div></div>`);
    skeleton.append(frame);
    const status = document.createElement('span');
    status.className = 'vy-loading-status';
    status.setAttribute('role', 'status');
    status.textContent = root.dataset.vyLoadingLabel || 'Loading page…';
    // The announcement and navigation stay outside the inert/busy content region.
    root.append(status);
    root.dataset.vyPageLoading = 'true';
    content.dataset.vyLoading = 'true';
    content.setAttribute('inert', '');
    content.setAttribute('aria-busy', 'true');
    content.append(skeleton);
    return () => {
        skeleton.remove();
        status.remove();
        delete root.dataset.vyPageLoading;
        delete content.dataset.vyLoading;
        if (!wasInert) content.removeAttribute('inert');
        if (wasBusy === null) content.removeAttribute('aria-busy');
        else content.setAttribute('aria-busy', wasBusy);
    };
}
