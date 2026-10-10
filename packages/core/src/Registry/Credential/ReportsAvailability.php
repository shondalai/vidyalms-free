<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credential;

/**
 * A format that can be installed and still unable to serve on this site.
 *
 * **Absent means always available**, which is true of both free formats: an
 * unsigned document and a hosted-verification PNG need nothing configured.
 *
 * It exists because a configuration miss was reaching the caller as a 500. A
 * signing format with no key reference configured threw out of build(), the
 * exception escaped the use case, and the kernel's catch-all turned it into
 * an internal error, so a site with a signing package installed but no key
 * was worse off than a free site: the free site correctly answered 406 and
 * named what it could serve. A format that cannot serve is a **negotiation
 * miss**, exactly like a format that is not installed, and the design says so
 * in as many words: never a 500 and never a silent substitution.
 *
 * The registry consults this before negotiation, so an unavailable format is
 * absent from what a 406 body lists as servable. It is asked on every
 * negotiation rather than cached, because configuring the key is what makes
 * the answer change and nothing invalidates a cache when it does.
 */
interface ReportsAvailability
{
    /**
     * Whether this build can actually produce this format right now.
     *
     * It must not throw and must not reach a network: a format that cannot
     * answer cheaply answers true and refuses inside build(), which is the
     * worse outcome but not a worse one than a boot that hangs.
     */
    public function isAvailable(): bool;
}
