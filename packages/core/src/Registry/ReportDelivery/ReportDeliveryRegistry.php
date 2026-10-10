<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ReportDelivery;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<ReportDeliveryInterface>
 */
final class ReportDeliveryRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'report_delivery';

    protected function providerInterface(): string
    {
        return ReportDeliveryInterface::class;
    }

    /**
     * The delivery, with the miss direction applied here rather than in each
     * caller.
     *
     * An unresolved key never throws out of a finished run: the artefact is
     * already written and the only thing left to decide is whether anybody was
     * told about it. Answering a failed result keeps the run good and puts the
     * missing provider on the record where an admin can read it.
     */
    public function deliverThrough(?string $key, ReportRun $run, DeliveryContext $context): DeliveryResult
    {
        if ($key === null || $key === '') {
            return DeliveryResult::waiting();
        }

        if (!$this->has($key)) {
            return DeliveryResult::failed('delivery_provider_missing:' . $key);
        }

        return $this->get($key)->deliver($run, $context);
    }
}
