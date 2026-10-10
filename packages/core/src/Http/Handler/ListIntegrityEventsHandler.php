<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\ListIntegrityEvents;
use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEvent;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/attempts/{id}/integrity-events
 */
final class ListIntegrityEventsHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListIntegrityEvents $listIntegrityEvents,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $page = $this->listIntegrityEvents->execute(
            $request->principal,
            $request->intPathParam('id'),
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 25),
        );

        return ApiResponse::ok([
            'items' => array_map(
                fn (IntegrityEvent $event): array => $this->presenters->assessment->integrityEvent($event),
                $page['items'],
            ),
            'total' => $page['total'],
            'page' => $page['page'],
            'per_page' => $page['per_page'],
        ]);
    }
}
