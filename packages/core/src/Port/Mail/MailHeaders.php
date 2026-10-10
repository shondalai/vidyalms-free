<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Mail;

/**
 * The header map a channel hands MailerPort, built once so both adapters
 * receive the identical thing.
 *
 * This is a decorator over a message class and not a per-host convention,
 * because three of the four fields it writes are obligations rather than
 * taste. RFC 8058 is exact: List-Unsubscribe carries one HTTPS URI, the value
 * of List-Unsubscribe-Post is the literal string List-Unsubscribe=One-Click,
 * and a receiver honours the pair only when both names are covered by the DKIM
 * signature. RFC 2369 orders the URIs, https first with a mailto alongside.
 * RFC 3834 stamps automatic mail so a vacation responder does not answer it.
 *
 * The class decides what is written, and the class is a property of the
 * message rather than of the moment, because CAN-SPAM's primary purpose test
 * asks what a reasonable recipient would conclude from the subject line. A
 * transactional message carries no unsubscribe pair and no Precedence: adding
 * them would invite a receiver to file a password reset as bulk, and a site is
 * sometimes legally required to deliver exactly those messages.
 */
final class MailHeaders
{
    public const TRANSACTIONAL = 'transactional';

    public const OPERATIONAL = 'operational';

    public const OPTIONAL = 'optional';

    public const MARKETING = 'marketing';

    /**
     * The exact value RFC 8058 section 3.1 requires. It is a constant rather
     * than a literal at the call site because a receiver compares it verbatim
     * and a stray space silently disables one-click.
     */
    public const ONE_CLICK = 'List-Unsubscribe=One-Click';

    /**
     * Non-transactional classes, the ones that carry the unsubscribe pair.
     */
    private const UNSUBSCRIBABLE = [self::OPERATIONAL, self::OPTIONAL, self::MARKETING];

    /**
     * @param string  $messageClass     one of the four constants above
     * @param ?string $unsubscribeUrl   the HTTPS landing page for this recipient and category
     * @param ?string $unsubscribeMailto a mailto: URI, listed after the HTTPS one
     * @param ?string $feedbackId       the identity a forwarded ARF report correlates back to,
     *                                  normally the queue row's dedupe digest
     * @param ?string $channelKey       the channel the feedback route would be posted to
     *
     * @return array<string, string>
     */
    public static function build(
        string $messageClass,
        ?string $unsubscribeUrl = null,
        ?string $unsubscribeMailto = null,
        ?string $feedbackId = null,
        ?string $channelKey = null,
    ): array {
        $headers = ['Auto-Submitted' => 'auto-generated'];

        if (\in_array($messageClass, self::UNSUBSCRIBABLE, true)) {
            $headers['Precedence'] = 'bulk';

            $uris = [];

            // https first, per RFC 2369: a receiver that offers one button
            // offers the first URI it understands, and the one-click POST only
            // exists on the HTTPS side.
            if ($unsubscribeUrl !== null && str_starts_with($unsubscribeUrl, 'https://')) {
                $uris[] = '<' . $unsubscribeUrl . '>';
            }

            if ($unsubscribeMailto !== null && str_starts_with($unsubscribeMailto, 'mailto:')) {
                $uris[] = '<' . $unsubscribeMailto . '>';
            }

            if ($uris !== []) {
                $headers['List-Unsubscribe'] = implode(', ', $uris);

                // The POST variant is advertised only when an HTTPS URI is
                // present: RFC 8058 has nowhere to send a one-click POST
                // otherwise, and a receiver that sees the pair without one
                // treats the message as malformed rather than as unsubscribable.
                if ($unsubscribeUrl !== null && str_starts_with($unsubscribeUrl, 'https://')) {
                    $headers['List-Unsubscribe-Post'] = self::ONE_CLICK;
                }
            }
        }

        if ($feedbackId !== null && $feedbackId !== '') {
            // Google's Feedback-ID grammar: up to three site-chosen fields and
            // a required trailing sender identifier.
            $headers['Feedback-ID'] = self::sanitise($feedbackId) . ':' . $messageClass . ':vidyalms';
            $headers['X-VidyaLMS-Feedback-Id'] = self::sanitise($feedbackId);
        }

        if ($channelKey !== null && $channelKey !== '') {
            $headers['X-VidyaLMS-Channel'] = self::sanitise($channelKey);
        }

        return $headers;
    }

    /**
     * Header values reach an SMTP transport, so a CR, an LF or a colon in a
     * value taken from a stored row is a header injection. Nothing in the
     * shipped inputs contains one; the guard is here because a third-party
     * channel may pass a value the core never saw.
     */
    private static function sanitise(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._@+-]+/', '-', $value) ?? '';

        return trim($clean, '-');
    }
}
