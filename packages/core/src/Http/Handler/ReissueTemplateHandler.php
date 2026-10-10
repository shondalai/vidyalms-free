<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificatesForTemplate;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * POST /manage/certificate-templates/{id}/reissue
 * DELETE /manage/certificate-templates/{id}/reissue
 *
 * Bulk reissue after a template correction, and its cancel.
 *
 * The response carries both dedupe keys and the selection predicate, because
 * the two keys answer different questions and an operator watching a run of
 * five hundred needs to be able to name what is running: the fan-out key is
 * what a cancel takes, and the per-certificate key is what stops one row
 * being reissued twice by two overlapping runs.
 */
final class ReissueTemplateHandler implements HandlerInterface
{
    private const ROUTE = 'manage.certificate_templates.reissue';

    public function __construct(
        private readonly ReissueCertificatesForTemplate $reissue,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
        private readonly AccessGuard $guard,
        private readonly bool $cancel = false,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $templateId = $request->intPathParam('id');
        $this->guard->require($request->principal, Capability::MANAGE);
        $revision = $this->cancel ? $request->queryInt('revision', 0) : ($request->body['revision'] ?? null);
        if (!is_int($revision) || $revision < 1 || $revision > 2147483646) {
            throw ValidationException::forField('revision', 'Provide the observed published revision.');
        }
        if (!$this->cancel && array_diff(array_keys($request->body), ['revision']) !== []) {
            throw ValidationException::forField('revision', 'Bulk reissue accepts only the published revision.');
        }

        if ($this->cancel) {
            return $this->idempotent->runAtomic(
                $request,
                self::ROUTE . '.cancel:' . $templateId,
                $this->database,
                function () use ($request, $templateId, $revision): ApiResponse {
                    $this->reissue->cancel($request->principal, $templateId, $revision);
                    return ApiResponse::noContent();
                },
                ['revision' => $revision],
            );
        }

        return $this->idempotent->runAtomic(
            $request,
            self::ROUTE . ':' . $templateId,
            $this->database,
            function () use ($request, $templateId, $revision): ApiResponse {
                $queued = $this->reissue->queue($request->principal, $templateId, $revision);

                return new ApiResponse(202, [
                'template_id' => $templateId,
                'revision' => $queued['revision'],
                'dedupe_key' => $queued['dedupe_key'],
                'status_url' => '/manage/certificate-templates/' . $templateId . '/reissue?revision=' . $queued['revision'],
                'certificate_dedupe_key_pattern' => 'reissue:certificate:{certificateId}:' . $queued['revision'],
                // The predicate is part of the contract rather than an
                // implementation detail: it is what makes a restart
                // idempotent, because a reissued row already carries the new
                // revision and is skipped.
                'selection' => 'template_id = ' . $templateId
                    . " AND status = 'active'"
                    . ' AND (template_revision IS NULL OR template_revision < ' . $queued['revision'] . ')',
                ]);
            },
            ['revision' => $revision],
        );
    }
}
