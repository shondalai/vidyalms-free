<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\AttachesArtefact;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryResult;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryInterface;

/**
 * A delivery provider that puts the artefact in the message, which neither
 * shipped provider does.
 *
 * It exists so the second delivery refusal is tested against a real registered
 * provider rather than against a mock: the rule has to be true on the day a
 * package this repository does not build registers one, and a rule proved only
 * against a double is a rule nobody has run.
 */
final class AttachingDelivery implements ReportDeliveryInterface, AttachesArtefact
{
    public const KEY = 'test-email-attachment';

    /** @var list<int> */
    public array $reached = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Email attachment (test)';
    }

    public function deliver(ReportRun $run, DeliveryContext $context): DeliveryResult
    {
        $this->reached = $context->recipientUserIds;

        return DeliveryResult::delivered('attached');
    }
}
