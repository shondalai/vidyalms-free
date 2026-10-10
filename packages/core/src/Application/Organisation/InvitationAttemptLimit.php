<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/** Durable CAS counters. Call before the mutation transaction, including failed attempts. */
final class InvitationAttemptLimit
{
    public function __construct(private readonly DatabasePort $database, private readonly ClockPort $clock)
    {
    }

    public function consume(string $identity, int $limit, int $seconds = 600): void
    {
        $now = $this->clock->now();
        $window = intdiv($now->getTimestamp(), $seconds);
        $bucket = hash('sha256', $identity . ':' . $window);
        $expires = $now->setTimestamp(($window + 1) * $seconds)->format('Y-m-d H:i:s');
        for ($retry = 0; $retry < 8; $retry++) {
            $row = $this->database->selectOne(Query::from('org_invitation_limits')->where('bucket_key', '=', $bucket));
            if ($row === null) {
                try {
                    $this->database->insert('org_invitation_limits', [
                        'bucket_key' => $bucket, 'attempts' => 1, 'expires_at' => $expires,
                        'created_at' => $now->format('Y-m-d H:i:s'), 'updated_at' => $now->format('Y-m-d H:i:s'),
                    ]);

                    return;
                } catch (\Throwable $failure) {
                    if (
                        $this->database->selectOne(Query::from('org_invitation_limits')
                        ->where('bucket_key', '=', $bucket)) === null
                    ) {
                        throw $failure;
                    }
                    continue;
                }
            }
            $attempts = (int) $row['attempts'];
            if ($attempts >= $limit) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ApiProblem::tooManyRequests(max(1, ($window + 1) * $seconds - $now->getTimestamp()));
            }
            if (
                $this->database->update(
                    'org_invitation_limits',
                    ['attempts' => $attempts + 1],
                    ['bucket_key' => $bucket, 'attempts' => $attempts]
                ) === 1
            ) {
                return;
            }
        }
        throw ApiProblem::tooManyRequests(1);
    }
}
