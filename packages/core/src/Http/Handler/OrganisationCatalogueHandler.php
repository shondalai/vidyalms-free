<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCatalogue;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * The six organisation catalogue routes, one handler.
 *
 * Transport only, and deliberately dull: the use case owns the tenancy check,
 * the release rule and the registry miss. Six files would be six places to get
 * the organisation id subtly different.
 */
final class OrganisationCatalogueHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $operation,
        private readonly ManageOrganisationCatalogue $catalogue,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $orgId = $request->intPathParam('id');
        $actor = $request->principal;

        return match ($this->operation) {
            'entries' => ApiResponse::ok(['items' => $this->catalogue->entries($actor, $orgId)]),
            'sources' => ApiResponse::ok(['items' => $this->catalogue->sources($actor, $orgId)]),
            'courses' => ApiResponse::ok($this->catalogue->courses($actor, $orgId)),
            'add' => ApiResponse::created($this->catalogue->add($actor, $orgId, $request->body)),
            'update' => ApiResponse::ok($this->catalogue->update(
                $actor,
                $orgId,
                $request->intPathParam('entry_id'),
                $request->body,
            )),
            'retire' => ApiResponse::ok($this->catalogue->retire(
                $actor,
                $orgId,
                $request->intPathParam('entry_id'),
            )),
            default => throw new \LogicException('Unknown organisation catalogue operation.'),
        };
    }
}
