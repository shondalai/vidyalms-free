<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationImports;
use Shondalai\VidyaLMS\Core\Application\Platform\FailedRowsFile;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The six organisation import routes, one handler.
 *
 * One class rather than six for the reason OrganisationLearningHandler is one
 * class for twenty: the six share the organisation id, the paging and the
 * job id, and six files would be six places to get the tenancy check subtly
 * different. The use case does the check; this is transport.
 *
 * The start route carries an Idempotency-Key, and the reason is written in the
 * design as a sentence rather than a rule: a double-clicked Import button on a
 * forty thousand row roster is the most likely way an organisation
 * administrator creates duplicate work, and the API contract is the cheapest
 * place to stop it. The same key after completion returns the original job.
 */
final class OrganisationImportHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $operation,
        private readonly OrganisationImports $imports,
        private readonly IdempotentExecution $idempotent,
        private readonly ClockPort $clock,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $orgId = $request->intPathParam('id');
        $actor = $request->principal;

        if ($this->operation === 'start') {
            return $this->start($request, $orgId);
        }

        $jobId = isset($request->pathParams['job_id']) ? $request->intPathParam('job_id') : 0;
        $now = $this->clock->now();

        if ($this->operation === 'failed_rows') {
            $download = $this->imports->failedRows($actor, $orgId, $jobId);

            // The cap travels with the bytes. A client that never opens the
            // body still learns that rows were left behind, which is the
            // half a trailing marker row cannot cover.
            return ApiResponse::binary(
                $download->body,
                'text/csv; charset=UTF-8',
                FailedRowsFile::nameFor($jobId),
                $download->headers(),
                'attachment',
            );
        }

        return ApiResponse::ok(match ($this->operation) {
            'jobs' => $this->page($actor, $orgId, $request, $now),
            'job' => Presenter::importJob($this->imports->job($actor, $orgId, $jobId), $now),
            'log' => $this->log($actor, $orgId, $jobId),
            'problems' => $this->problems($actor, $orgId, $jobId, $request),
            default => throw new \LogicException('Unknown organisation import operation.'),
        });
    }

    private function start(ApiRequest $request, int $orgId): ApiResponse
    {
        $body = $request->body;
        $importer = $body['importer'] ?? null;

        if (!\is_string($importer) || trim($importer) === '') {
            throw ApiProblem::validation(['importer' => 'An importer key is required.']);
        }

        $source = $body['source'] ?? null;

        if (!\is_string($source) || trim($source) === '') {
            throw ApiProblem::validation(['source' => 'A source is required.']);
        }

        $dryRun = $body['dry_run'] ?? false;

        if (!\is_bool($dryRun)) {
            throw ApiProblem::validation(['dry_run' => 'Must be a boolean.']);
        }

        $options = $this->options($body['options'] ?? null);
        $options['dry_run'] = $dryRun;

        // The organisation is in the path, so hashing the path is what puts
        // the organisation in the claim. A flat constant scopes the key on
        // route name plus principal id and nothing else, so one person
        // administering two districts who reuses a key across
        // /organisations/1/imports and /organisations/2/imports is handed the
        // first district's job back, told 202, and the second district's
        // roster never loads. The three sibling organisation handlers already
        // scope on hash(method:path); this is the fourth.
        return $this->idempotent->run(
            $request,
            'org.import.' . hash('sha256', $request->method . ':' . $request->path),
            function () use ($request, $orgId, $importer, $source, $options, $dryRun): ApiResponse {
                $job = $this->imports->start($request->principal, $orgId, $importer, $source, $options);
                $shape = Presenter::importJob($job, $this->clock->now());

                // A preview answers 200 because it has already happened; a
                // real run answers 202 because it has not.
                return $dryRun ? ApiResponse::ok($shape) : new ApiResponse(202, $shape);
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function page(
        \Shondalai\VidyaLMS\Core\Application\Shared\Principal $actor,
        int $orgId,
        ApiRequest $request,
        \DateTimeImmutable $now,
    ): array {
        $page = $this->imports->jobs(
            $actor,
            $orgId,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );

        return [
            'items' => array_map(
                static fn (ImportJob $job): array => Presenter::importJob($job, $now),
                $page->items,
            ),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function log(
        \Shondalai\VidyaLMS\Core\Application\Shared\Principal $actor,
        int $orgId,
        int $jobId,
    ): array {
        $job = $this->imports->job($actor, $orgId, $jobId);

        return [
            'id' => $job->id,
            'status' => $job->status,
            'log' => $job->log,
            'notes' => array_map(
                static fn (ImportNote $note): array => $note->toArray(),
                ImportNote::parseLog($job->log),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function problems(
        \Shondalai\VidyaLMS\Core\Application\Shared\Principal $actor,
        int $orgId,
        int $jobId,
        ApiRequest $request,
    ): array {
        return Presenter::importRowProblems($this->imports->problems(
            $actor,
            $orgId,
            $jobId,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 25),
            $this->filter($request, 'code'),
            $this->filter($request, 'outcome'),
        ));
    }

    private function filter(ApiRequest $request, string $name): ?string
    {
        $value = trim($request->queryString($name));

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function options(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!\is_array($value)) {
            throw ApiProblem::validation(['options' => 'Must be an object of importer options.']);
        }

        $options = [];

        /** @var mixed $option */
        foreach ($value as $name => $option) {
            if (!\is_string($name)) {
                throw ApiProblem::validation(['options' => 'Option names must be strings.']);
            }

            $options[$name] = $option;
        }

        return $options;
    }
}
