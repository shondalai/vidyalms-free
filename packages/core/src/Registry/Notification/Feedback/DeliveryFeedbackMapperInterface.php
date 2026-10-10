<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Feedback;

use Shondalai\VidyaLMS\Core\Registry\Context\FeedbackContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One external delivery-report format, mapped to one neutral value object.
 *
 * The free build ships the RFC 5965 message/feedback-report mapper, which
 * needs no vendor account because the grammar is published: Feedback-Type,
 * User-Agent and Version are required, and Original-Mail-From,
 * Original-Rcpt-To, Arrival-Date, Reported-Domain and Source-IP are common.
 *
 * It has a call site rather than being a seam nobody can reach: the mail port
 * is send-only, there is no inbound mail port, and a mapper nothing can hand
 * a payload to is an empty seam. The core route that feeds it is
 * POST /notifications/feedback/{channelKey}.
 *
 * The miss direction is published: a payload no mapper claims is logged
 * unparsed and no suppression is written.
 */
interface DeliveryFeedbackMapperInterface extends ProviderInterface
{
    public function label(): string;

    /** Whether this mapper recognises the payload at all. */
    public function claims(string $body, string $contentType, FeedbackContext $context): bool;

    /**
     * @return list<DeliveryFeedback> possibly empty, which is a claimed but empty report
     */
    public function map(string $body, string $contentType, FeedbackContext $context): array;
}
