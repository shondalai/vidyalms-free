<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Mail;

/**
 * Outbound mail. Joomla implements it with MailTemplate, WordPress with
 * wp_mail. Sending failures must throw; the caller records the outcome, and
 * the notification channel translates a throw into a classified send result
 * so the queue can decide whether to retry.
 *
 * The adapter contract, stated here because it is a legal obligation and not
 * a preference:
 *
 * - A message carrying rendered parts is sent as authored. The host template
 *   may wrap the layout and may not replace the subject or the parts, and
 *   multipart alternatives are ordered plain first and HTML last.
 * - Every header in the message's header map is emitted verbatim. The two
 *   List-Unsubscribe header names must be covered by the DKIM signature and
 *   named in the h= tag wherever the adapter controls signing, and neither
 *   may be rewritten or dropped: RFC 8058 one-click fails silently otherwise
 *   and the site loses its lawful unsubscribe mechanism without noticing.
 * - The envelope sender, where the transport allows one to be set, is used as
 *   given. It carries a per-message tag and is what makes an asynchronous
 *   bounce attributable to the message that caused it. It is a full address
 *   of the form prefix+tag@domain and never a bare tag: PHPMailer validates
 *   the reverse path and refuses the whole message, so an adapter that is
 *   handed one is handed a message it cannot send. A message carrying none
 *   is sent without one and loses the attribution and nothing else.
 */
interface MailerPort
{
    public function send(MailMessage $message): void;
}
