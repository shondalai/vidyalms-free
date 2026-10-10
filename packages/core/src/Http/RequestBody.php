<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

/**
 * The one rule both adapters apply to a request body.
 *
 * It lives in the core rather than in each adapter because "anything that
 * passes on WordPress and fails on Joomla is an adapter bug by definition",
 * and two independently written body decoders are the easiest way to earn one.
 * Neither adapter may hold a second copy of this decision.
 *
 * The rule carries no route knowledge, which is what keeps it in the transport
 * layer where it belongs:
 *
 *   - an empty body decodes to an empty array;
 *   - a JSON content type, or no content type at all, is decoded as a JSON
 *     object, and a malformed one is a 400 exactly as it always was;
 *   - any other content type is carried through untouched, with an empty
 *     decoded body, for the handler that asked for the bytes.
 *
 * The third case exists for two routes and no others. An RFC 5965 feedback
 * report arrives as message/feedback-report, a MIME document with no array
 * form, and an RFC 8058 one-click unsubscribe arrives as
 * application/x-www-form-urlencoded carrying exactly List-Unsubscribe=One-Click.
 * Both were answered 400 by the adapters before the core could see them.
 */
final class RequestBody
{
    /**
     * @param array<string, mixed> $decoded
     */
    private function __construct(
        public readonly array $decoded,
        public readonly ?string $raw,
        public readonly ?string $contentType,
    ) {
    }

    /**
     * @param ?string $raw            the request body as it arrived
     * @param ?string $contentTypeHeader the Content-Type header, parameters and all
     */
    public static function from(?string $raw, ?string $contentTypeHeader): self
    {
        $mediaType = self::mediaType($contentTypeHeader);

        if ($raw === null || trim($raw) === '') {
            return new self([], null, $mediaType);
        }

        if (!self::isJson($mediaType)) {
            return new self([], $raw, $mediaType);
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiProblem::badRequest('The request body is not valid JSON.');
        }

        if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw ApiProblem::badRequest('The request body must be a JSON object.');
        }

        return new self($decoded, $raw, $mediaType);
    }

    /**
     * A body with no content type is decoded as JSON, because that is what
     * every client of this contract has always sent and a stricter rule would
     * break them for no gain.
     */
    private static function isJson(?string $mediaType): bool
    {
        if ($mediaType === null || $mediaType === '') {
            return true;
        }

        return $mediaType === 'application/json'
            || $mediaType === 'text/json'
            || str_ends_with($mediaType, '+json');
    }

    /**
     * The media type alone: "message/feedback-report; report-type=..." is
     * "message/feedback-report", lowercased, so a comparison never depends on
     * the charset parameter a client happened to append.
     */
    private static function mediaType(?string $header): ?string
    {
        if ($header === null) {
            return null;
        }

        $type = trim(strtolower(explode(';', $header, 2)[0]));

        return $type === '' ? null : $type;
    }
}
