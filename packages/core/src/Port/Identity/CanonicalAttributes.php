<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * The core's own attribute names, which every mapper maps on to.
 *
 * Eighteen words and no protocol among them. A dialect knows that one
 * directory spells the family name one way and another spells it as an object
 * identifier; nothing past the mapper seam knows either, and a rule author
 * writes against this list.
 *
 * It is closed because a mapping rule names an attribute and a stored rule
 * outlives the mapper that suggested it: an open list would let a rule be
 * saved against a word that no mapper ever produces, which is a rule that
 * silently matches nothing for ever.
 */
final class CanonicalAttributes
{
    public const SUBJECT = 'subject';
    public const ISSUER = 'issuer';
    public const EMAIL = 'email';
    public const EMAIL_VERIFIED = 'email_verified';
    public const GIVEN_NAME = 'given_name';
    public const FAMILY_NAME = 'family_name';
    public const DISPLAY_NAME = 'display_name';
    public const PREFERRED_USERNAME = 'preferred_username';
    public const LOCALE = 'locale';
    public const GROUPS = 'groups';
    public const ROLES = 'roles';
    public const DEPARTMENT = 'department';
    public const DIVISION = 'division';
    public const ORGANIZATION = 'organization';
    public const EMPLOYEE_NUMBER = 'employee_number';
    public const COST_CENTRE = 'cost_centre';
    public const MANAGER = 'manager';
    /**
     * The customer's own name for a tenant, and the one word here that NO
     * dialect produces on its own.
     *
     * Every other canonical word is filled by at least one shipped dialect
     * table. This one is not, and it is kept rather than removed because it is
     * the word a directory-side tenant key belongs under when a customer has
     * one: a `tenantRef`, a `costCenterOrg`, a company code, all of them
     * site-specific attribute names the core cannot enumerate and must not
     * guess at. So the only route to it is an operator's own `attribute_map`
     * entry on the connection, which points the word at whatever their
     * directory actually sends, and that route is asserted rather than
     * asserted-in-prose.
     *
     * A rule naming it on a connection with no such mapping matches nothing,
     * which is the same answer any canonical word gives when the assertion does
     * not carry it.
     */
    public const ORG_KEY = 'org_key';

    /** @var list<string> */
    public const NAMES = [
        self::SUBJECT,
        self::ISSUER,
        self::EMAIL,
        self::EMAIL_VERIFIED,
        self::GIVEN_NAME,
        self::FAMILY_NAME,
        self::DISPLAY_NAME,
        self::PREFERRED_USERNAME,
        self::LOCALE,
        self::GROUPS,
        self::ROLES,
        self::DEPARTMENT,
        self::DIVISION,
        self::ORGANIZATION,
        self::EMPLOYEE_NUMBER,
        self::COST_CENTRE,
        self::MANAGER,
        self::ORG_KEY,
    ];

    /**
     * The two attributes whose values are group-shaped, so a rule naming one
     * of them must also name the kind of key it is comparing.
     *
     * @var list<string>
     */
    public const GROUP_SHAPED = [self::GROUPS, self::ROLES];

    private function __construct()
    {
    }

    public static function isKnown(string $name): bool
    {
        return \in_array($name, self::NAMES, true);
    }

    public static function isGroupShaped(string $name): bool
    {
        return \in_array($name, self::GROUP_SHAPED, true);
    }
}
