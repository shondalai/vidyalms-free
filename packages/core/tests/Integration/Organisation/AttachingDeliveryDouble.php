<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\AttachesArtefact;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryResult;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryInterface;

/**
 * A delivery provider that puts the artefact in the message.
 *
 * Neither shipped provider does, so the second delivery refusal has nothing to
 * bite on in the free build and would be a rule with no test. This double is the
 * package that arrives one day and registers one, which is exactly the case the
 * refusal is written for.
 */
final class AttachingDeliveryDouble implements ReportDeliveryInterface, AttachesArtefact
{
    public const KEY = 'test-attachment';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.test.attachment_delivery';
    }

    public function deliver(ReportRun $run, DeliveryContext $context): DeliveryResult
    {
        return DeliveryResult::delivered();
    }
}
