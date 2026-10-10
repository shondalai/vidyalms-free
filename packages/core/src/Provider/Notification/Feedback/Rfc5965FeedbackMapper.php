<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Feedback;

use Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender;
use Shondalai\VidyaLMS\Core\Registry\Context\FeedbackContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedback;
use Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedbackMapperInterface;

/**
 * The abuse reporting format, mapped to the neutral delivery feedback shape.
 *
 * It needs no vendor account because the grammar is published: Feedback-Type,
 * User-Agent and Version are required, and Original-Mail-From,
 * Original-Rcpt-To, Arrival-Date, Reported-Domain and Source-IP are common.
 * That is what makes it the free provider of this registry rather than a seam
 * only a paid mapper reaches.
 *
 * It reads the report block as a header list rather than parsing the whole
 * multipart body, because a feedback report forwarded by a mailbox rule
 * arrives in more shapes than one and the fields it needs are the same in all
 * of them.
 */
final class Rfc5965FeedbackMapper implements DeliveryFeedbackMapperInterface
{
    public const KEY = 'rfc5965';

    private const FEEDBACK_TYPES = [
        'abuse' => DeliveryFeedback::TYPE_COMPLAINT,
        'fraud' => DeliveryFeedback::TYPE_COMPLAINT,
        'virus' => DeliveryFeedback::TYPE_COMPLAINT,
        'other' => DeliveryFeedback::TYPE_COMPLAINT,
        'opt-out' => DeliveryFeedback::TYPE_UNSUBSCRIBE,
        'not-spam' => DeliveryFeedback::TYPE_DELIVERED,
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.feedback.rfc5965';
    }

    public function claims(string $body, string $contentType, FeedbackContext $context): bool
    {
        if (stripos($contentType, 'feedback-report') !== false || stripos($contentType, 'multipart/report') !== false) {
            return true;
        }

        return stripos($body, 'Feedback-Type:') !== false;
    }

    public function map(string $body, string $contentType, FeedbackContext $context): array
    {
        $fields = $this->fields($body);
        $feedbackType = strtolower($fields['feedback-type'] ?? '');

        if ($feedbackType === '' || !isset(self::FEEDBACK_TYPES[$feedbackType])) {
            return [];
        }

        $recipient = $fields['original-rcpt-to'] ?? null;

        if ($recipient !== null) {
            $recipient = trim($recipient, " \t<>");
        }

        $occurredAt = null;
        $arrival = $fields['arrival-date'] ?? null;

        if (\is_string($arrival) && $arrival !== '') {
            try {
                $occurredAt = new \DateTimeImmutable($arrival);
            } catch (\Exception) {
                $occurredAt = null;
            }
        }

        return [new DeliveryFeedback(
            self::FEEDBACK_TYPES[$feedbackType],
            $recipient === '' ? null : $recipient,
            $this->messageIdOf($fields),
            $occurredAt ?? $context->receivedAt,
            $fields['reported-domain'] ?? null,
        )];
    }

    /**
     * The queue row this report is about.
     *
     * An envelope id is the direct answer and is preferred, but RFC 5965
     * names Original-Mail-From as a common field and few reporters carry an
     * envelope id at all. Our own reverse path is a VERP address, so the tag
     * inside it is the same string the channel reported as its provider
     * message id, and reading it here is what closes the round trip. An
     * address with no subaddress belongs to another sender and answers null
     * rather than a guess.
     *
     * @param array<string, string> $fields
     */
    private function messageIdOf(array $fields): ?string
    {
        $direct = $fields['original-envelope-id'] ?? ($fields['x-vidyalms-message-id'] ?? null);

        if ($direct !== null && $direct !== '') {
            return $direct;
        }

        $reversePath = $fields['original-mail-from'] ?? null;

        return $reversePath === null || $reversePath === '' ? null : EnvelopeSender::tagOf($reversePath);
    }

    /**
     * @return array<string, string>
     */
    private function fields(string $body): array
    {
        $fields = [];

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $colon = strpos($line, ':');

            if ($colon === false || $colon === 0) {
                continue;
            }

            $name = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            if ($name !== '' && !isset($fields[$name])) {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }
}
