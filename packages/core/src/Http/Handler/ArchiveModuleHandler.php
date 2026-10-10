<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveModule;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * DELETE /manage/modules/{id}
 *
 * A reversible archive rather than a delete, so it answers the module it
 * archived, how many lessons went with it, and what that cost.
 */
final class ArchiveModuleHandler implements HandlerInterface
{
    private const ROUTE = 'manage.modules.archive';

    public function __construct(
        private readonly ArchiveModule $archiveModule,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $moduleId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $moduleId): ApiResponse {
            [$module, $outcome] = $this->archiveModule->execute($request->principal, $moduleId);

            return ApiResponse::ok([
                'module' => $this->presenters->curriculum->moduleRecord($module),
                'outcome' => $this->presenters->curriculum->archiveOutcome($outcome),
            ]);
        });
    }
}
