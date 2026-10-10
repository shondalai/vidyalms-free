import { useRef, useState, type DragEvent, type ReactNode } from 'react';
import { ArrowDown, ArrowUp, GripVertical } from 'lucide-react';
import { cn } from '@/shared/cn';
import { Icon } from '@/shared/components';

/*
 * Artboard 1a: "every module and lesson has move buttons AND a drag handle;
 * order is never implicit". The buttons are the primary control because
 * they work for everyone; the handle is the pointer shortcut on top.
 *
 * The handle is a real button, so it is reachable and operable by keyboard
 * too: focus it and the arrow keys move the row it belongs to, which is the
 * same operation the two move buttons perform. Native HTML drag and drop
 * carries the row index in dataTransfer, and a drop outside the list leaves
 * the order alone.
 */

export interface DragState {
    from: number | null;
    over: number | null;
}

const EMPTY_DRAG: DragState = { from: null, over: null };

export interface UseListDragOptions {
    /**
     * Which list this is. The curriculum nests a lesson list inside a module
     * row, and both are drag lists, so an index alone is ambiguous: it names
     * a row of a list nobody said which.
     */
    listId: string;
    /** Called with the two indexes of a completed drop. */
    onDrop: (from: number, to: number) => void;
}

export interface DragRowProps {
    draggable: true;
    onDragStart: (event: DragEvent<HTMLElement>) => void;
    onDragOver: (event: DragEvent<HTMLElement>) => void;
    onDragLeave: () => void;
    onDrop: (event: DragEvent<HTMLElement>) => void;
    onDragEnd: () => void;
}

export interface ListDrag {
    state: DragState;
    /** Props for the row at `index`; spread them on the row element. */
    rowProps: (index: number) => DragRowProps;
    /** True while a dragged row hovers this one, for the drop hairline. */
    isOver: (index: number) => boolean;
    isDragging: (index: number) => boolean;
}

/** What a row writes into dataTransfer: which list it belongs to, and where. */
const PAYLOAD_FORMAT = 'text/plain';

function payloadFor(listId: string, index: number): string {
    return `vidyalms:${listId}:${index}`;
}

function listOf(payload: string): string | null {
    const parts = payload.split(':');
    return parts.length === 3 && parts[0] === 'vidyalms' ? (parts[1] ?? null) : null;
}

/*
 * Two rules keep nested lists apart, and both are needed.
 *
 * React drag events bubble, so without stopPropagation a dragstart on a
 * lesson row also armed the module list underneath it with the source
 * module's index, and a drop that the lesson list could not use (its own
 * drag was never armed, because the row belonged to a different module's
 * list) fell through to the module list, which reordered the modules and
 * announced that a lesson had moved.
 *
 * stopPropagation alone is not enough either: a drop lands on whichever
 * list the pointer is over, and that list may have no drag of its own in
 * flight while an outer one does. So the payload names its list, and a
 * dragover or a drop whose payload names another list is ignored outright.
 */
export function useListDrag({ listId, onDrop }: UseListDragOptions): ListDrag {
    const [state, setState] = useState<DragState>(EMPTY_DRAG);
    /* The index also lives in a ref: dataTransfer is empty on dragover in
       some browsers, and the drop handler must know where the drag began
       even when the state update has not flushed. */
    const fromRef = useRef<number | null>(null);

    /* Read where the payload is readable (drop), fall back to our own armed
       state where it is not (dragover, in every browser but Firefox). */
    const foreign = (event: DragEvent<HTMLElement>): boolean => {
        const named = listOf(event.dataTransfer.getData(PAYLOAD_FORMAT));
        return named === null ? fromRef.current === null : named !== listId;
    };

    return {
        state,
        isOver: (index) => state.over === index && state.from !== null && state.from !== index,
        isDragging: (index) => state.from === index,
        rowProps: (index) => ({
            draggable: true,
            onDragStart: (event) => {
                event.stopPropagation();
                fromRef.current = index;
                setState({ from: index, over: null });
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData(PAYLOAD_FORMAT, payloadFor(listId, index));
            },
            onDragOver: (event) => {
                if (foreign(event)) {
                    return;
                }
                event.stopPropagation();
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                setState((current) => (current.over === index ? current : { ...current, over: index }));
            },
            onDragLeave: () => {
                setState((current) => (current.over === index ? { ...current, over: null } : current));
            },
            onDrop: (event) => {
                if (foreign(event)) {
                    return;
                }
                event.stopPropagation();
                event.preventDefault();
                const from = fromRef.current;
                fromRef.current = null;
                setState(EMPTY_DRAG);
                if (from !== null && from !== index) {
                    onDrop(from, index);
                }
            },
            onDragEnd: () => {
                fromRef.current = null;
                setState(EMPTY_DRAG);
            },
        }),
    };
}

export interface OrderControlsProps {
    /** Names the thing being moved, e.g. "Move lesson Captions up". */
    upLabel: string;
    downLabel: string;
    dragLabel: string;
    canMoveUp: boolean;
    canMoveDown: boolean;
    onMoveUp: () => void;
    onMoveDown: () => void;
    /** Sizes the buttons down inside a dense row. */
    size?: 'md' | 'sm';
    className?: string;
}

const iconButton =
    'flex cursor-pointer items-center justify-center rounded border border-border bg-surface text-fg disabled:cursor-default disabled:text-fg-2 disabled:opacity-40';

/** The drag handle, keyboard-operable: arrows move the row it belongs to. */
export function DragHandle({
    label,
    canMoveUp,
    canMoveDown,
    onMoveUp,
    onMoveDown,
    className,
}: {
    label: string;
    canMoveUp: boolean;
    canMoveDown: boolean;
    onMoveUp: () => void;
    onMoveDown: () => void;
    className?: string;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            onKeyDown={(event) => {
                if (event.key === 'ArrowUp' && canMoveUp) {
                    event.preventDefault();
                    onMoveUp();
                } else if (event.key === 'ArrowDown' && canMoveDown) {
                    event.preventDefault();
                    onMoveDown();
                }
            }}
            className={cn('shrink-0 cursor-grab rounded text-fg-2 hover:text-fg', className)}
        >
            <Icon icon={GripVertical} size={16} />
        </button>
    );
}

export function OrderControls({
    upLabel,
    downLabel,
    canMoveUp,
    canMoveDown,
    onMoveUp,
    onMoveDown,
    size = 'md',
    className,
}: Omit<OrderControlsProps, 'dragLabel'>) {
    const box = size === 'sm' ? 'h-7 w-7' : 'h-[30px] w-[30px]';
    return (
        <span className={cn('flex shrink-0 gap-1', className)}>
            <button
                type="button"
                aria-label={upLabel}
                disabled={!canMoveUp}
                onClick={onMoveUp}
                className={cn(iconButton, box)}
            >
                <Icon icon={ArrowUp} size={14} />
            </button>
            <button
                type="button"
                aria-label={downLabel}
                disabled={!canMoveDown}
                onClick={onMoveDown}
                className={cn(iconButton, box)}
            >
                <Icon icon={ArrowDown} size={14} />
            </button>
        </span>
    );
}

/** The row wrapper that shows where a dragged row would land. */
export function DropTarget({
    over,
    dragging,
    className,
    children,
    ...props
}: {
    over: boolean;
    dragging: boolean;
    className?: string;
    children: ReactNode;
} & Partial<DragRowProps>) {
    return (
        <div
            className={cn(
                'transition-colors duration-1',
                over && 'border-t-2 border-t-accent',
                dragging && 'opacity-50',
                className,
            )}
            {...props}
        >
            {children}
        </div>
    );
}
