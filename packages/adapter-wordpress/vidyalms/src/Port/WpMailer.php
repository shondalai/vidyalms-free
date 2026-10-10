<?php

declare(strict_types=1);

/**
 * VidyaLMS: the mailer port over wp_mail.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Port\Mail\MailParameters;
use Shondalai\VidyaLMS\Core\Port\Mail\MailerPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

defined('ABSPATH') || exit;

/**
 * Transactional mail through wp_mail.
 *
 * Two shapes arrive here and MailMessage::isRendered() tells them apart. A
 * rendered message was composed by the core and is sent as written: subject,
 * plain part and optional HTML part are final. A template-addressed message
 * keeps the behaviour this port has always had, rendering the template key
 * plus ".subject" and ".body" from the core catalogue.
 *
 * Whatever SMTP plugin the site runs picks either up from wp_mail like any
 * other mail.
 *
 * Three things wp_mail cannot express through its own arguments are set on
 * phpmailer_init, which fires immediately before the send:
 *
 *   AltBody   the plain part of a multipart/alternative message. wp_mail sends
 *             one body; PHPMailer emits the plain part first and the HTML part
 *             last when Body holds the HTML and AltBody the text, which is the
 *             RFC 2046 section 5.1.4 ordering MailerPort requires.
 *   Sender    the SMTP MAIL FROM. It is not the From header, and substituting
 *             the From address would attribute every bounce to the wrong
 *             stream.
 *   headers   wp_mail does pass unrecognised names to addCustomHeader, so the
 *             map travels in the headers array; the hook only removes itself.
 *
 * The listener is added for the duration of one send and removed in a finally
 * block, because a site sending its own mail between two of ours must not
 * inherit our envelope sender.
 *
 * wp_mail() returns false when the mailer refused the message, and the core
 * records the outcome from the exception, so a false has to throw rather than
 * pass for a send.
 */
final class WpMailer implements MailerPort
{
    /**
     * Filter: (array $headers, MailMessage $message): array
     *
     * It is asked for the site's own additional headers. The map the core
     * built is merged over the answer afterwards, so a filter can add a
     * List-Id or a stream tag and cannot remove List-Unsubscribe,
     * List-Unsubscribe-Post or Auto-Submitted: a receiver honours RFC 8058
     * one-click only when the pair survives intact, and dropping it is not a
     * decision a site plugin gets to take silently.
     */
    public const HEADERS_FILTER = 'vidyalms_mail_headers';

    /**
     * Headers whose value is a structure rather than a phrase, so an RFC 2047
     * encoded-word in them is corruption and not encoding. RFC 8058 names the
     * first two and RFC 3834 the third.
     *
     * @var list<string>
     */
    private const STRUCTURED_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post', 'Auto-Submitted'];

    public function __construct(
        private readonly TranslatorPort $translator,
        private readonly UsersPort $users,
    ) {
    }

    public function send(MailMessage $message): void
    {
        $parameters = MailParameters::scalar($message->parameters);

        if ($message->isRendered()) {
            // A rendered message may carry only one of the two parts, so the
            // subject falls back to the catalogue rather than going out empty:
            // the message class was decided from the subject line and an empty
            // one is not a message.
            $subject = $message->subject;
            $subject = $subject !== null && trim($subject) !== ''
                ? $subject
                : $this->translator->translate($message->templateKey . '.subject', $parameters);
            $html    = $message->bodyHtml;
            $text    = $message->bodyText ?? ($html === null ? '' : wp_strip_all_tags($html));
        } else {
            $subject = $this->translator->translate($message->templateKey . '.subject', $parameters);
            $text    = $this->translator->translate($message->templateKey . '.body', $parameters);
            $html    = null;
        }

        $multipart = $html !== null && $html !== '';
        $headers   = $this->headers($message, $multipart);
        $body      = $multipart ? $html : $text;

        $configure = $this->phpmailerListener($message, $multipart ? $text : null);
        $restore = null;
        $listener = null;
        $listener = static function (object $mailer) use ($configure, &$restore, &$listener): void {
            // wp_mail reuses PHPMailer. Scope our changes to this send, even
            // when an SMTP plugin throws or sends another message recursively.
            remove_action('phpmailer_init', $listener, 10);
            $sender = $mailer->Sender;
            $altBody = $mailer->AltBody;
            $restore = static function () use ($mailer, $sender, $altBody): void {
                $mailer->Sender = $sender;
                $mailer->AltBody = $altBody;
            };
            $configure($mailer);
        };
        add_action('phpmailer_init', $listener, 10, 1);

        try {
            $sent = wp_mail($this->recipient($message), $subject, (string) $body, $headers);
        } finally {
            remove_action('phpmailer_init', $listener, 10);
            if ($restore !== null) {
                $restore();
            }
        }

        if ($sent === false) {
            throw new \RuntimeException(sprintf(
                'WordPress refused to send the mail "%s" to user %d.',
                // Exception arguments are data; escape at the HTTP/template boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $message->templateKey,
                // Exception arguments are data; escape at the HTTP/template boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $message->recipientUserId,
            ));
        }
    }

