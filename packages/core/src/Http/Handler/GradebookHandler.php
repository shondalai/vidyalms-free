<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Grades\ReadGradebook;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * The three gradebook reads.
 *
 * GET /manage/courses/{id}/gradebook/items
 * GET /manage/courses/{id}/gradebook/grades
 * GET /manage/courses/{id}/gradebook/changes
 *
 * One handler with a mode rather than three, because the three differ only in
 * which method of the read model they call and in which query parameters they
 * pass through: three classes would be three copies of the same six lines.
 */
final class GradebookHandler implements HandlerInterface
{
    public const MODE_ITEMS = 'items';
    public const MODE_CELLS = 'cells';
    public const MODE_CHANGES = 'changes';

    public function __construct(
        private readonly ReadGradebook $gradebook,
        private readonly string $mode = self::MODE_ITEMS,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');

        return ApiResponse::ok(match ($this->mode) {
            self::MODE_CELLS => $this->gradebook->cells(
                $request->principal,
                $courseId,
                $this->optionalInt($request, 'user_id'),
                $this->optionalInt($request, 'grade_item_id'),
                $this->cursor($request),
                $request->queryInt('limit', ReadGradebook::DEFAULT_LIMIT),
            ),
            self::MODE_CHANGES => $this->gradebook->changes(
                $request->principal,
                $courseId,
                $this->optionalInt($request, 'user_id'),
                $this->optionalInt($request, 'grade_item_id'),
                $this->optionalInt($request, 'actor_id'),
                $this->optionalString($request, 'from'),
                $this->optionalString($request, 'until'),
                $this->cursor($request),
                $request->queryInt('limit', ReadGradebook::DEFAULT_LIMIT),
            ),
            default => $this->gradebook->items($request->principal, $courseId),
        })->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    private function optionalInt(ApiRequest $request, string $name): ?int
    {
        $value = $request->queryInt($name, 0);

        return $value > 0 ? $value : null;
    }

    private function optionalString(ApiRequest $request, string $name): ?string
    {
        $value = $request->queryString($name);

        return $value === '' ? null : $value;
    }

    private function cursor(ApiRequest $request): ?string
    {
        return $this->optionalString($request, 'cursor');
    }
}
