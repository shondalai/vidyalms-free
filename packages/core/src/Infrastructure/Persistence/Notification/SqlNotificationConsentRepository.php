<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Append-only consent evidence.
 *
 * GDPR Article 7(1) puts the burden of proof on the controller, so
 * withdrawals are appended and rows are never deleted: a deleted row is
 * indistinguishable both from never having consented and from never having
 * objected, and the second matters because an Article 21 objection is
 * permanent.
 *
 * The lawful basis is a real column rather than prose in a document, because
 * a column is what an audit can query, and the position that the soft opt-in
 * never covers bought-in, rented or broker-supplied lists is only enforceable
 * if the basis is stored per row.
 */
final class SqlNotificationConsentRepository
{
    public const STATE_GRANTED = 'granted';
    public const STATE_WITHDRAWN = 'withdrawn';
    public const STATE_OBJECTED = 'objected';

    public const BASIS_CONSENT = 'consent';
    public const BASIS_SOFT_OPT_IN = 'soft_opt_in';
    public const BASIS_CONTRACT = 'contract';
    public const BASIS_LEGITIMATE_INTERESTS = 'legitimate_interests';

    public const SOURCE_PREFERENCE_CENTRE = 'preference_centre';
    public const SOURCE_UNSUBSCRIBE = 'unsubscribe_link';
    public const SOURCE_ADMIN = 'administrator';
    public const SOURCE_IMPORT = 'import';
    /**
     * An unsubscribe the sending provider reported rather than one pressed on
     * our own page. It is the same act and is recorded as one, with the
     * source naming where it came from so an audit can tell them apart.
     */
    public const SOURCE_DELIVERY_FEEDBACK = 'delivery_feedback';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function bases(): array
    {
        return [self::BASIS_CONSENT, self::BASIS_SOFT_OPT_IN, self::BASIS_CONTRACT, self::BASIS_LEGITIMATE_INTERESTS];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public function append(
        int $userId,
        string $categoryKey,
        string $state,
        string $basis,
        string $source,
        array $evidence = [],
        ?int $actorUserId = null,
        ?string $ip = null,
    ): int {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        return $this->database->insert('notification_consents', [
            'user_id' => $userId,
            'category_key' => $categoryKey,
            'state' => $state,
            'basis' => $basis,
            'source' => $source,
            'evidence' => $evidence === [] ? null : json_encode($evidence, \JSON_THROW_ON_ERROR),
            'evidence_version' => 1,
            'actor_user_id' => $actorUserId,
            'ip' => $ip,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The current state for one category: the latest appended row wins, and
     * an objection is permanent, so it outranks a later grant.
     */
    public function currentState(int $userId, string $categoryKey): ?string
    {
        $objection = $this->database->selectOne(
            Query::from('notification_consents')
                ->select(['id'])
                ->where('user_id', '=', $userId)
                ->where('category_key', '=', $categoryKey)
                ->where('state', '=', self::STATE_OBJECTED)
                ->limit(1),
        );

        if ($objection !== null) {
            return self::STATE_OBJECTED;
        }

        $row = $this->database->selectOne(
            Query::from('notification_consents')
                ->select(['state'])
                ->where('user_id', '=', $userId)
                ->where('category_key', '=', $categoryKey)
                ->orderBy('occurred_at', Query::SORT_DESC)
                ->orderBy('id', Query::SORT_DESC)
                ->limit(1),
        );

        return $row === null ? null : (string) $row['state'];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function page(?int $userId, ?string $categoryKey, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($userId, $categoryKey): Query {
            if ($userId !== null) {
                $query->where('user_id', '=', $userId);
            }

            if ($categoryKey !== null) {
                $query->where('category_key', '=', $categoryKey);
            }

            return $query;
        };

        return [
            'items' => $this->database->selectAll(
                $filter(Query::from('notification_consents'))
                    ->orderBy('id', Query::SORT_DESC)
                    ->limit($limit)
                    ->offset($offset),
            ),
            'total' => $this->database->count($filter(Query::from('notification_consents'))),
        ];
    }
}
