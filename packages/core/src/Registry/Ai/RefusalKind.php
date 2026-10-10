<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * The twelve reasons a model call or an AI task produced nothing.
 *
 * A closed enum rather than a free string, because two of these drive a
 * decision no caller may take by pattern-matching prose. `upstream_refused`
 * is the model itself declining, which is a content signal recorded against
 * the input and is not retryable; `upstream_unavailable` is a transport that
 * failed, which is retryable and says nothing at all about the content.
 * Collapsing them pays for the same refusal eight times and leaves an input
 * corpus with no record of what the model would not do.
 *
 * A refusal is an ordinary outcome and never an exception. An exception out
 * of a provider stays reserved for programmer error.
 */
enum RefusalKind: string
{
    /** No enabled route serves this task at this scope. */
    case NotConfigured = 'not_configured';

    /** The route cannot serve this task's shape. */
    case UnsupportedCapability = 'unsupported_capability';

    /** A deny from a ceiling policy. A period ceiling defers instead. */
    case CeilingExceeded = 'ceiling_exceeded';

    /** The upstream asked for a pause, and usually said when. */
    case UpstreamRateLimited = 'upstream_rate_limited';

    /** The transport failed. Nothing is implied about the content. */
    case UpstreamUnavailable = 'upstream_unavailable';

    /** The model itself declined. A content signal, recorded against the input. */
    case UpstreamRefused = 'upstream_refused';

    /**
     * The declared field tree rejected the payload, with a structured error
     * list the review screen renders. A task or service definition bug, and
     * deliberately not `upstream_refused`, which would file a caller bug as
     * a content signal about the model.
     */
    case InputRejected = 'input_rejected';

    /**
     * The service's own maximum input ceiling. Not retryable as sent: the
     * job runner re-chunks and re-queues the unit rather than retrying it
     * unchanged, which is the only response that can succeed.
     */
    case InputTooLarge = 'input_too_large';

    /** Parsed, and did not satisfy the declared output schema. */
    case SchemaInvalid = 'schema_invalid';

    /** A moderating bridge blocked the input or the output. */
    case ModerationBlocked = 'moderation_blocked';

    /** The inline deadline elapsed. */
    case DeadlineExceeded = 'deadline_exceeded';

    /** The site-wide stop control, or an operator cancel. */
    case Cancelled = 'cancelled';

    /**
     * Whether the same call may be sent again unchanged.
     *
     * Only the two transport-shaped kinds are. Everything else either says
     * something about the content, about the request as composed, or about a
     * decision a person made, and re-sending it buys a second identical
     * answer at a second price.
     */
    public function isRetryable(): bool
    {
        return $this === self::UpstreamRateLimited || $this === self::UpstreamUnavailable;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
