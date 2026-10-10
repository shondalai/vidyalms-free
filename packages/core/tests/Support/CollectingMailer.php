<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Mail\MailerPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;

final class CollectingMailer implements MailerPort
{
    /** @var list<MailMessage> */
    public array $sent = [];

    public ?\RuntimeException $failWith = null;

    public function send(MailMessage $message): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->sent[] = $message;
    }
}
