<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Availability;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\ManageAvailabilityRule;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\Body;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET, PUT and DELETE /manage/{subject_type}/{subject_id}/availability
 *
 * One templated path rather than five concrete ones, which follows the
 * precedent the report route already sets and makes a sixth gated subject
 * type a handler change rather than a contract change. A subject type outside
 * the declared set is a not-found here, so the route never becomes a way to
 * probe what other tables exist.
 */
final class AvailabilityRuleHandler implements HandlerInterface
{
    public function __construct(
        private readonly ManageAvailabilityRule $rules,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $subject = $this->subjectOf($request);
        $surface = $this->surfaceOf($request);

        if ($request->method === 'GET') {
            return ApiResponse::ok($this->rules->get($request->principal, $subject, $surface));
        }

        if ($request->method === 'DELETE') {
            return $this->idempotent->run(
                $request,
                'availability.rule.delete',
                function () use ($request, $subject, $surface): ApiResponse {
                    $this->rules->delete($request->principal, $subject, $surface);

                    return ApiResponse::noContent();
                },
            );
        }

        return $this->idempotent->run(
            $request,
            'availability.rule.put',
            function () use ($request, $subject, $surface): ApiResponse {
                $tree = $request->body['tree'] ?? null;

                return ApiResponse::ok($this->rules->put(
                    $request->principal,
                    $subject,
                    $surface,
                    \is_array($tree) ? $tree : null,
                    Body::nullableString($request->body, 'default_visibility'),
                ));
            },
        );
    }

    private function subjectOf(ApiRequest $request): GateSubject
    {
        $type = $request->pathParam('subject_type');

        // The five gated types are a closed set on this route. A path naming
        // anything else is not found rather than refused, because a refusal
        // would confirm the segment reached a handler.
        $singular = match ($type) {
            'courses' => GateSubject::TYPE_COURSE,
            'modules' => GateSubject::TYPE_MODULE,
            'lessons' => GateSubject::TYPE_LESSON,
            'quizzes' => GateSubject::TYPE_QUIZ,
            'assignments' => GateSubject::TYPE_ASSIGNMENT,
            default => null,
        };

        if ($singular === null) {
            throw ApiProblem::notFound();
        }

        return new GateSubject($singular, $request->intPathParam('subject_id'));
    }

    private function surfaceOf(ApiRequest $request): AvailabilitySurface
    {
        $surface = $request->queryString('surface', AvailabilitySurface::ContentGating->value);
        $resolved = AvailabilitySurface::tryFrom($surface);

        if ($resolved === null || !$resolved->storesInAvailabilityRules()) {
            throw ApiProblem::notFound();
        }

        return $resolved;
    }
}
