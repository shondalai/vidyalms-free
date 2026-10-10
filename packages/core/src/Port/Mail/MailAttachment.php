<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Mail;

/**
 * One file travelling with an outbound mail.
 *
 * **It exists because the mailer seam could not carry one and a registered
 * delivery provider needed it.** `SupportsAttachments` has been in the
 * notification registry since the channel capabilities were seated, and its
 * docblock promises that a channel accepting attachments receives them; nothing
 * in the product could ever hand one over, because `MailMessage` had no member
 * for it. A capability nothing can exercise is a capability that has never been
 * tested, and the first provider to need it found that out.
 *
 * **The bytes travel rather than a path, and the ceiling is what makes that
 * safe.** A storage reference would have been cheaper in memory and would have
 * put the resolution rule in every adapter: two hosts reading a private storage
 * path two ways, with the failure appearing as a mail that silently arrives
 * without its attachment. The provider that builds one of these enforces its
 * own size ceiling before reading, so the memory an attachment costs is bounded
 * by a number a site owner set rather than by whatever the report happened to
 * produce.
 *
 * **An adapter that cannot attach drops it and says so**, which is the
 * published default of `SupportsAttachments` and not a new rule: the send is
 * recorded as degraded rather than failing, because a message that arrived
 * without its file is better than a message that never arrived.
 */
final class MailAttachment
{
    private function __construct(
        /** The file name the recipient sees, already sanitised by the caller. */
        public readonly string $name,
        public readonly string $mimeType,
        /** The file itself. Bounded by the caller's own ceiling before it is read. */
        public readonly string $contents,
    ) {
    }

    /**
     * The one door, with the name reduced to something a mail client and a
     * file system can both hold.
     *
     * A name is taken to its basename and stripped of everything but a
     * conservative set, because an attachment name arrives from a report key
     * and a format key and a header built from an unfiltered one is a header
     * injection with a file attached to it.
     */
    public static function of(string $name, string $mimeType, string $contents): self
    {
        $base = basename(str_replace('\\', '/', $name));
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? '';
        $safe = trim($safe, '.');

        if ($safe === '') {
            $safe = 'attachment';
        }

        return new self(
            substr($safe, 0, 120),
            $mimeType === '' ? 'application/octet-stream' : $mimeType,
            $contents,
        );
    }

    public function byteSize(): int
    {
        return \strlen($this->contents);
    }
}
