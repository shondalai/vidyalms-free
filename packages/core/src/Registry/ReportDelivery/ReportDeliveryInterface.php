<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ReportDelivery;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * How a finished run reaches a person.
 *
 * It is a registry of its own and not a mode on the export one, because
 * serialising a result and putting it in front of somebody are different
 * questions with different failure modes. Folding delivery into the scheduler
 * is what makes a second delivery mode a branch instead of a registration.
 *
 * Free ships `download`, which leaves the artefact where the run record
 * already points, and `notification-link`, which enqueues a notification
 * carrying a signed expiring storage URL. Pro adds `email-attachment` as an
 * explicit opt-in; a third party adds `sftp`, a storage drop or an outbound
 * webhook with zero core edits.
 *
 * **Do not attach personal-data reports to email by default.** Revoking
 * somebody's access should revoke the report too, and a link does that while an
 * attachment sits in an inbox indefinitely. That is the free provider's
 * behaviour rather than a hardcoded branch somewhere above it.
 *
 * **Miss direction: the run completes, the artefact waits, and the schedule
 * records a delivery failure the admin can see.**
 */
interface ReportDeliveryInterface extends ProviderInterface
{
    public function label(): string;

    public function deliver(ReportRun $run, DeliveryContext $context): DeliveryResult;
}