    /**
     * The site's own headers first, then the core's over the top.
     *
     * @return list<string>
     */
    private function headers(MailMessage $message, bool $multipart): array
    {
        /** @var mixed $filtered */
        $filtered = apply_filters('vidyalms_mail_headers', [], $message);

        $lines = [];

        if (\is_array($filtered)) {
            foreach ($filtered as $line) {
                if (\is_string($line) && !$this->hasLineBreak($line)) {
                    $lines[] = $line;
                }
            }
        }

        if ($multipart) {
            // wp_mail sends text/plain by default. The HTML part is the body,
            // and phpmailer_init puts the plain part in AltBody, so PHPMailer
            // emits multipart/alternative in the required order.
            $lines[] = 'Content-Type: text/html; charset=UTF-8';
        }

        foreach ($message->headers as $name => $value) {
            if ($this->hasLineBreak($name) || $this->hasLineBreak($value)) {
                continue;
            }

            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * @param ?string $plainPart the plain half of a multipart message, or null
     *
     * @return callable(object): void
     */
    private function phpmailerListener(MailMessage $message, ?string $plainPart): callable
    {
        $envelope   = $message->envelopeSender;
        $structured = [];

        foreach ($message->headers as $name => $value) {
            if (\in_array($name, self::STRUCTURED_HEADERS, true)) {
                $structured[$name] = $value;
            }
        }

        // The files travelling with the message. wp_mail's own attachments
        // argument takes file PATHS and the port carries bytes deliberately,
        // so they go on through PHPMailer here. This port mentioned
        // attachments nowhere, so the Pro email-attachment report delivery
        // composed an artefact, enforced its ceiling, recorded its refusals
        // and then sent an empty-handed message on every WordPress site.
        $attachments = $message->attachments;

        return static function (object $phpmailer) use ($plainPart, $envelope, $structured, $attachments): void {
            if ($plainPart !== null) {
                $phpmailer->AltBody = $plainPart;
            }

            foreach ($attachments as $attachment) {
                if (!method_exists($phpmailer, 'addStringAttachment')) {
                    break;
                }

                $phpmailer->addStringAttachment(
                    $attachment->contents,
                    $attachment->name,
                    'base64',
                    $attachment->mimeType,
                );
            }

            if ($envelope !== null && strpbrk($envelope, "\r\n") === false) {
                $phpmailer->Sender = $envelope;
            }

            // PHPMailer runs every custom header value through encodeHeader(),
            // and with no 8-bit characters in it the only trigger left is the
            // length: MAX_LINE_LENGTH is 998 for SMTP and sendmail, and
            // MAIL_MAX_LINE_LENGTH is 63 for PHP's own mail(), less sixteen
            // characters of Q-encoding overhead. So on a site sending through
            // mail(), any structured value over 47 characters comes out as an
            // encoded-word, and an encoded-word is not permitted in a
            // structured header value: a receiver reading List-Unsubscribe
            // finds no URI and RFC 8058 one-click stops working with nothing in
            // any log.
            //
            // The header still goes out, because an inert header is not harmful
            // and silently dropping a legally required mechanism would be
            // worse. What changes is that the site is told, with the remedy.
            $mailer   = (string) ($phpmailer->Mailer ?? 'mail');
            $maxLen   = $mailer === 'mail' ? 47 : 982;
            $mangled  = [];

            foreach ($structured as $name => $value) {
                if (\strlen($value) > $maxLen) {
                    $mangled[] = $name;
                }
            }

            if ($mangled !== [] && \defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    '[vidyalms] this site sends mail through PHP mail(), which makes PHPMailer encode the '
                    . 'structured header(s) %s. An encoded value is not a URI, so one-click unsubscribe will '
                    . 'not work until the site sends through SMTP.',
                    implode(', ', $mangled)
                ));
            }
        };
    }

    /**
     * "Name <address>" when a name is known. The name is stripped of the
     * characters that would let a display name break the header apart.
     */
    private function recipient(MailMessage $message): string
    {
        $name = $this->recipientName($message);

        return $name === '' ? $message->recipientEmail : sprintf('%s <%s>', $name, $message->recipientEmail);
    }

    private function recipientName(MailMessage $message): string
    {
        $name = $message->parameters['recipient_name'] ?? null;

        if (!\is_string($name) || trim($name) === '') {
            $name = $this->users->findById($message->recipientUserId)->name ?? '';
        }

        $clean = preg_replace('/[\r\n<>,;"]+/', ' ', $name);

        return trim($clean ?? '');
    }

    private function hasLineBreak(string $value): bool
    {
        return strpbrk($value, "\r\n") !== false;
    }
}
