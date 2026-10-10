<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Application\Platform\SendTransactionalMail;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The subscriber and the queue behind it, so a test reads the rows one way.
 */
final class TransactionalMailHarness
{
    public function __construct(
        public readonly SendTransactionalMail $listener,
        private readonly SqlNotificationQueueRepository $queue,
        private readonly TestPorts $ports,
    ) {
    }

    /**
     * @return list<QueuedNotification>
     */
    public function rows(): array
    {
        $rows = [];

        foreach (
            $this->ports->database->selectAll(
                Query::from('notification_queue')->select(['id'])->orderBy('id'),
            ) as $row
        ) {
            $found = $this->queue->find((int) $row['id']);

            if ($found !== null) {
                $rows[] = $found;
            }
        }

        return $rows;
    }
}
