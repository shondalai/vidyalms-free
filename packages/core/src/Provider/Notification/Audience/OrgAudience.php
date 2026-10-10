<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Audience;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;

/**
 * The three rules the free organisation audiences share, in one place.
 *
 * They are static helpers rather than a base class on purpose. An abstract
 * resolver would be a fourth thing a third-party audience author has to read
 * before writing one, and the two markers plus a scope check are the whole
 * contract; extending a class would also make the three resolvers look like
 * variants of one provider, which is the god-provider shape the design
 * refuses everywhere else.
 */
final class OrgAudience
{
    private function __construct()
    {
    }

    /**
     * Whether the run's visibility scope reaches this organisation.
     *
     * A site-wide run reaches everything, and an organisation or unit scope
     * reaches the organisation the OrgScope already named, because the
     * OrgScope is where that scope came from. **Every other scope kind
     * reaches nobody**: a course-scoped or cohort-scoped run asking an
     * organisation audience is asking a question this resolver cannot narrow,
     * and answering the whole administrator roster is the widening the marker
     * promised not to do.
     */
    public static function visibleUnder(AudienceContext $context): bool
    {
        $tenant = $context->tenant();
        $scope = $context->orgScope;
        $visibility = $context->visibilityScope;

        if ($tenant === null) {
            return false;
        }

        if ($visibility === null || $visibility->isSiteWide()) {
            return true;
        }

        if ($visibility->type === VisibilityScope::TYPE_ORG_UNIT) {
            // A unit-scoped run needs the unit reach a bare stamp does not
            // carry, so a stamped run is not narrowed to a unit and answers
            // nobody rather than guessing which unit was meant.
            return $scope !== null && $scope->unitId !== null && $visibility->id === $scope->unitId;
        }

        return $visibility->type === VisibilityScope::TYPE_ORGANISATION
            && $visibility->id === $tenant->organisationId;
    }

    /**
     * Every registered role key that permits one right, sorted.
     *
     * The audiences ask for a right rather than for a key, so a role a
     * package registers is in the audience the moment it claims the right,
     * and the four shipped keys appear in no resolver.
     *
     * The question goes through the registry rather than to the provider's
     * own predicate, so audience membership is decided by the same
     * declaration authorisation is decided by. Asking the provider directly
     * put a role into this audience that the assignability rule treated as
     * rightless, which is two answers to one question.
     *
     * @return list<string>
     */
    public static function rolesPermitting(OrgRoleRegistry $roles, string $action): array
    {
        $keys = [];

        foreach ($roles->all() as $role) {
            if ($roles->permits($role->key(), $action)) {
                $keys[] = $role->key();
            }
        }

        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * The user ids one query names, each yielded once.
     *
     * Deduplication is per person per event and it is here rather than left
     * to the sender, because a person holding two roles in one organisation
     * is two rows and one recipient. The query is consumed a page at a time,
     * so a large roster is never materialised, which is the rule every
     * resolver follows.
     *
     * @return \Generator<int, int>
     */
    public static function distinctUserIds(AudienceContext $context, Query $query, string $column = 'user_id'): \Generator
    {
        $seen = [];
        $page = max(1, $context->batchSize());
        $offset = 0;

        while (true) {
            $rows = $context->database->selectAll((clone $query)->limit($page)->offset($offset));

            foreach ($rows as $row) {
                $userId = (int) ($row[$column] ?? 0);

                if ($userId < 1 || isset($seen[$userId])) {
                    continue;
                }

                $seen[$userId] = true;

                yield $userId;
            }

            if (\count($rows) < $page) {
                return;
            }

            $offset += $page;
        }
    }
}
