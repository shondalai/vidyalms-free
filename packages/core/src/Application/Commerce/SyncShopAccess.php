<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Commerce;

use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Access\EnrolLearner;
use Shondalai\VidyaLMS\Core\Application\Access\ExtendEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlement;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Synchronizes learning access with a shop's authoritative order/subscription.
 * Only trusted, in-process CMS bridges call this service after reading the shop.
 * The link stores access correlation only; the shop owns all financial records.
 */
final class SyncShopAccess
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly UsersPort $users,
        private readonly CourseAccess $access,
        private readonly EntitlementRepository $entitlements,
        private readonly GrantEntitlement $grant,
        private readonly ExtendEntitlement $extend,
        private readonly RevokeEntitlement $revoke,
        private readonly EnrolLearner $enrol,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
    ) {
    }

    /** @param list<int> $courses The courses still covered by this shop object. */
    public function execute(
        Principal $actor,
        string $shop,
        string $objectType,
        string $objectId,
        int $userId,
        array $courses,
        bool $active,
        ?string $expiresAt = null,
        string $reason = 'shop_sync',
    ): void {
        $this->access->requireSignedIn($actor);
        if (!\in_array($shop, ['easycommerce', 'woocommerce'], true)) {
            throw ValidationException::forField('shop', 'Unsupported course shop.');
        }
        if (!\in_array($objectType, ['order', 'subscription'], true) || !preg_match('/^[1-9][0-9]{0,63}$/D', $objectId)) {
            throw ValidationException::forField('object_id', 'Expected a shop order or subscription identifier.');
        }
        if (strlen($reason) > 100) {
            throw ValidationException::forField('reason', 'Use at most 100 characters.');
        }
        foreach ($courses as $courseId) {
            if (!\is_int($courseId) || $courseId < 1) {
                throw ValidationException::forField('courses', 'Expected positive course identifiers.');
            }
        }
        $courses = array_values(array_unique($courses));
        sort($courses);
        $expiry = $this->expiry($expiresAt);
        if ($active && ($userId < 1 || !$this->users->exists($userId))) {
            throw ValidationException::forField('user_id', 'Course purchases require a linked CMS account.');
        }
        $active = $active && ($expiry === null || $expiry > $this->clock->now());
        $source = $objectType === 'subscription' ? Entitlement::SOURCE_SUBSCRIPTION : Entitlement::SOURCE_PURCHASE;
        $where = ['shop' => $shop, 'object_type' => $objectType, 'object_id' => $objectId];

        $this->events->afterSuccessful(fn () => $this->database->transactional(function () use (
            $actor,
            $where,
            $shop,
            $objectType,
            $objectId,
            $userId,
            $courses,
            $active,
            $expiresAt,
            $reason,
            $source,
        ): void {
            $query = Query::from('shop_access_links');
            foreach ($where as $field => $value) {
                $query = $query->where($field, '=', $value);
            }
            $link = $this->database->selectOne($query);
            // Privacy erasure retires the shop object permanently. The marker
            // has no learner identity or grant references and survives retries.
            if (($link['last_reason'] ?? null) === 'privacy_erased') {
                return;
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            if ($link === null) {
                try {
                    $id = $this->database->insert('shop_access_links', $where + [
                        'user_id' => max(0, $userId), 'revision' => 0, 'entitlements' => '{}',
                        'last_reason' => $reason, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } catch (UniqueConstraintViolation) {
                    throw new ConflictException('Course access is being synchronized. Retry from the current shop state.');
                }
                $link = ['id' => $id, 'revision' => 0, 'entitlements' => '{}'];
            }
            $revision = (int) $link['revision'] + 1;
            // Hold the row until the access writes commit. A stale concurrent
            // reader cannot overwrite this result; its bridge must retry.
            $claimed = $this->database->update('shop_access_links', ['revision' => $revision], [
                'id' => (int) $link['id'], 'revision' => (int) $link['revision'],
            ]);
            if ($claimed !== 1) {
                throw new ConflictException('Course access changed during synchronization. Retry from the shop.');
            }
            $previous = json_decode((string) $link['entitlements'], true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($previous)) {
                throw new \RuntimeException('Invalid shop access correlation.');
            }
            $grants = [];
            foreach ($previous as $id) {
                $entitlement = $this->entitlements->find((int) $id);
                if ($entitlement !== null) {
                    $this->access->require($actor, $entitlement->courseId, Capability::ENROL_OTHERS);
                    $grants[$entitlement->courseId] = $entitlement;
                }
            }
            foreach ($courses as $courseId) {
                $this->access->require($actor, $courseId, Capability::ENROL_OTHERS);
            }
            foreach ($grants as $courseId => $entitlement) {
                if (!$active || $entitlement->userId !== $userId || !\in_array($courseId, $courses, true)) {
                    $this->revoke->execute($actor, $entitlement->id(), $reason);
                    unset($grants[$courseId]);
                }
            }
            if ($active) {
                foreach ($courses as $courseId) {
                    $current = $grants[$courseId] ?? null;
                    if ($current === null || $current->status === Entitlement::STATUS_REVOKED) {
                        $current = $this->grant->execute(
                            $actor,
                            $userId,
                            $courseId,
                            $source,
                            SourceRef::compose($shop, $objectType, $objectId, $courseId, null, $userId, $revision),
                            null,
                            $expiresAt,
                        );
                    } else {
                        $this->extend->execute($actor, $current->id(), $expiresAt);
                        $this->enrol->execute($userId, $courseId, $current->id());
                    }
                    $grants[$courseId] = $current;
                }
            }
            $ids = [];
            foreach ($grants as $courseId => $entitlement) {
                $ids[$courseId] = $entitlement->id();
            }
            $this->database->update('shop_access_links', [
                'user_id' => max(0, $userId),
                'entitlements' => json_encode((object) $ids, JSON_THROW_ON_ERROR),
                'last_reason' => $reason, 'updated_at' => $now,
            ], ['id' => (int) $link['id']]);
        }));
    }

    private function expiry(?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw ValidationException::forField('expires_at', 'Expected a UTC date and time.');
        }
        return $date;
    }
}
