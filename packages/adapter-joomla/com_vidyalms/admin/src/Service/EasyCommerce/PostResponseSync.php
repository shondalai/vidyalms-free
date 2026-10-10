<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

/** A request-local hint, consumed only after Joomla has sent the response. */
final class PostResponseSync
{
    public const LIMIT = 10;
    public const SECONDS = 2.0;

    /** @var array<string, array{type: string, id: int}> */
    private array $objects = [];

    /**
     * @param ?\Closure(): bool $idle
     * @param ?\Closure(int, array, float): mixed $reconcile
     */
    public function __construct(private readonly ?\Closure $idle = null, private readonly ?\Closure $reconcile = null)
    {
    }

    public function queued(string $type, int $id): void
    {
        if (count($this->objects) < self::LIMIT && in_array($type, ['order', 'subscription'], true) && $id > 0) {
            $this->objects[$type . ':' . $id] = ['type' => $type, 'id' => $id];
        }
    }

    public function respond(bool $cli): void
    {
        $objects = array_values($this->objects);
        $this->objects = [];
        if ($cli || $objects === []) {
            return;
        }
        $idle = $this->idle ?? static fn (): bool => self::connectionIdle(Factory::getContainer()->get(DatabaseInterface::class));
        if (!$idle()) {
            return;
        }
        $reconcile = $this->reconcile ?? Integration::reconcile(...);
        $reconcile(self::LIMIT, $objects, self::SECONDS);
    }

    /** Unknown connection state deliberately leaves the work for the scheduler. */
    public static function connectionIdle(DatabaseInterface $db): bool
    {
        $connection = $db->getConnection();
        if ($connection instanceof \PDO) {
            return !$connection->inTransaction()
                && ($connection->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql'
                    || (bool) $connection->getAttribute(\PDO::ATTR_AUTOCOMMIT));
        }
        if ($connection instanceof \mysqli) {
            // in_transaction is a MariaDB session variable, not a MySQL one.
            // PHP's mysqli exposes no equivalent native transaction-state API.
            try {
                if (!str_contains(strtolower($db->getVersion()), 'mariadb')) {
                    // MySQL exposes transaction state through Performance Schema.
                    // Require positive proof of a completed monitored transaction;
                    // missing rows, disabled instrumentation or denied access use cron.
                    // These nontransactional metadata reads cannot commit shop work.
                    $query = $db->createQuery()->select('@@session.autocommit AS autocommit, tx.STATE AS state, tx.END_EVENT_ID AS ended')
                        ->from('performance_schema.threads AS th')
                        ->join('INNER', 'performance_schema.events_transactions_current AS tx ON tx.THREAD_ID = th.THREAD_ID')
                        ->where('th.PROCESSLIST_ID = CONNECTION_ID()')->where("th.INSTRUMENTED = 'YES'")
                        ->where("EXISTS (SELECT 1 FROM performance_schema.setup_instruments WHERE NAME = 'transaction' AND ENABLED = 'YES')")
                        ->where("(SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation', 'thread_instrumentation', 'events_transactions_current') AND ENABLED = 'YES') = 3");
                    $state = $db->setQuery($query)->loadAssoc();
                    return isset($state['autocommit'], $state['state'], $state['ended'])
                        && (int) $state['autocommit'] === 1
                        && in_array($state['state'], ['COMMITTED', 'ROLLED BACK'], true);
                }
                // No table reads and no transaction-control statements. This
                // probe neither starts nor commits a shop transaction.
                $query = $db->createQuery()->select('@@session.in_transaction AS in_transaction, @@session.autocommit AS autocommit');
                $state = $db->setQuery($query)->loadAssoc();
                return isset($state['in_transaction'], $state['autocommit'])
                    && (int) $state['in_transaction'] === 0 && (int) $state['autocommit'] === 1;
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }
}
