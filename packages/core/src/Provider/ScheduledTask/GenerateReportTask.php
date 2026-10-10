<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Reporting\ReportExportPipeline;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Generates one report as a background job, and records what it produced.
 *
 * **It authorises before it calls a provider.** It did not, and the provider
 * did it instead, which is fine with one report and one capability and wrong
 * the moment a group leader exists: every provider then repeats both the
 * capability check and the scope filter, and the eleventh one forgets the
 * second. The job carries the principal it runs as, so the check has an actor;
 * a job with no actor is refused rather than run as somebody.
 *
 * **It writes a run row and returns its id.** It used to JSON-encode the result
 * and put it at `reports/{key}/{jobId}.json` with no run record, no format, no
 * row count, no truncation flag, no expiry and nothing that handed anybody a
 * URL. A tabular report now goes through {@see ReportExportPipeline}, which
 * writes the manifest, applies the suppression policy, writes through the
 * chosen export format and hands the run to the chosen delivery provider.
 *
 * A scalar report has no rows to write and no format to write them in, so it
 * keeps the shipped behaviour: the result is stored as JSON at the same path it
 * always used. That is not a leftover. `course-overview` returns six numbers
 * and a CSV of six numbers is not a thing anybody asked for, and changing where
 * it lands would orphan every artefact a site already has.
 */
final class GenerateReportTask implements ScheduledTaskHandlerInterface
{
    public const KEY = 'vidyalms.report.generate';

    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly StoragePort $storage,
        private readonly ReportExportPipeline $pipeline,
        private readonly AccessGuard $guard,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $key = $run->string('report');
        $report = $this->reports->get($key);
        $this->guard->requireAuthenticated($run->principal);
        $this->guard->require($run->principal, $report->requiredCapability());

        $parameters = $run->arrayValue('parameters');
        $scope = self::scopeFrom($parameters, $run);
        $context = ReportContext::create(
            $parameters,
            $run->principal,
            $scope,
            null,
            null,
            null,
            null,
            'en-GB',
            null,
            $run->string('timezone', 'UTC'),
        );

        if ($report instanceof TabularReportInterface) {
            $completed = $this->pipeline->execute(
                $report,
                $context,
                $run->string('format', 'csv'),
                $run->string('delivery') === '' ? null : $run->string('delivery'),
                ReportScopeDigest::of($scope),
            );

            return sprintf(
                'report=%s run=%d format=%s rows=%d state=%s',
                $key,
                $completed->id ?? 0,
                $completed->formatKey,
                $completed->rowCount,
                $completed->state,
            );
        }

        $path = 'reports/' . self::pathSegment($key) . '/' . $run->jobId . '.json';
        $this->storage->putPrivate(
            $path,
            (string) json_encode($report->generate($context), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );

        return 'report=' . $key . ' path=' . $path;
    }

    /**
     * The scope this job runs under, taken from what was stored when it was
     * queued.
     *
     * A job cannot ask the guard what its actor may see the way a request can,
     * because the answer may have changed since it was queued and a report is
     * meant to reproduce the run that was scheduled. So the scope is data: a
     * course id narrows to that course, and a job that names none is a
     * site-wide run, which is what a manager schedules.
     *
     * @param array<string, mixed> $parameters
     */
    private static function scopeFrom(array $parameters, ScheduledTaskRun $run): VisibilityScope
    {
        $courseId = $run->int('course_id', 0);

        if ($courseId < 1) {
            $raw = $parameters['course_id'] ?? null;
            $courseId = \is_numeric($raw) ? (int) $raw : 0;
        }

        return $courseId > 0 ? VisibilityScope::forCourse($courseId) : VisibilityScope::site();
    }

    /**
     * A provider key reaches a storage path, so it is reduced to one safe
     * segment before it gets there.
     */
    private static function pathSegment(string $value): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_.-]/', '-', $value) ?? '';
        $segment = trim(str_replace('..', '-', $segment), '.-');

        return $segment !== '' ? $segment : 'unknown';
    }
}
