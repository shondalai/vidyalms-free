<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ProvisioningSource;

/**
 * A provisioning source that can be swept incrementally from a watermark.
 *
 * The watermark is an opaque string this product stores and hands back
 * unchanged. It is a modification timestamp for one kind of source and a
 * continuation link for another, and the core deliberately knows which of
 * those it is holding: parsing it would make the core's storage depend on a
 * protocol's own paging model, which is the thing the mapper seam exists to
 * keep out.
 *
 * **A sweep that cannot resume is a sweep that re-reads everything.** On a
 * district roster that is a nightly full read of every person, which is the
 * cost that makes customers turn synchronisation off. So the watermark is
 * advanced only after the rows it covers have been written, and a run that
 * fails part way leaves the previous watermark in place: re-reading a page is
 * cheap and idempotent, and skipping one is a person who never arrives.
 *
 * A source that does not declare this is swept in full every time, which is
 * correct for a small directory and is what the free build does with nothing
 * at all, because no free source is continuous.
 */
interface SupportsDelta
{
    /**
     * Runs this source from a watermark, and returns the watermark to store.
     *
     * Null in means "everything", which is what a first run and a reset both
     * look like. Null out means this run has nothing to resume from and the
     * next one starts again from the beginning, which a source must return
     * rather than inventing a value it cannot honour.
     *
     * @param int         $organisationId the tenant this run belongs to, from the run's own
     *                                    context and never from a payload field
     * @param string|null $watermark      the value this product stored last time, unparsed
     *
     * @return array{watermark: string|null, seen: int, changed: int} what to store and what happened
     */
    public function sweepFrom(int $organisationId, ?string $watermark): array;
}
