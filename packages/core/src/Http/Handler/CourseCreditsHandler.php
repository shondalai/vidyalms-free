<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCreditRepository;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Credit\ComputesFromParams;

/**
 * GET /manage/courses/{id}/credits
 * PUT /manage/courses/{id}/credits
 *
 * The authored source of every credit row an issuance will write. Without it
 * the free contact hours scheme, the free credit statement element and the
 * free credit tokens all read an empty table.
 *
 * A save runs the scheme's own validation, because the amounts on these rows
 * are reported to a regulator and a scheme that could not refuse a payload it
 * cannot report would be a seam that validates nothing.
 */
final class CourseCreditsHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.credits';

    public function __construct(
        private readonly CourseCreditRepository $credits,
        private readonly CreditSchemeRegistry $schemes,
        private readonly AccessGuard $guard,
        private readonly IdempotentExecution $idempotent,
        private readonly bool $write = false,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');

        if (!$this->write) {
            $this->guard->require($request->principal, Capability::MANAGE);

            return ApiResponse::ok(['items' => $this->present($courseId)]);
        }

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId): ApiResponse {
            $this->guard->require($request->principal, Capability::MANAGE);

            $items = $request->body['items'] ?? null;

            if (!\is_array($items)) {
                throw ApiProblem::badRequest('A credits save takes an "items" array.');
            }

            $errors = [];
            $saved = [];

            foreach ($items as $index => $item) {
                if (!\is_array($item)) {
                    $errors['items.' . $index] = 'Each credit is an object.';
                    continue;
                }

                $scheme = $item['scheme'] ?? null;

                if (!\is_string($scheme) || $scheme === '') {
                    $errors['items.' . $index . '.scheme'] = 'Name the credit scheme.';
                    continue;
                }

                $params = $item['params'] ?? [];
                $params = \is_array($params) ? $params : [];
                /** @var array<string, mixed> $params */

                if ($this->schemes->has($scheme)) {
                    $provider = $this->schemes->get($scheme);

                    foreach ($provider->validate($params) as $finding) {
                        if ($finding->blocks()) {
                            $errors['items.' . $index . '.params'] = $finding->messageKey;
                        }
                    }

                    $version = $provider->currentShape();
                } else {
                    // An unregistered scheme is stored rather than refused,
                    // which is the published miss direction: a compliance
                    // figure that silently disappears is worse than one that
                    // is visibly unvalidated.
                    $version = 1;
                }

                $saved[] = new CourseCredit(
                    $courseId,
                    $scheme,
                    $params,
                    $version,
                    $request->principal->isGuest() ? null : $request->principal->userId,
                );
            }

            if ($errors !== []) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ApiProblem::validation($errors);
            }

            $keep = [];

            foreach ($saved as $credit) {
                $this->credits->save($credit);
                $keep[] = $credit->scheme;
            }

            foreach ($this->credits->forCourse($courseId) as $existing) {
                if (!\in_array($existing->scheme, $keep, true)) {
                    $this->credits->delete($courseId, $existing->scheme);
                }
            }

            return ApiResponse::ok(['items' => $this->present($courseId)]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function present(int $courseId): array
    {
        $items = [];

        foreach ($this->credits->forCourse($courseId) as $credit) {
            $registered = $this->schemes->has($credit->scheme);
            $provider = $registered ? $this->schemes->get($credit->scheme) : null;

            $items[] = [
                'scheme' => $credit->scheme,
                'scheme_label' => $provider?->label() ?? $credit->scheme,
                'available' => $registered,
                'params' => $credit->params,
                'params_version' => $credit->paramsVersion,
                'derived' => $provider === null ? null : [
                    'unit' => $provider->unit(),
                    'contact_minutes' => $credit->contactMinutes(),
                    'field_of_study' => $credit->fieldOfStudy(),
                    'awarding_body_id' => $credit->awardingBodyId(),
                    'amount' => $credit->statedAmount() ?? ($provider instanceof ComputesFromParams
                        ? $provider->amountFromParams($credit->params)
                        : $provider->amountFor($credit->contactMinutes() ?? 0)),
                ],
            ];
        }

        return $items;
    }
}
