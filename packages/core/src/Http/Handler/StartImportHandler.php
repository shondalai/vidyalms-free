<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\QueueImportJob;
use Shondalai\VidyaLMS\Core\Application\Platform\RunImportJob;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /import
 *
 * Two behaviours, and the difference is deliberate:
 *
 * - A real import answers 202 with a pending job and runs later. Queuing is
 *   not an optimisation: a Moodle backup takes minutes, and the request that
 *   asked for it would time out long before the import finished.
 * - A dry run answers 200 with a finished, previewed job, because a preview
 *   exists to be read now. It writes nothing, so holding the request for it
 *   is safe.
 *
 * The uploaded file never travels through here. The adapter takes the
 * multipart upload, stores it through StoragePort, and passes the private
 * path as "source", the same shape assignment submissions use.
 */
final class StartImportHandler implements HandlerInterface
{
    private const ROUTE = 'import.start';

    public function __construct(
        private readonly QueueImportJob $queueImportJob,
        private readonly RunImportJob $runImportJob,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
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

        return $this->idempotent->run(
            $request,
            self::ROUTE,
            function () use ($request, $importer, $source, $options, $dryRun): ApiResponse {
                if ($dryRun) {
                    return ApiResponse::ok(Presenter::importJob(
                        $this->runImportJob->execute($request->principal, $importer, $source, $options),
                    ));
                }

                return new ApiResponse(202, Presenter::importJob(
                    $this->queueImportJob->execute($request->principal, $importer, $source, $options),
                ));
            },
        );
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
