<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailerInterface;
use Joomla\CMS\Mail\MailTemplate;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailerPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Port\Mail\MailParameters;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ComponentLog;

/**
 * Transactional mail through Joomla.
 *
 * Two shapes arrive here and they are told apart by MailMessage::isRendered().
 *
 * A rendered message was composed by the core: subject, plain part and
 * optional HTML part are final, and this port only puts them on the wire. It
 * does not go through MailTemplate, because MailTemplate would substitute its
 * own subject and its own layout over a subject the message class was decided
 * from, and the four legal obligations in MailerPort's docblock would then be
 * enforced by whichever template the site owner last edited.
 *
 * A template-addressed message keeps the behaviour this port has always had:
 * Joomla's MailTemplate renders it when the site owner registered a template
 * for the key, and otherwise the catalogue's translated subject and body go
 * out as plain mail.
 *
 * Both shapes carry the header map and the envelope sender. Both need the
 * concrete Mail class, because MailerInterface exposes neither custom headers
 * nor PHPMailer's Sender; a site that replaced the mailer service with its own
 * MailerInterface implementation gets the mail without them rather than a
 * fatal, and webExposedHeaders() reports which of the two it was.
 *
 * @since 1.0.0
 */
final class JoomlaMailer implements MailerPort
{
    private const TEMPLATE_PREFIX = 'com_vidyalms.';

    /**
     * Headers whose value is a structure rather than a phrase, so an RFC 2047
     * encoded-word in them is corruption and not encoding. RFC 8058 names the
     * first two and RFC 3834 the third.
     *
     * @var list<string>
     */
    private const STRUCTURED_HEADERS = ['List-Unsubscribe', 'List-Unsubscribe-Post', 'Auto-Submitted'];

    public function __construct(
        private readonly MailerFactoryInterface $mailers,
        private readonly TranslatorPort $translator,
        private readonly UsersPort $users,
        private readonly string $language = 'en-GB',
    ) {
    }

    public function send(MailMessage $message): void
    {
        if ($message->isRendered()) {
            $this->sendRendered($message);

            return;
        }

        $templateId = $this->templateId($message->templateKey);

        if (MailTemplate::getTemplate($templateId, $this->language) !== null) {
            $this->sendTemplate($templateId, $message);

            return;
        }

        $this->sendPlain($message);
    }

    /**
     * A message the core already composed.
     *
     * PHPMailer builds multipart/alternative with the plain part first and the
     * HTML part last exactly when Body holds the HTML and AltBody the text,
     * which is the RFC 2046 section 5.1.4 ordering MailerPort requires. Setting
     * Body to the text and AltBody to the HTML compiles and produces the
     * ordering reversed, so the assignment is not interchangeable and the
     * smoke harness asserts the resulting order.
     *
     * A rendered message may carry only one of the two parts, so the subject
     * falls back to the catalogue rather than being sent empty: a message class
     * is decided from the subject line and an empty one is not a message.
     */
    private function sendRendered(MailMessage $message): void
    {
        $mailer = $this->mailers->createMailer();
        $mailer->setSubject($this->subjectOf($message));
        $mailer->addRecipient($message->recipientEmail, $this->recipientName($message));

        $text = $message->bodyText;
        $html = $message->bodyHtml;

        if ($html !== null && $html !== '' && $mailer instanceof Mail) {
            $mailer->isHtml(true);
            $mailer->setBody($html);

            if ($text !== null && $text !== '') {
                $mailer->AltBody = $text;
            }
        } else {
            if ($mailer instanceof Mail) {
                $mailer->isHtml(false);
            }

            $mailer->setBody($text ?? strip_tags($html ?? ''));
        }

        $this->decorate($mailer, $message);
        $this->dispatch($mailer, $message->templateKey);
    }

    private function subjectOf(MailMessage $message): string
    {
        $subject = $message->subject;

        if ($subject !== null && trim($subject) !== '') {
            return $subject;
        }

        return $this->translator->translate(
            $message->templateKey . '.subject',
            MailParameters::scalar($message->parameters)
        );
    }

