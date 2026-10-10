<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/courses/{id}/modules
 */
final class AddModuleHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.modules.add';

    public function __construct(
        private readonly AddModule $addModule,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $body = $request->body;
        $title = Body::requiredString($body, 'title');
        $summary = Body::nullableString($body, 'summary');
        $state = Body::nullableString($body, 'state', Module::STATE_PUBLISHED) ?? Module::STATE_PUBLISHED;

        return $this->idempotent->run(
            $request,
            self::ROUTE,
            function () use ($request, $courseId, $title, $summary, $state): ApiResponse {
                return ApiResponse::created($this->presenters->curriculum->moduleRecord(
                    $this->addModule->execute($request->principal, $courseId, $title, $summary, $state),
                ));
            },
        );
    }
}
