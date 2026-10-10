<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\RecordIntegrityEvent;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /attempts/{id}/integrity-events
 *
 * The answer says whether a row was written, because recording is suppressed
 * entirely for a learner whose accessibility profile declares a need whose
 * tooling blurs the browser. Saying so is what lets the client stop
 * reporting rather than retrying every blur for the rest of the attempt.
 */
final class RecordIntegrityEventHandler implements HandlerInterface
{
    private const ROUTE = 'attempts.integrity_events.record';

    public function __construct(
        private readonly RecordIntegrityEvent $recordIntegrityEvent,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $attemptId = $request->intPathParam('id');
        $kind = Body::requiredString($request->body, 'kind');
        $occurredAt = $this->occurredAt($request);
        $context = \array_key_exists('context', $request->body) && \is_array($request->body['context'])
            ? Body::object($request->body, 'context')
            : null;

        return $this->idempotent->run(
            $request,
            self::ROUTE,
            function () use ($request, $attemptId, $kind, $occurredAt, $context): ApiResponse {
                $outcome = $this->recordIntegrityEvent->execute(
                    $request->principal,
                    $attemptId,
                    $kind,
                    $occurredAt,
                    $context,
                );

                return new ApiResponse(201, [
                    'recorded' => $outcome->recorded,
                    'event' => $outcome->event === null
                        ? null
                        : $this->presenters->assessment->integrityEvent($outcome->event),
                ]);
            },
        );
    }

    private function occurredAt(ApiRequest $request): ?\DateTimeImmutable
    {
        $value = Body::nullableString($request->body, 'occurred_at');

        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw ApiProblem::validation(['occurred_at' => 'A UTC datetime is required.']);
        }
    }
}