    private function sendTemplate(string $templateId, MailMessage $message): void
    {
        // MailTemplate types its mailer as the concrete Mail class, so a
        // replacement MailerInterface implementation is left to Joomla.
        $mailer = $this->mailers->createMailer();
        $this->decorate($mailer, $message);

        $mail = new MailTemplate($templateId, $this->language, $mailer instanceof Mail ? $mailer : null);
        $mail->addRecipient($message->recipientEmail, $this->recipientName($message));
        $mail->addTemplateData($this->templateData($message));

        // MailerInterface documents send() as void plus an exception on
        // failure, while the concrete Mail class inherits PHPMailer's boolean.
        // Only an explicit false is a failure: a void return is a success.
        if ($mail->send() === false) {
            throw new \RuntimeException(sprintf('Joomla refused to send the mail template "%s".', $templateId));
        }
    }

    private function sendPlain(MailMessage $message): void
    {
        $parameters = MailParameters::scalar($message->parameters);

        $mailer = $this->mailers->createMailer();
        $mailer->setSubject($this->translator->translate($message->templateKey . '.subject', $parameters));
        $mailer->setBody($this->translator->translate($message->templateKey . '.body', $parameters));
        $mailer->addRecipient($message->recipientEmail, $this->recipientName($message));

        $this->decorate($mailer, $message);
        $this->dispatch($mailer, $message->templateKey);
    }

    /**
     * The header map and the envelope sender, applied verbatim.
     *
     * Nothing is rewritten and no name is dropped: List-Unsubscribe and
     * List-Unsubscribe-Post are honoured by a receiver only when both survive
     * intact, and Joomla's own DKIM signing (when the site has any) covers the
     * headers present on the message. A name or value carrying a CR or an LF is
     * skipped rather than folded, because folding an injected value is how a
     * header split becomes a second message.
     */
    private function decorate(MailerInterface $mailer, MailMessage $message): void
    {
        if (!$mailer instanceof Mail) {
            return;
        }

        $mangled = [];

        foreach ($message->headers as $name => $value) {
            if ($this->hasLineBreak($name) || $this->hasLineBreak($value)) {
                continue;
            }

            if (\in_array($name, self::STRUCTURED_HEADERS, true) && $this->wouldEncode($mailer, $value)) {
                $mangled[] = $name;
            }

            $mailer->addCustomHeader($name, $value);
        }

        if ($mangled !== []) {
            $this->reportEncodedStructuredHeaders($mangled);
        }

        if ($message->envelopeSender !== null && !$this->hasLineBreak($message->envelopeSender)) {
            // PHPMailer's Sender is the SMTP MAIL FROM. Joomla's setSender()
            // writes the From header, which is a different field and would
            // attribute every bounce to the wrong stream.
            $mailer->Sender = $message->envelopeSender;
        }

        $this->attach($mailer, $message);
    }

    /**
     * The files travelling with the message.
     *
     * `MailMessage::$attachments` carried bytes and this port mentioned
     * attachments nowhere, so the Pro email-attachment report delivery
     * composed an artefact, enforced its size ceiling, recorded its refusals,
     * and then sent an empty-handed message on every Joomla site. The bytes go
     * through PHPMailer's addStringAttachment rather than a path, because the
     * port carries bytes deliberately: a storage reference would put the
     * resolution rule in two adapters and fail as a silently missing file.
     *
     * A replacement MailerInterface implementation has no such method, so it
     * gets the mail without the file, which is the published default of
     * `SupportsAttachments`: a message that arrived without its file is better
     * than a message that never arrived.
     */
    private function attach(Mail $mailer, MailMessage $message): void
    {
        foreach ($message->attachments as $attachment) {
            $mailer->addStringAttachment(
                $attachment->contents,
                $attachment->name,
                'base64',
                $attachment->mimeType,
            );
        }
    }

