<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

/**
 * An RFC 9457 problem detail as an exception. Handlers and the kernel throw
 * these; adapters serialise toResponse() with the
 * application/problem+json media type.
 */
final class ApiProblem extends \RuntimeException
{
    /**
     * The members RFC 9457 defines itself. An extension may not take one of
     * these names, and one that does is dropped rather than allowed to
     * rewrite the problem it travels in.
     *
     * @var list<string>
     */
    public const RESERVED = ['type', 'title', 'status', 'detail'];

    /**
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        public readonly int $status,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    public static function badRequest(string $detail): self
    {
        return new self(400, 'Bad request', $detail);
    }

    public static function unauthorized(): self
    {
        return new self(401, 'Authentication required');
    }

    public static function forbidden(?string $detail = null): self
    {
        return new self(403, 'Forbidden', $detail);
    }

    public static function notFound(?string $detail = null): self
    {
        return new self(404, 'Not found', $detail);
    }

    /**
     * A refusal may carry the fact the client needs in order to say something
     * useful instead of "no", which is the precedent the domain's own
     * conflict already sets: the successor of a replaced credential travels
     * as a problem extension rather than as a body of its own.
     *
     * @param array<string, mixed> $extensions
     */
    public static function conflict(string $detail, array $extensions = []): self
    {
        return new self(409, 'Conflict', $detail, $extensions);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function validation(array $errors): self
    {
        return new self(422, 'Validation failed', null, ['errors' => $errors]);
    }

    public static function tooManyRequests(?int $retryAfterSeconds = null): self
    {
        $extensions = $retryAfterSeconds === null ? [] : ['retry_after' => $retryAfterSeconds];

        return new self(429, 'Too many requests', null, $extensions);
    }

    public static function internal(): self
    {
        return new self(500, 'Internal error');
    }

    public function toResponse(): ApiResponse
    {
        // The four RFC 9457 members are not extensible. Merged the other way
        // round, an extension called "status" replaced the problem's own
        // status, so two shipped 409s answered a string where the contract
        // and the generated SDK both declare an integer, and every assertion
        // on the response's HTTP status still passed.
        $body = array_merge([
            'type' => 'about:blank',
            'title' => $this->title,
            'status' => $this->status,
        ], $this->detail !== null ? ['detail' => $this->detail] : [], array_diff_key(
            $this->extensions,
            array_flip(self::RESERVED),
        ));

        return new ApiResponse($this->status, $body, ['Content-Type' => 'application/problem+json']);
    }
}
