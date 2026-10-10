<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class InjectingRuleWinnerDatabase extends ForwardingNotificationDatabase
{
    private bool $injected = false;

    /** @param \Closure(): NotificationRule $inject */
    public function __construct(
        DatabasePort $inner,
        private readonly string $ruleKey,
        private readonly \Closure $inject,
    ) {
        parent::__construct($inner);
    }

    public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
    {
        if (!$this->injected && $table === SqlNotificationRuleRepository::TABLE && ($row['rule_key'] ?? null) === $this->ruleKey) {
            $this->injected = true;
            ($this->inject)();
        }

        parent::upsert($table, $row, $uniqueBy, $updateColumns);
    }
}
