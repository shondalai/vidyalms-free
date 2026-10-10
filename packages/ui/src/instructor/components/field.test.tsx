import { createRef, version } from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { useForm } from 'react-hook-form';
import { CheckboxField, Select, TextArea, TextInput } from './field';

describe('instructor field refs', () => {
    it('uses the requested React runtime for the compatibility run', () => {
        if (process.env.VIDYALMS_REACT18_CHECK === '1') expect(version).toMatch(/^18\./);
    });

    it('attaches object and callback refs to native controls and clears them on unmount', () => {
        const input = createRef<HTMLInputElement>();
        const area = createRef<HTMLTextAreaElement>();
        const select = vi.fn();
        const checkbox = vi.fn();
        const { unmount } = render(<>
            <TextInput ref={input} aria-label="Title" invalid />
            <TextArea ref={area} aria-label="Description" />
            <Select ref={select} aria-label="Level"><option value="basic">Basic</option></Select>
            <CheckboxField ref={checkbox} label="Published" hint="Visible to learners" />
        </>);
        expect(input.current).toBe(screen.getByRole('textbox', { name: 'Title' }));
        expect(area.current).toBe(screen.getByRole('textbox', { name: 'Description' }));
        expect(select).toHaveBeenLastCalledWith(screen.getByRole('combobox'));
        expect(checkbox).toHaveBeenLastCalledWith(screen.getByRole('checkbox'));
        expect(input.current).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('checkbox')).toHaveAccessibleDescription('Visible to learners');
        input.current?.focus();
        expect(input.current).toHaveFocus();
        unmount();
        expect(input.current).toBeNull();
        expect(area.current).toBeNull();
        expect(select.mock.calls.at(-1)?.[0]).toBeNull();
        expect(checkbox.mock.calls.at(-1)?.[0]).toBeNull();
    });

    it('registers defaults, focuses invalid fields, submits edits and resets through React Hook Form', async () => {
        const user = userEvent.setup();
        const saved = vi.fn();
        function Form() {
            const { register, handleSubmit, reset, setFocus } = useForm({
                defaultValues: { title: 'Original', description: 'Description', level: 'basic', published: true },
            });
            return <form onSubmit={handleSubmit((values) => saved(values))}>
                <TextInput aria-label="Title" {...register('title', { required: true })} />
                <TextArea aria-label="Description" {...register('description')} />
                <Select aria-label="Level" {...register('level')}>
                    <option value="basic">Basic</option><option value="advanced">Advanced</option>
                </Select>
                <CheckboxField label="Published" {...register('published')} />
                <button type="submit">Save</button>
                <button type="button" onClick={() => reset()}>Reset</button>
                <button type="button" onClick={() => setFocus('description')}>Focus description</button>
            </form>;
        }
        render(<Form />);
        const title = screen.getByRole('textbox', { name: 'Title' });
        const description = screen.getByRole('textbox', { name: 'Description' });
        expect(title).toHaveValue('Original');
        expect(description).toHaveValue('Description');
        expect(screen.getByRole('combobox')).toHaveValue('basic');
        expect(screen.getByRole('checkbox')).toBeChecked();
        await user.clear(title);
        await user.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(title).toHaveFocus());
        expect(saved).not.toHaveBeenCalled();
        await user.type(title, 'Updated');
        await user.clear(description);
        await user.type(description, 'Edited description');
        await user.selectOptions(screen.getByRole('combobox'), 'advanced');
        await user.click(screen.getByRole('checkbox'));
        await user.click(screen.getByRole('button', { name: 'Focus description' }));
        expect(description).toHaveFocus();
        await user.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(saved).toHaveBeenCalledWith({
            title: 'Updated', description: 'Edited description', level: 'advanced', published: false,
        }));
        await user.click(screen.getByRole('button', { name: 'Reset' }));
        expect(title).toHaveValue('Original');
        expect(description).toHaveValue('Description');
        expect(screen.getByRole('combobox')).toHaveValue('basic');
        expect(screen.getByRole('checkbox')).toBeChecked();
    });
});
