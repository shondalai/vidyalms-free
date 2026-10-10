import { act, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useWorkspaceHeight } from './use-workspace-height';

function Workspace() {
    return <div ref={useWorkspaceHeight()} data-testid="workspace" style={{ border: '1px solid' }} />;
}

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    document.body.className = '';
    document.body.style.removeProperty('--_vy-admin-header-height');
    document.body.style.removeProperty('--_vy-admin-bottom-height');
    document.body.replaceChildren();
});

function harness(joomla = true, previousHeader = '') {
    document.body.className = joomla ? 'com_vidyalms' : 'wp-admin';
    document.body.style.setProperty('--_vy-admin-header-height', previousHeader);
    document.body.style.setProperty('--_vy-admin-bottom-height', previousHeader ? '12px' : '');
    document.body.innerHTML = '<header id="header"><div class="header-items"></div></header><main><div id="system-message-container"></div><div id="vidyalms-admin"></div></main>';
    const mount = document.getElementById('vidyalms-admin')!;
    const header = document.getElementById('header')!;
    const actions = header.querySelector<HTMLElement>('.header-items')!;
    actions.style.position = 'static';
    const dimensions = { top: 80, workspaceTop: 80, header: 48, bottom: 44, scroll: 0 };
    const frames = new Map<number, FrameRequestCallback>();
    let id = 0;
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation(callback => {
        frames.set(++id, callback);
        return id;
    });
    vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(key => {
        frames.delete(key);
    });
    vi.spyOn(window, 'scrollY', 'get').mockImplementation(() => dimensions.scroll);
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
        const top = this === header ? 0 : this === actions ? window.innerHeight - dimensions.bottom
            : this === mount ? dimensions.top : dimensions.workspaceTop;
        const height = this === header ? dimensions.header : this === actions ? dimensions.bottom : 100;
        return { top, bottom: top + height, left: 0, right: 600, width: 600, height, x: 0, y: top, toJSON: () => ({}) };
    });
    const observers: Observer[] = [];
    class Observer {
        targets = new Set<Element>();
        disconnected = false;
        constructor(readonly callback: () => void) { observers.push(this); }
        observe(node: Element) { this.targets.add(node); }
        unobserve(node: Element) { this.targets.delete(node); }
        disconnect() {
            this.targets.clear();
            this.disconnected = true;
        }
    }
    vi.stubGlobal('ResizeObserver', Observer);
    vi.stubGlobal('MutationObserver', Observer);
    const view = render(<Workspace />, { container: mount });
    const workspace = view.getByTestId('workspace');
    const flush = () => act(() => {
        const queued = [...frames.values()];
        frames.clear();
        queued.forEach(callback => callback(0));
    });
    const notify = () => {
        observers.forEach(observer => observer.callback());
        flush();
    };
    return { mount, workspace, actions, dimensions, frames, observers, view, flush, notify };
}

describe('workspace height', () => {
    it('measures Joomla notices and header changes without a window resize', () => {
        const app = harness();
        expect(app.workspace.style.getPropertyValue('--_vy-workspace-offset')).toBe('82px');
        expect(app.mount.style.getPropertyValue('--_vy-admin-offset')).toBe('80px');
        expect(document.body.style.getPropertyValue('--_vy-admin-header-height')).toBe('48px');
        expect(document.body.style.getPropertyValue('--_vy-admin-bottom-height')).toBe('0px');
        expect(app.observers.some(observer => observer.targets.has(document.getElementById('system-message-container')!))).toBe(true);
        app.dimensions.top = app.dimensions.workspaceTop = 150;
        app.dimensions.header = 60;
        app.notify();
        expect(app.workspace.style.getPropertyValue('--_vy-workspace-offset')).toBe('152px');
        expect(app.mount.style.getPropertyValue('--_vy-admin-offset')).toBe('150px');
        expect(document.body.style.getPropertyValue('--_vy-admin-header-height')).toBe('60px');
    });

    it('uses document coordinates and reserves only a visible fixed bottom bar', () => {
        const app = harness();
        app.dimensions.top = app.dimensions.workspaceTop = -20;
        app.dimensions.scroll = 100;
        app.actions.style.position = 'fixed';
        app.notify();
        expect(app.workspace.style.getPropertyValue('--_vy-workspace-offset')).toBe('126px');
        expect(app.mount.style.getPropertyValue('--_vy-admin-offset')).toBe('124px');
        expect(document.body.style.getPropertyValue('--_vy-admin-bottom-height')).toBe('44px');
        app.actions.style.display = 'none';
        app.notify();
        expect(app.mount.style.getPropertyValue('--_vy-admin-offset')).toBe('80px');
        expect(document.body.style.getPropertyValue('--_vy-admin-bottom-height')).toBe('0px');
        app.actions.style.display = '';
        app.actions.style.visibility = 'hidden';
        app.notify();
        expect(app.mount.style.getPropertyValue('--_vy-admin-offset')).toBe('80px');
    });

    it('cleans up pending work, observers and the previous Joomla header value', () => {
        const add = vi.spyOn(window, 'addEventListener');
        const remove = vi.spyOn(window, 'removeEventListener');
        const app = harness(true, '55px');
        app.view.unmount();
        expect(app.frames.size).toBe(0);
        expect(app.observers.every(observer => observer.disconnected)).toBe(true);
        for (const event of ['resize', 'scroll', 'hashchange']) {
            const callback = add.mock.calls.find(call => call[0] === event)?.[1];
            expect(remove).toHaveBeenCalledWith(event, callback);
        }
        expect(document.body.style.getPropertyValue('--_vy-admin-header-height')).toBe('55px');
        expect(document.body.style.getPropertyValue('--_vy-admin-bottom-height')).toBe('12px');
    });

    it('retains embedded WordPress sizing without changing Joomla host properties', () => {
        const app = harness(false);
        app.actions.style.position = 'fixed';
        app.notify();
        expect(app.workspace.style.getPropertyValue('--_vy-workspace-offset')).toBe('82px');
        expect(app.mount.style.getPropertyValue('--_vy-admin-offset')).toBe('');
        expect(document.body.style.getPropertyValue('--_vy-admin-header-height')).toBe('');
        expect(document.body.style.getPropertyValue('--_vy-admin-bottom-height')).toBe('');
    });
});