    /**
     * Whether PHPMailer will RFC 2047 encode this value, which for a
     * structured header is corruption rather than encoding.
     *
     * PHPMailer runs every custom header value through encodeHeader(). With no
     * control or 8-bit characters in it the only thing that triggers an
     * encoding is the length, and the length that triggers it depends on the
     * transport: MAX_LINE_LENGTH is 998 for SMTP and sendmail, and
     * MAIL_MAX_LINE_LENGTH is 63 for PHP's own mail(), from which the 16
     * characters of Q-encoding overhead are taken. So on a site that sends
     * through mail(), any structured value over 47 characters comes out as an
     * encoded-word, and an encoded-word is not permitted in a structured
     * header value: a receiver reading List-Unsubscribe finds no URI at all
     * and RFC 8058 one-click stops working with nothing in any log.
     *
     * The header is still emitted, because an inert header is not harmful and
     * dropping a legally required mechanism silently would be worse. What
     * changes is that the site is told, with the remedy, rather than left to
     * discover it from a deliverability report.
     */
    private function wouldEncode(Mail $mailer, string $value): bool
    {
        if (preg_match('/[\000-\010\013\014\016-\037\177-\377]/', $value) === 1) {
            return true;
        }

        $overhead = 8 + \strlen('us-ascii');
        $maxLen   = $mailer->Mailer === 'mail' ? 63 - $overhead : 998 - $overhead;

        return \strlen($value) > $maxLen;
    }

    /**
     * @param list<string> $names
     */
    private function reportEncodedStructuredHeaders(array $names): void
    {
        ComponentLog::error(sprintf(
            'VidyaLMS: this site sends mail through PHP mail(), which makes PHPMailer encode the '
            . 'structured header(s) %s. An encoded value is not a URI, so one-click unsubscribe will '
            . 'not work until the site is configured to send through SMTP or sendmail.',
            implode(', ', $names),
        ));
    }

    /**
     * Two ways a send reports a failure, and both are honoured.
     *
     * MailerInterface declares send() as void and signals failure by throwing,
     * which is what a replacement mailer service does. The concrete Mail class
     * inherits PHPMailer's boolean instead, and Joomla constructs PHPMailer
     * with exceptions on, so it usually throws as well and returns false only
     * where it does not. Reading the boolean off the interface-typed value
     * would be reading a return the interface says does not exist, so the
     * concrete case is narrowed first.
     */
    private function dispatch(MailerInterface $mailer, string $templateKey): void
    {
        try {
            if ($mailer instanceof Mail) {
                if ($mailer->send() === false) {
                    throw new \RuntimeException(
                        sprintf('Joomla refused to send the mail "%s".', $templateKey)
                    );
                }

                return;
            }

            $mailer->send();
        } catch (\RuntimeException $refusal) {
            throw $refusal;
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                sprintf('Joomla refused to send the mail "%s".', $templateKey),
                0,
                $failure,
            );
        }
    }

    private function hasLineBreak(string $value): bool
    {
        return strpbrk($value, "\r\n") !== false;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function templateData(MailMessage $message): array
    {
        $data = MailParameters::scalar($message->parameters);
        $data['recipient_name'] = $this->recipientName($message);

        if ($message->eventReference !== null) {
            $data['event_reference'] = $message->eventReference;
        }

        return $data;
    }

    private function recipientName(MailMessage $message): string
    {
        $name = $message->parameters['recipient_name'] ?? null;

        if (\is_string($name) && $name !== '') {
            return $name;
        }

        return $this->users->findById($message->recipientUserId)->name ?? $message->recipientEmail;
    }

    /**
     * "vidyalms.mail.enrolled" becomes the Joomla mail template id
     * "com_vidyalms.mail.enrolled".
     */
    private function templateId(string $templateKey): string
    {
        return self::TEMPLATE_PREFIX . preg_replace('/^vidyalms\./', '', $templateKey);
    }
}
