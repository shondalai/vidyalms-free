<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeRequest;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\RegradePolicy;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;

/**
 * The regrade form, read off a request body, shared by the per-quiz and
 * per-attempt routes.
 */
final class RegradeRequestBody
{
    private const MAX_REASON = 500;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): RegradeRequest
    {
        $key = Body::requiredString($body, 'policy');
        $policy = RegradePolicy::fromKey($key);

        if ($policy === null) {
            throw ApiProblem::validation([
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'policy' => sprintf('Unknown regrade policy "%s".', $key),
            ]);
        }

        $reason = Body::nullableString($body, 'reason');

        if ($reason !== null && mb_strlen($reason) > self::MAX_REASON) {
            throw ApiProblem::validation([
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'reason' => sprintf('The reason must be at most %d characters.', self::MAX_REASON),
            ]);
        }

        return new RegradeRequest(
            $policy,
            \array_key_exists('question_ids', $body) ? Body::intList($body, 'question_ids') : [],
            Body::bool($body, 'dry_run', false),
            $reason,
        );
    }
}
