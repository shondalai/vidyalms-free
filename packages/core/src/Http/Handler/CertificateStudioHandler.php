<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateStudio;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

final class CertificateStudioHandler implements HandlerInterface
{
    public function __construct(
        private readonly CertificateStudio $studio,
        private readonly CertificateTemplateWrite $write,
        private readonly PresenterRegistry $presenters,
        private readonly string $operation,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        if ($this->operation === 'catalogue') {
            return ApiResponse::ok($this->studio->catalogue($request->principal));
        }
        $id = $this->operation === 'blueprint' ? 0 : $request->intPathParam('id');
        $key = (string) ($request->pathParams['key'] ?? '');

        $resource = 'studio.' . $this->operation . ':' . $id . ':' . $key;
        try {
            return $this->write->run($request, $resource, function () use ($request, $id, $key): ApiResponse {
                $view = match ($this->operation) {
                    'publish' => $this->studio->publish($request->principal, $id, $request->body),
                    'default' => $this->studio->selectDefault($request->principal, $id, $request->body),
                    'clone' => $this->studio->clonePublished($request->principal, $id, $request->body),
                    'blueprint' => $this->studio->cloneBlueprint($request->principal, $key, $request->body),
                    default => throw new \LogicException('Unknown studio operation.'),
                };
                $body = $this->presenters->certificateTemplates->templateDetail($view);
                return in_array($this->operation, ['clone', 'blueprint'], true) ? ApiResponse::created($body) : ApiResponse::ok($body);
            });
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\DomainException | \Shondalai\VidyaLMS\Core\Http\ApiProblem $failure) {
            // A problem the idempotency envelope raised already carries its
            // own status; the salvage branch below must not rewrite it into
            // a conflict.
            throw $failure;
        } catch (\Throwable $failure) {
            if ($this->operation === 'default' && $this->studio->defaultChanged($request->body)) {
                throw new \Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException(
                    'The site default changed during selection. Refresh before retrying.',
                );
            }
            throw $failure;
        }
    }
}
