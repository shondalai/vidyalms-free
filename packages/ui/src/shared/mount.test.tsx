import { createContext, useEffect } from 'react';
import { act, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { mountApp } from './mount';
import { mountConfigBaseSchema, type MountConfigBase } from './config';

it('disposes a public React root and remounts once with fresh server configuration after returning', async () => {
    const context = createContext<MountConfigBase | null>(null);
    const cleanup = vi.fn();
    function View({ name }: { name: string }) {
        useEffect(() => cleanup, []);
        return <h1>{name}</h1>;
    }
    const insert = (name: string) => {
        const el = document.createElement('div');
        el.id = 'learner-lifecycle-test';
        el.dataset.vyConfig = JSON.stringify({
            contract: 1, api: { base: '/api', mode: 'prefix', headers: { 'X-CSRF-Token': name } },
            user: { id: 1, name }, locale: 'en-GB',
        });
        document.body.append(el);
        return el;
    };
    const initial = insert('First request');
    await act(async () => mountApp({ mountId: initial.id, schema: mountConfigBaseSchema, context,
        learnerNavigation: true, render: (config) => <View name={config.user.name} /> }));
    expect(screen.getByRole('heading')).toHaveTextContent('First request');
    cleanup.mockClear();
    await act(async () => {
        window.dispatchEvent(new Event('vidyalms:learner:unmount'));
    });
    expect(cleanup).toHaveBeenCalledOnce();
    initial.remove();
    const next = insert('New request');
    await act(async () => {
        window.dispatchEvent(new Event('vidyalms:learner:mount'));
        window.dispatchEvent(new Event('vidyalms:learner:mount'));
    });
    expect(screen.getAllByRole('heading')).toHaveLength(1);
    expect(screen.getByRole('heading')).toHaveTextContent('New request');
    await act(async () => {
        window.dispatchEvent(new Event('vidyalms:learner:unmount'));
    });
    next.remove();
});
