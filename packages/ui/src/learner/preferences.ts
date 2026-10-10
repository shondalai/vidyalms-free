/** Progressive enhancement only: the CMS owns validation, CSRF and saving. */
export function enhancePreferenceForms(root: ParentNode = document): () => void {
    const controller = new AbortController();
    const { signal } = controller;
    root.querySelectorAll<HTMLFormElement>('[data-vy-preferences-form]').forEach((form) => {
        const fields = [...form.querySelectorAll<HTMLSelectElement>('select[name]:not(:disabled)')];
        const save = form.querySelector<HTMLButtonElement>('[data-vy-preferences-save]');
        const reset = form.querySelector<HTMLButtonElement>('[data-vy-preferences-reset]');
        const status = form.querySelector<HTMLElement>('[data-vy-preferences-status]');
        const saved = form.parentElement?.querySelector<HTMLElement>('[data-vy-preferences-saved]');
        const saveLabel = save?.textContent ?? '';
        let submitting = false;
        const defaults = fields.map((field) => field.dataset.vyOriginalValue ?? [...field.options].find((option) => option.defaultSelected)?.value ?? field.options[0]?.value ?? '');
        const isDirty = () => fields.some((field, index) => field.value !== defaults[index]);
        const update = () => {
            if (signal.aborted) return;
            submitting = false;
            const dirty = isDirty();
            form.dataset.vyDirty = String(dirty);
            form.removeAttribute('aria-busy');
            if (save) {
                save.disabled = !dirty;
                save.textContent = saveLabel;
            }
            if (reset) {
                reset.hidden = !dirty;
                reset.disabled = false;
            }
            if (status) status.textContent = (dirty ? form.dataset.vyDirtyLabel : form.dataset.vyCleanLabel) ?? '';
            if (saved && dirty) saved.hidden = true;
        };
        form.addEventListener('change', update, { signal });
        // Reset events fire before the browser restores the default selections.
        form.addEventListener('reset', () => queueMicrotask(() => {
            if (signal.aborted) return;
            fields.forEach((field, index) => {
                field.value = defaults[index]!;
            });
            update();
        }), { signal });
        // Failed native saves retain draft selections; protect those drafts too.
        window.addEventListener('vidyalms:check-drafts', (event) => {
            if (isDirty()) event.preventDefault();
        }, { signal });
        window.addEventListener('beforeunload', (event) => {
            if (isDirty() && !submitting) {
                event.preventDefault();
                event.returnValue = '';
            }
        }, { signal });
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            submitting = true;
            form.setAttribute('aria-busy', 'true');
            if (save) {
                save.disabled = true;
                save.textContent = form.dataset.vySavingLabel ?? saveLabel;
            }
            if (reset) reset.disabled = true;
            if (status) status.textContent = form.dataset.vySavingLabel ?? '';
        }, { signal });
        window.addEventListener('pageshow', update, { signal });
        update();
    });
    return () => controller.abort();
}

export function startPreferenceForms(): () => void {
    let cleanup = enhancePreferenceForms();
    const mount = () => {
        cleanup();
        cleanup = enhancePreferenceForms();
    };
    const unmount = () => cleanup();
    window.addEventListener('vidyalms:learner:mount', mount);
    window.addEventListener('vidyalms:learner:unmount', unmount);
    return () => {
        cleanup();
        window.removeEventListener('vidyalms:learner:mount', mount);
        window.removeEventListener('vidyalms:learner:unmount', unmount);
    };
}
