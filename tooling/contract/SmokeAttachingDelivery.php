<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\AttachesArtefact;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryResult;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryInterface;

/**
 * A delivery provider that puts the artefact in the message, which neither
 * shipped provider does.
 *
 * The second of the two crossing refusals is a rule about a delivery that
 * attaches, and no free delivery attaches. Proving the refusal therefore needs
 * a registered provider that does, or the rule is only ever proved against the
 * absence of the case it is about. This is that provider: it declares the
 * capability, it is registered into the live kernel for the length of one
 * section, and it never reaches its own deliver() because the refusal fires
 * before the run row opens.
 *
 * If it ever does reach deliver(), the section fails: `$reached` is what says
 * so.
 */
final class SmokeAttachingDelivery implements ReportDeliveryInterface, AttachesArtefact
{
    /**
     * Twenty-one characters, because `report_runs.delivery_key` and
     * `report_schedules.delivery_key` are both VARCHAR(24) and a run row is
     * written with the key on it.
     *
     * The first spelling was `vidyalms-smoke-attachment`, one character too
     * long. SQLite stores it and MySQL in strict mode refuses the insert, so
     * the whole section died on a real host at the moment the refusal above it
     * did not fire, which is a fixture that can only be exercised where it
     * cannot fail.
     */
    public const KEY = 'vidyalms-smoke-attach';

    /** @var list<int> */
    public array $reached = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Email attachment (smoke)';
    }

    public function deliver(ReportRun $run, DeliveryContext $context): DeliveryResult
    {
        $this->reached = $context->recipientUserIds;

        return DeliveryResult::delivered('attached');
    }
}
