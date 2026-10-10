<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCatalogue;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /courses
 */
final class GetCatalogueHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetCatalogue $getCatalogue,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $q = $request->queryString('q');
        $categoryId = $request->queryInt('category', 0);

        $page = $this->getCatalogue->execute(
            $request->principal,
            $q === '' ? null : $q,
            $categoryId > 0 ? $categoryId : null,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );

        return ApiResponse::ok([
            'items' => array_map(
                static fn (Course $course): array => Presenter::courseSummary($course),
                $page->items,
            ),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ]);
    }
}
