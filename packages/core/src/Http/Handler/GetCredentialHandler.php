<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\GetCredential;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialUrlUnavailable;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /credentials/{uuid}
 *
 * The machine-readable credential under content negotiation. The route exists
 * now, while the free build serves only unsigned formats, so the URL is
 * stable before anything embeds one in a QR code printed on paper.
 *
 * A format this build cannot serve is a 406 that names what it can serve,
 * never a 500 and never a silent substitution: a wallet asking a free site
 * for a signed credential should be told the site cannot sign, rather than
 * handed an unsigned document that fails its verification.
 *
 * An unknown uuid is a 404 rather than the calm empty answer the verification
 * route gives, because a uuid is not a short code somebody retypes: nothing
 * is probing this key space by hand, and a wallet dereferencing a stale id
 * needs to know it is stale.
 */
final class GetCredentialHandler implements HandlerInterface
{
    public function __construct(private readonly GetCredential $credentials)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $uuid = $request->pathParam('uuid');
        $accepted = $this->acceptedTypes($request->header('accept'));
        // Baked badges pin their hosted representation, even when a verifier
        // prefers JSON-LD or a signed provider also serves application/json.
        $format = $request->queryString('format') === 'openbadges2'
            ? \Shondalai\VidyaLMS\Core\Provider\Credential\OpenBadges2AssertionFormat::KEY : null;

        if ($this->credentials->findByUuid($uuid) === null) {
            throw ApiProblem::notFound();
        }

        try {
            $artefact = $this->credentials->execute($uuid, $accepted, $format);
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException $refusal) {
            return ApiProblem::conflict($refusal->getMessage(), $refusal->extensions)->toResponse()
                ->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex']);
        } catch (CredentialUrlUnavailable $refusal) {
            // A named reason rather than a 500 or, worse, a document with a
            // blank id. The host supplies the address; the core will not
            // invent one from the request, because issuance runs from a
            // scheduled task that has none.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::conflict($refusal->getMessage(), ['option' => $refusal->option]);
        }

        if ($artefact === null) {
            return new ApiResponse(
                406,
                [
                    'type' => 'about:blank',
                    'title' => 'Not Acceptable',
                    'status' => 406,
                    'detail' => 'This build serves no credential format matching the Accept header.',
                    'servable' => $this->credentials->servableMediaTypes(),
                ],
                ['Content-Type' => 'application/problem+json'],
            );
        }

        // The body is bytes rather than a decoded structure, because one of
        // the free formats is a PNG. It goes through the core's own binary
        // factory rather than an inline literal, so an adapter asks the
        // response whether it is binary instead of sniffing its shape.
        return ApiResponse::binary(
            $artefact->bytes(),
            $artefact->mediaType(),
            $artefact->artefact->suggestedFilename,
            ['X-Robots-Tag' => 'noindex'],
        );
    }

    /**
     * The Accept header, split and ordered by its own q values, with the
     * parameters dropped. A missing header is not a refusal: it means the
     * caller has no preference, and the first registered format answers.
     *
     * @return list<string>
     */
    private function acceptedTypes(?string $accept): array
    {
        if ($accept === null || trim($accept) === '') {
            return [];
        }

        $weighted = [];

        foreach (explode(',', $accept) as $index => $part) {
            $segments = explode(';', trim($part));
            $type = trim($segments[0]);

            if ($type === '') {
                continue;
            }

            $quality = 1.0;

            foreach (\array_slice($segments, 1) as $parameter) {
                $parameter = trim($parameter);

                if (str_starts_with($parameter, 'q=') && is_numeric(substr($parameter, 2))) {
                    $quality = (float) substr($parameter, 2);
                }
            }

            $weighted[] = ['type' => $type, 'q' => $quality, 'order' => $index];
        }

        usort(
            $weighted,
            static fn (array $a, array $b): int => [$b['q'], $a['order']] <=> [$a['q'], $b['order']],
        );

        return array_map(static fn (array $entry): string => (string) $entry['type'], $weighted);
    }
}
