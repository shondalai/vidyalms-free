<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Integrity;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One kind of advisory integrity observation.
 *
 * **In the core because integrity_events is a free table and the route that
 * writes it is a core route.** There were briefly two stacks over one table,
 * a hardcoded const list plus a regular expression in the core and a full
 * registry in the paid package, and only the first was reachable: the
 * registry's payload allowlist and its per-kind suppression rule never ran
 * anywhere, while a vendor kind could write an arbitrary two-kilobyte blob
 * into an audit table. A new first-party kind was also a core edit to a
 * const array, which is exactly the shape the design forbids.
 *
 * A registry rather than an enum because a new integrity signal must not be
 * a core edit, and because a site's own proctoring integration is exactly
 * the case this exists for. What it may never become is enforcement:
 * **integrity features are evidence, never enforcement.** Window blur and
 * page visibility have a documented false positive rate against legitimate
 * access tools (separate-window and docked magnifiers, on-screen keyboards,
 * speech recognition correction windows, separate dictionary and read-aloud
 * applications all blur the browser), so no row written through this seam may
 * end an attempt, reduce a mark or refuse a submission. The actor is always
 * "system" and a human reads the row.
 */
interface IntegrityEventKindInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * Whether this kind is suppressed for a learner whose accessibility
     * profile declares a need the signal cannot distinguish from misconduct.
     *
     * True for the browser-focus family and false for a kind whose signal is
     * unambiguous, so a proctoring integration that observes something real
     * is not silently switched off for the learners it was bought for.
     */
    public function suppressedByAccessibilityProfile(): bool;

    /**
     * The observation's own payload, normalised. A kind that reads nothing
     * returns an empty array, which is what stops a client writing arbitrary
     * JSON into an audit table.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalise(array $context): array;
}
