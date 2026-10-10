<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

/** The four channel-neutral parts and the closed-token render report. */
final class CompiledNotificationTemplate
{
    /**
     * @param list<string> $usedTokens
     * @param list<string> $unresolvedTokens
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly ?string $bodyHtml,
        public readonly ?string $bodyShort,
        public readonly array $usedTokens,
        public readonly array $unresolvedTokens,
        public readonly string $locale,
    ) {
    }

    /** @return array{subject:string,body_text:string,body_html:?string,body_short:?string} */
    public function parts(): array
    {
        return [
            'subject' => $this->subject,
            'body_text' => $this->bodyText,
            'body_html' => $this->bodyHtml,
            'body_short' => $this->bodyShort,
        ];
    }
}
