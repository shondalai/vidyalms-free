<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Notification;

use Shondalai\VidyaLMS\Core\Application\Notification\RecordDeliveryFeedback;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /notifications/feedback/{channelKey}
 *
 * The raw body and its content type go to the mapper registry, and whatever
 * neutral report comes back is written, which may suppress an address.
 *
 * The transport hands the raw body, its content type and the forwarded
 * headers on the request itself: `ApiRequest::$rawBody`, `$contentType` and
 * `$headers`, which both adapters populate from `Http\RequestBody` and
 * `ApiRequest::FORWARDED_HEADERS`. The reserved body keys below are read
 * second and exist only for a caller that has no typed members to fill, such
 * as an in-process test driving the handler directly. Reading a decoded map
 * for a reserved key is not the shape to build on: a JSON payload that
 * happens to carry `_raw` would be misread as a transport instruction.
 *
 * An adapter that cannot supply the raw text supplies the decoded JSON
 * instead, which an ESP mapper reads happily and the abuse-report mapper
 * declines to claim.
 *
 * It takes no idempotency key for the same reason the unsubscribe post does
 * not: a repeated report is folded by the suppression row's own uniqueness
 * rather than by a replay cache.
 */
final class DeliveryFeedbackHandler implements HandlerInterface
{
    /** Where an adapter puts the undecoded body and the request headers. */
    public const RAW_BODY = '_raw';
    public const CONTENT_TYPE = '_content_type';
    public const HEADERS = '_headers';

    /** The header the shared secret arrives in. */
    public const SECRET_HEADER = 'X-VidyaLMS-Feedback-Secret';

    public function __construct(private readonly RecordDeliveryFeedback $feedback)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $headers = $this->headers($request);
        $raw = $request->rawBody ?? ($request->body[self::RAW_BODY] ?? null);
        $contentType = $request->contentType
            ?? ($request->body[self::CONTENT_TYPE] ?? ($headers['content-type'] ?? 'application/json'));

        $body = \is_string($raw) ? $raw : json_encode($this->payload($request), \JSON_THROW_ON_ERROR);

        return new ApiResponse(202, $this->feedback->execute(
            $request->pathParam('channelKey'),
            $body,
            \is_string($contentType) ? $contentType : 'application/json',
            \Shondalai\VidyaLMS\Core\Http\FeedbackCredentials::secret(
                $request->headers[strtolower(self::SECRET_HEADER)] ?? '',
                $request->headers['authorization'] ?? '',
            ),
            $headers,
        ));
    }

    /**
     * The real transport headers win over the reserved body key.
     *
     * The reserved keys exist for an in-process caller with no typed members
     * to fill, so they are a fallback and not an override. Reading the body
     * last let a webhook whose own JSON legitimately carries a _headers
     * object replace the header set the mapper's context receives, which
     * makes the reserved key a supported way to spoof transport metadata.
     * This is the same precedence the raw body and the content type already
     * take in this class.
     *
     * @return array<string, string>
     */
    private function headers(ApiRequest $request): array
    {
        $headers = [];
        $raw = $request->body[self::HEADERS] ?? null;

        if (\is_array($raw)) {
            foreach ($raw as $name => $value) {
                if (\is_string($name) && \is_string($value)) {
                    $headers[strtolower($name)] = $value;
                }
            }
        }

        foreach ($request->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ApiRequest $request): array
    {
        $payload = $request->body;
        unset($payload[self::RAW_BODY], $payload[self::CONTENT_TYPE], $payload[self::HEADERS]);

        return $payload;
    }
}
