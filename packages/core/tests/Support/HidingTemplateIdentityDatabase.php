<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class HidingTemplateIdentityDatabase extends ForwardingNotificationDatabase
{
    private bool $hidden = false;

    public function __construct(DatabasePort $inner, private readonly string $templateKey)
    {
        parent::__construct($inner);
    }

    public function selectOne(Query $query): ?array
    {
        if (!$this->hidden && $this->isTargetIdentity($query)) {
            $this->hidden = true;

            return null;
        }

        return parent::selectOne($query);
    }

    private function isTargetIdentity(Query $query): bool
    {
        if ($query->table() !== SqlNotificationTemplateRepository::TABLE) {
            return false;
        }

        foreach ($query->conditions() as $condition) {
            if ($condition['column'] === 'template_key' && $condition['value'] === $this->templateKey) {
                return true;
            }
        }

        return false;
    }
}
