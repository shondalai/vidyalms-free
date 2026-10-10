import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { useForm } from 'react-hook-form';
import userEvent from '@testing-library/user-event';
import { RadioGroup, SelectField, TextField } from './field';

it('registers admin field defaults and edits with React Hook Form', async () => {
    const user = userEvent.setup();
    const saved = vi.fn();
    function Form() {
        const { register, handleSubmit } = useForm({ defaultValues: { title: 'Original', rule: 'all' } });
        return <form onSubmit={handleSubmit((values) => saved(values))}>
            <TextField label="Title" {...register('title', { required: true })} />
            <SelectField label="Completion rule" {...register('rule')}>
                <option value="all">All lessons</option><option value="quiz">Final quiz</option>
            </SelectField>
            <button type="submit">Save</button>
        </form>;
    }
    render(<Form />);
    const title = screen.getByRole('textbox', { name: 'Title' });
    expect(title).toHaveValue('Original');
    expect(screen.getByRole('combobox')).toHaveValue('all');
    await user.clear(title);
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(title).toHaveFocus());
    expect(saved).not.toHaveBeenCalled();
    await user.type(title, 'Updated');
    await user.selectOptions(screen.getByRole('combobox'), 'quiz');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(saved).toHaveBeenCalledWith({ title: 'Updated', rule: 'quiz' }));
});

/*
 * Foundations piece 11: the label is always there, the hint and the error
 * are associated, and an invalid control says so.
 */

describe('TextField', () => {
    it('labels the input, hidden label included', () => {
        render(
            <>
                <TextField label="Title" />
                <TextField label="Search" labelHidden type="search" />
            </>,
        );
        expect(screen.getByRole('textbox', { name: 'Title' })).toBeInTheDocument();
        expect(screen.getByRole('searchbox', { name: 'Search' })).toBeInTheDocument();
    });

    it('associates the hint until an error replaces it', () => {
        const { rerender } = render(<TextField label="Expiry date" hint="Pick a date in the future." />);
        expect(screen.getByRole('textbox', { name: 'Expiry date' })).toHaveAccessibleDescription(
            'Pick a date in the future.',
        );

        rerender(<TextField label="Expiry date" hint="Pick a date in the future." error="Pick a date." />);
        const input = screen.getByRole('textbox', { name: 'Expiry date' });
        expect(input).toHaveAccessibleDescription('Pick a date.');
        expect(input).toHaveAttribute('aria-invalid', 'true');
    });
});

describe('SelectField', () => {
    it('labels the select and reports its error', () => {
        render(
            <SelectField label="Course" error="Choose a course.">
                <option value="">Choose a course</option>
                <option value="1">Foundations</option>
            </SelectField>,
        );
        const select = screen.getByRole('combobox', { name: 'Course' });
        expect(select).toHaveAttribute('aria-invalid', 'true');
        expect(select).toHaveAccessibleDescription('Choose a course.');
    });
});

describe('RadioGroup', () => {
    it('is a named group of radios and reports the choice', async () => {
        const onChange = vi.fn();
        render(
            <RadioGroup
                legend="Access expires"
                name="expiry"
                value="never"
                options={[
                    { value: 'never', label: 'Never' },
                    { value: 'date', label: 'On a date' },
                ]}
                onChange={onChange}
            />,
        );
        expect(screen.getByRole('group', { name: 'Access expires' })).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'Never' })).toBeChecked();

        await userEvent.click(screen.getByRole('radio', { name: 'On a date' }));
        expect(onChange).toHaveBeenCalledWith('date');
    });
});
