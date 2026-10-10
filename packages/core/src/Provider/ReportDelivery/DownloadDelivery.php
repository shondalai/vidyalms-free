<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ReportDelivery;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryResult;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryInterface;

/**
 * The artefact waits where the run record already points, and nobody is told.
 *
 * It is the delivery for a person who asked for the export and is watching
 * `GET /v1/manage/report-runs/{id}` for it, which is the whole of the 202
 * contract's own flow. It exists as a registered provider rather than as the
 * absence of one so that `report_runs.delivery_key` always names something and
 * a null there keeps meaning "nothing was chosen" rather than "download".
 */
final class DownloadDelivery implements ReportDeliveryInterface
{
    public const KEY = 'download';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.delivery.download');
    }

    public function deliver(ReportRun $run, DeliveryContext $context): DeliveryResult
    {
        return DeliveryResult::waiting($run->storagePath);
    }
}
