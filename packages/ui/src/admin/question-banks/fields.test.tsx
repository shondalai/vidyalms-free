import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useForm } from 'react-hook-form';
import { describe, expect, it, vi } from 'vitest';
import { BankInput, BankSelect, BankTextArea } from './fields';

describe('question bank forms', () => {
    it('loads stored fields and submits edits through native refs', async () => {
        const save = vi.fn();
        const user = userEvent.setup();
        function Form() {
            const { register, handleSubmit } = useForm({
                defaultValues: { prompt: 'Original prompt', points: '1', feedback: 'Original feedback' },
            });
            return <form onSubmit={handleSubmit(save)}>
                <BankInput aria-label="Prompt" {...register('prompt', { required: true })} />
                <BankSelect aria-label="Points" {...register('points')}>
                    <option value="1">1</option><option value="2">2</option>
                </BankSelect>
                <BankTextArea aria-label="Feedback" {...register('feedback')} />
                <button type="submit">Save</button>
            </form>;
        }
        render(<Form />);
        expect(screen.getByLabelText('Prompt')).toHaveValue('Original prompt');
        expect(screen.getByLabelText('Feedback')).toHaveValue('Original feedback');
        await user.clear(screen.getByLabelText('Prompt'));
        await user.type(screen.getByLabelText('Prompt'), 'Updated prompt');
        await user.selectOptions(screen.getByLabelText('Points'), '2');
        await user.clear(screen.getByLabelText('Feedback'));
        await user.type(screen.getByLabelText('Feedback'), 'Updated feedback');
        await user.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(save.mock.calls[0]?.[0]).toEqual({
            prompt: 'Updated prompt', points: '2', feedback: 'Updated feedback',
        }));
    });
});
