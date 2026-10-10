<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Reporting\ManageReportSchedules;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * The saved report schedules.
 *
 * GET    /manage/report-schedules
 * POST   /manage/report-schedules
 * PATCH  /manage/report-schedules/{id}
 * DELETE /manage/report-schedules/{id}
 * POST   /manage/report-schedules/{id}/run
 *
 * The three writes require the idempotency key rather than accepting it: a
 * replayed create is a second schedule mailing the same people twice a day, and
 * a replayed run is a second artefact of personal data with its own retention
 * date.
 */
final class ReportScheduleHandler implements HandlerInterface
{
    public const MODE_LIST = 'list';
    public const MODE_CREATE = 'create';
    public const MODE_UPDATE = 'update';
    public const MODE_DELETE = 'delete';
    public const MODE_RUN = 'run';

    private const ROUTE = 'reports.schedules';

    /** What a 202 tells the caller to wait before polling a run. */
    private const RETRY_AFTER = 5;

    /** @param \Closure(ReportSchedule): list<array<string, mixed>> $runner */
    public function __construct(
        private readonly ManageReportSchedules $schedules,
        private readonly IdempotentExecution $idempotent,
        private readonly \Closure $runner,
        /**
         * The connection the idempotency claim commits on.
         *
         * It is required rather than defaulted because the required-key path
         * cannot open the transaction the claim belongs in without one, and a
         * handler that silently fell back to the optional path would answer a
         * missing key by doing the work.
         */
        private readonly DatabasePort $database,
        private readonly string $mode = self::MODE_LIST,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        if ($this->mode === self::MODE_LIST) {
            return ApiResponse::ok($this->schedules->list(
                $request->principal,
                $request->queryString('report_key') === '' ? null : $request->queryString('report_key'),
                $request->queryString('state') === '' ? null : $request->queryString('state'),
                $request->queryInt('page', 1),
                $request->queryInt('per_page', 25),
            ))->withHeaders(['Cache-Control' => 'private, no-store']);
        }

        // runAtomic and not run, because the contract declares the key
        // REQUIRED on all four writes and the optional path answers a missing
        // one by simply doing the work. It also commits the claim inside the
        // mutation's own transaction, which is what makes a replay return the
        // first answer rather than race it.
        return $this->idempotent->runAtomic(
            $request,
            self::ROUTE . '.' . $this->mode,
            $this->database,
            function () use ($request): ApiResponse {
                return match ($this->mode) {
                    self::MODE_CREATE => ApiResponse::created(
                        $this->schedules->create($request->principal, $request->body),
                    ),
                    self::MODE_UPDATE => ApiResponse::ok(
                        $this->schedules->update($request->principal, $request->intPathParam('id'), $request->body),
                    ),
                    self::MODE_DELETE => $this->deleted($request),
                    default => new ApiResponse(202, $this->schedules->runNow(
                        $request->principal,
                        $request->intPathParam('id'),
                        $this->runner,
                    ), [
                        // A caller that polls immediately learns nothing and
                        // costs a round trip, so the hint is on the response
                        // rather than in prose.
                        'Retry-After' => (string) self::RETRY_AFTER,
                        'Cache-Control' => 'private, no-store',
                    ]),
                };
            },
            ['body' => $request->body],
        );
    }

    private function deleted(ApiRequest $request): ApiResponse
    {
        $this->schedules->delete($request->principal, $request->intPathParam('id'));

        return ApiResponse::noContent();
    }
}
