<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ReportDelivery;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;

/**
 * What a delivery provider is handed beside the run.
 *
 * **`renderAs` is the security-critical setting and its default is the
 * recipient.** Rendering one run under the author's visibility and sending the
 * same file to a mixed audience is a data breach the first time a site has two
 * group leaders. Rendering per recipient, each under their own scope, is what
 * lets one schedule serve twenty leaders correctly; author scope exists only as
 * an explicit choice and the screen that offers it names what it means.
 */
final class DeliveryContext
{
    /** One artefact per recipient, each computed under that recipient's own scope. */
    public const RENDER_AS_RECIPIENT = 'recipient';

    /** One artefact under the author's scope, sent to everyone. Explicit only. */
    public const RENDER_AS_AUTHOR = 'author';

    /**
     * @param list<int> $recipientUserIds
     */
    private function __construct(
        public readonly ReportRun $run,
        public readonly array $recipientUserIds,
        public readonly string $renderAs,
        /** The signed, expiring URL for the artefact, or null when there is none. */
        public readonly ?string $signedUrl,
        public readonly ?\DateTimeImmutable $urlExpiresAt,
        public readonly string $categoryKey,
        public readonly string $locale,
        public readonly ?string $messageSubject,
        public readonly ?string $messageBody,
    ) {
    }

    /**
     * @param list<int> $recipientUserIds
     */
    public static function create(
        ReportRun $run,
        array $recipientUserIds = [],
        ?string $signedUrl = null,
        ?\DateTimeImmutable $urlExpiresAt = null,
        string $renderAs = self::RENDER_AS_RECIPIENT,
        string $categoryKey = 'vidyalms.category.reports',
        string $locale = 'en-GB',
        ?string $messageSubject = null,
        ?string $messageBody = null,
    ): self {
        if ($renderAs !== self::RENDER_AS_RECIPIENT && $renderAs !== self::RENDER_AS_AUTHOR) {
            throw new \InvalidArgumentException('A render-as setting is "recipient" or "author".');
        }

        $unique = array_values(array_unique(array_filter(
            $recipientUserIds,
            static fn (int $id): bool => $id > 0,
        )));

        return new self($run, $unique, $renderAs, $signedUrl, $urlExpiresAt, $categoryKey, $locale, $messageSubject, $messageBody);
    }
}
