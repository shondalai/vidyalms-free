import { Card, Skeleton, VisuallyHidden } from '@/shared/components';
import { quizRunnerStrings as strings } from '../strings';

/**
 * Artboard 3e: a shape-true skeleton of the question card (kicker line,
 * prompt line, three option rows). Loading is announced once here, not per
 * bone.
 */
export function QuizSkeleton() {
    return (
        <Card role="status" className="px-[22px] py-[18px]">
            <VisuallyHidden>{strings.loading}</VisuallyHidden>
            <Skeleton className="mb-3 h-[9px] w-[100px]" />
            <Skeleton className="mb-3.5 h-[15px] w-[70%]" />
            <div className="grid gap-2">
                <Skeleton className="h-11 rounded" />
                <Skeleton className="h-11 rounded" />
                <Skeleton className="h-11 rounded" />
            </div>
        </Card>
    );
}
