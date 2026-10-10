<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Everything in one course waiting for a human, or already graded by one.
 *
 * The queue is assembled from both sources, then sorted, then paged, in that
 * order: a page cut before the merge would split an item across pages and
 * report a total that is only true of one source. Both totals travel on every
 * page, unaffected by the filters, so a header can say "8 pending, 41 graded"
 * without a second request.
 *
 * This is the one list on the surface that does not sort and page at the
 * database, and the reason is a rule rather than an oversight. Whether a
 * stored answer needs a human is decided by the question type provider over
 * the definition and the answer (short_answer with no accepted answers does,
 * the same type with them does not), and neither is a column the core may
 * interpret. A WHERE clause here would be the core second-guessing a
 * provider, which is exactly what the registry exists to prevent, and it
 * would be wrong for the first third-party type that decides differently.
 *
 * Bounding it needs the decision materialised at write time, a column on
 * attempt_answers written by SubmitAttempt, which is a schema change with a
 * migration and a backfill on both hosts. Until that lands the queue is
 * per course, which bounds it in practice, and the names it shows are one
 * users call for the whole queue rather than one per learner.
 */
final class GetGradebook
{
    public const SORTS = ['waiting_since', 'graded_at', 'learner'];

    public function __construct(
        private readonly GradebookQueue $queue,
        private readonly CourseAccess $access,
        private readonly ListingPolicy $policy,
    ) {
    }

    public function execute(
        Principal $principal,
        int $courseId,
        string $status = GradebookItem::STATUS_PENDING,
        ?string $type = null,
        ?string $sort = null,
        ?string $order = null,
        int $page = 1,
        int $perPage = 20,
    ): GradebookPage {
        $this->access->require($principal, $courseId, Capability::GRADE);

        if (!\in_array($status, [GradebookItem::STATUS_PENDING, GradebookItem::STATUS_GRADED], true)) {
            throw ValidationException::forField('status', 'The status must be pending or graded.');
        }

        if ($type !== null && !\in_array($type, [GradebookItem::TYPE_SUBMISSION, GradebookItem::TYPE_QUIZ_ANSWER], true)) {
            throw ValidationException::forField('type', 'The type must be submission or quiz_answer.');
        }

        if ($sort !== null && !\in_array($sort, self::SORTS, true)) {
            throw ValidationException::forField('sort', 'Unknown sort.');
        }

        if ($order !== null && !\in_array(strtolower($order), ['asc', 'desc'], true)) {
            throw ValidationException::forField('order', 'The order must be asc or desc.');
        }

        if ($page < 1) {
            throw ValidationException::forField('page', 'Must be 1 or more.');
        }

        if ($perPage < 1 || $perPage > $this->policy->maxPerPage) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('per_page', sprintf('Must be between 1 and %d.', $this->policy->maxPerPage));
        }

        $graded = $status === GradebookItem::STATUS_GRADED;
        $sort ??= $graded ? 'graded_at' : 'waiting_since';
        $descending = $order === null
            ? $graded && $sort === 'graded_at'
            : strtolower($order) === 'desc';

        $all = $this->queue->forCourse($courseId);
        $pendingTotal = 0;
        $gradedTotal = 0;
        $matching = [];

        foreach ($all as $item) {
            if ($item->isPending()) {
                ++$pendingTotal;
            } else {
                ++$gradedTotal;
            }

            if ($item->status !== $status) {
                continue;
            }

            if ($type !== null && $item->type !== $type) {
                continue;
            }

            $matching[] = $item;
        }

        $this->sort($matching, $sort, $descending);

        return new GradebookPage(
            array_values(\array_slice($matching, ($page - 1) * $perPage, $perPage)),
            \count($matching),
            $page,
            $perPage,
            $pendingTotal,
            $gradedTotal,
        );
    }

    /**
     * @param list<GradebookItem> $items
     */
    private function sort(array &$items, string $sort, bool $descending): void
    {
        $factor = $descending ? -1 : 1;

        usort($items, static function (GradebookItem $left, GradebookItem $right) use ($sort, $factor): int {
            $compared = match ($sort) {
                'learner' => strcasecmp($left->learnerName ?? '', $right->learnerName ?? ''),
                // An ungraded item has no graded_at; it sorts below every
                // graded one rather than pretending to a timestamp.
                'graded_at' => ($left->grade?->gradedAt->getTimestamp() ?? -1)
                    <=> ($right->grade?->gradedAt->getTimestamp() ?? -1),
                default => $left->waitingSince->getTimestamp() <=> $right->waitingSince->getTimestamp(),
            };

            return $compared !== 0 ? $compared * $factor : strcmp($left->key, $right->key);
        });
    }
}
