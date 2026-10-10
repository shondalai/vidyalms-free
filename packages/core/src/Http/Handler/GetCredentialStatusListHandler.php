<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialAssembly;
use Shondalai\VidyaLMS\Core\Application\Credentials\StatusListDocument;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusList;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * GET /credentials/status/{listId}
 *
 * The status list as a cacheable static document.
 *
 * The free build allocates indexes and does not publish, so this answers 404
 * until a build that publishes fills the bitstring in. That is the honest
 * answer and it is deliberately not an empty list: an all-zero bitstring
 * tells every verifier that every credential on it is valid, which would be a
 * lie about revocations the site has actually recorded.
 *
 * **A stale list is refused for the same reason.** Every publisher is a paid
 * provider; the lifecycle writes that dirty a list are free. So a site that
 * publishes once and then removes the package keeps recording revocations
 * while this route keeps serving the frozen bitstring as authoritative, and
 * every wallet following the entry reads a withdrawn credential as valid
 * indefinitely. Past a bounded staleness window this answers 404, which a
 * verifier reads as "cannot check" rather than as "valid".
 *
 * The route exists in the free build anyway, because a credential issued
 * today carries this URL inside it and the URL has to keep meaning the same
 * thing after the site buys the package that publishes.
 */
final class GetCredentialStatusListHandler implements HandlerInterface
{
    /**
     * How long a list may go on being served after a lifecycle write dirtied
     * it. It is generous against the coalesce window a publisher debounces
     * with and short against the life of a revocation.
     */
    public const MAX_STALENESS_SECONDS = 86400;

    public function __construct(
        private readonly CredentialStatusRepository $statusLists,
        private readonly ClockPort $clock,
        private readonly string $statusListBaseUrl = '',
        private readonly ?CredentialAssembly $assembly = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $list = $this->statusLists->findListByUuid($request->pathParam('listId'));

        if ($list === null || !$list->isPublished() || $this->isStale($list)) {
            throw ApiProblem::notFound();
        }

        return new ApiResponse(
            200,
            StatusListDocument::build($list, $this->statusListBaseUrl, $this->issuerUrl()),
            [
                'Content-Type' => 'application/ld+json',
                'Cache-Control' => 'public, max-age=' . (int) ($list->ttlMs / 1000),
                'X-Robots-Tag' => 'noindex',
            ],
        );
    }

    private function isStale(CredentialStatusList $list): bool
    {
        if ($list->dirtySince === null) {
            return false;
        }

        return $this->clock->now()->getTimestamp() - $list->dirtySince->getTimestamp() > self::MAX_STALENESS_SECONDS;
    }

    private function issuerUrl(): string
    {
        return $this->assembly?->issuerSetting()['url'] ?? '';
    }
}
