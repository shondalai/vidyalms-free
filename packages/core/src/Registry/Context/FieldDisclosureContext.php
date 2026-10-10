<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;

/**
 * One question put to a field disclosure policy: may this reader see this
 * field, for this purpose, inside this organisation.
 *
 * The design's triple is (org role, field, purpose) and all three are here,
 * with the organisation carried as the resolved {@see OrgScope} rather than as
 * an id, for the reason {@see ReportContext::$orgScope} states: a policy handed
 * only "organisation 7" cannot tell a site manager's run from an organisation
 * reporter's, and the two are legitimately allowed different columns.
 *
 * A policy provider never issues a query. It is handed what the caller has
 * already established, exactly as {@see OrgJoinContext} is, which is what stops
 * a third-party policy reading an organisation-scoped table with no predicate.
 *
 * `purpose` is a free string naming why the field is being read, defaulting to
 * {@see PURPOSE_COMPLETION_EVIDENCE}, which is the only purpose the free build
 * has: proving that somebody completed something. A Business policy that stores
 * a necessity justification stores it against this value.
 */
final class FieldDisclosureContext
{
    /** The free build's one purpose: showing that a person completed a thing. */
    public const PURPOSE_COMPLETION_EVIDENCE = 'completion_evidence';

    private function __construct(
        public readonly OrgScope $scope,
        public readonly string $field,
        public readonly string $purpose,
        /**
         * The reading role keys, sorted, as the scope resolved them. A policy
         * that widens for one role reads this and nothing else, so a role a
         * caller does not hold cannot be supplied as a parameter.
         *
         * @var list<string>
         */
        public readonly array $roles,
    ) {
        if ($field === '') {
            throw new \InvalidArgumentException('A disclosure question names a field.');
        }
    }

    public static function of(
        OrgScope $scope,
        string $field,
        string $purpose = self::PURPOSE_COMPLETION_EVIDENCE,
    ): self {
        return new self(
            $scope,
            $field,
            $purpose === '' ? self::PURPOSE_COMPLETION_EVIDENCE : $purpose,
            $scope->roles,
        );
    }
}
