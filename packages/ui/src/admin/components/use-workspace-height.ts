import { useLayoutEffect, useRef } from 'react';

/** Fit below the actual CMS chrome, including notices and the workspace border. */
export function useWorkspaceHeight() {
    const ref = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const workspace = ref.current;
        if (workspace === null) return;

        const mount = workspace.closest<HTMLElement>('#vidyalms-admin');
        const joomla = document.body.classList.contains('com_vidyalms') && mount !== null;
        const headerProperty = '--_vy-admin-header-height';
        const bottomProperty = '--_vy-admin-bottom-height';
        const previousHeader = document.body.style.getPropertyValue(headerProperty);
        const previousBottom = document.body.style.getPropertyValue(bottomProperty);
        let frame = 0;
        const observed = new Set<Element>();
        const pixels = (element: HTMLElement, property: string, value: number) => {
            const next = `${Math.max(0, value)}px`;
            if (element.style.getPropertyValue(property) !== next) element.style.setProperty(property, next);
        };
        const measure = () => {
            frame = 0;
            const style = getComputedStyle(workspace);
            // Document coordinates stay stable when the host page itself scrolls.
            const top = workspace.getBoundingClientRect().top + window.scrollY;
            const border = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
            const header = joomla ? document.getElementById('header') : null;
            const actions = header?.querySelector<HTMLElement>('.header-items');
            const notices = joomla ? document.getElementById('system-message-container') : null;
            let bottom = 0;
            if (actions) {
                const rect = actions.getBoundingClientRect();
                const css = getComputedStyle(actions);
                if (css.position === 'fixed' && css.display !== 'none' && css.visibility !== 'hidden'
                    && rect.height > 0 && rect.bottom >= window.innerHeight - 1) {
                    bottom = Math.min(window.innerHeight, Math.max(0, window.innerHeight - rect.top));
                }
            }
            pixels(workspace, '--_vy-workspace-offset', Math.max(0, top) + (Number.isFinite(border) ? border : 0) + bottom);
            if (joomla) {
                pixels(mount, '--_vy-admin-offset', Math.max(0, mount.getBoundingClientRect().top + window.scrollY) + bottom);
                pixels(document.body, headerProperty, header?.getBoundingClientRect().height ?? Math.max(0, top));
                pixels(document.body, bottomProperty, bottom);
            }
            const targets = new Set<Element>([header, actions, notices].filter((node): node is HTMLElement => Boolean(node)));
            // Keep the existing embedded/WordPress host responsive too.
            for (let element: HTMLElement | null = workspace; element !== null; element = element.parentElement) targets.add(element);
            for (const node of observed) {
                if (!targets.has(node)) {
                    observer?.unobserve(node);
                    observed.delete(node);
                }
            }
            for (const node of targets) {
                if (!observed.has(node)) {
                    observer?.observe(node);
                    observed.add(node);
                }
            }
        };
        const schedule = () => {
            if (!frame) frame = requestAnimationFrame(measure);
        };
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(schedule);
        measure();
        schedule();
        const mutations = typeof MutationObserver === 'undefined' ? null : new MutationObserver(schedule);
        mutations?.observe(mount?.closest('main') ?? workspace.parentElement ?? workspace, { childList: true, subtree: true });
        window.addEventListener('resize', schedule);
        window.addEventListener('scroll', schedule, { passive: true });
        window.addEventListener('hashchange', schedule);
        return () => {
            cancelAnimationFrame(frame);
            window.removeEventListener('resize', schedule);
            window.removeEventListener('scroll', schedule);
            window.removeEventListener('hashchange', schedule);
            observer?.disconnect();
            mutations?.disconnect();
            if (joomla) {
                if (previousHeader) document.body.style.setProperty(headerProperty, previousHeader);
                else document.body.style.removeProperty(headerProperty);
                if (previousBottom) document.body.style.setProperty(bottomProperty, previousBottom);
                else document.body.style.removeProperty(bottomProperty);
            }
        };
    }, []);

    return ref;
}
