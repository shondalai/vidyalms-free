<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\RoleMappingContext;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\OrgRoleMapperInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\RoleMapping;

/**
 * The free role mapper: the three standard vocabularies a roster load actually
 * arrives in.
 *
 * ## What it translates
 *
 * The eleven OneRoster role words, the five LTI membership URIs with their
 * deprecated simple names behind them, and the Canvas base role names plus the
 * `role_id` form. Everything lands on one of the four free org roles, because
 * those are the four a free build has providers for and a mapper answering a
 * key nothing registers is refused by the registry rather than written.
 *
 * ## Full URIs first, simple names as a warned fallback
 *
 * LTI deprecated the simple names in favour of the full
 * `http://purl.imsglobal.org/vocab/lis/v2/membership#...` URIs, so the URI table
 * is consulted first and an exact URI match never reaches the short-name table.
 * A short name that matches maps and warns, which is what a compatibility
 * fallback is: it keeps a customer's older integration working and tells them
 * it is older.
 *
 * ## The three administrator words are refused rather than mapped
 *
 * `districtAdministrator`, `siteAdministrator` and `systemAdministrator` are a
 * foreign system saying somebody administers *it*. They are answered as
 * refusals with the reason spelled out, not as `org_admin`: a district export
 * whose fifty school administrators became fifty administrators of this
 * organisation would be a privilege decision taken by a spreadsheet. An
 * operator who wants that assigns it, one person at a time, through the console
 * that already applies the subset rule.
 *
 * `administrator` on its own is the OneRoster word for an administrative member
 * of one org, which is exactly what `org_admin` is here, so that one maps.
 *
 * ## The Canvas `role_id` form
 *
 * A Canvas `role_id` is a number whose meaning is private to one Canvas
 * installation: role 3 is a different thing on two different sites. This mapper
 * therefore translates only the five *base* role names Canvas ships and the
 * `role_id:{n}` form for the five built-in ids, and refuses nothing and maps
 * nothing for a site-defined id, which comes back unmapped so the operator is
 * told which number nobody translates. A customer whose Canvas has its own
 * roles registers a mapper of their own beside this one; that is the whole
 * reason the seam is a registry.
 */
final class StandardOrgRoleMapper implements OrgRoleMapperInterface
{
    public const KEY = 'oneroster-default';

    /** The org roles a free build registers, and the only keys this mapper answers. */
    public const ROLE_ADMIN = 'org_admin';
    public const ROLE_REPORTER = 'org_reporter';
    public const ROLE_MEMBER = 'org_member';

    /** The LTI membership vocabulary stem the full URIs are built on. */
    public const LTI_STEM = 'http://purl.imsglobal.org/vocab/lis/v2/membership#';

    /**
     * The eleven OneRoster role words, lower-cased for lookup.
     *
     * A value of null means "refused": the word is understood and deliberately
     * not translated, which the registry reports differently from a word nobody
     * understands.
     *
     * @var array<string, string|null>
     */
    private const ONEROSTER = [
        'administrator' => self::ROLE_ADMIN,
        'teacher' => self::ROLE_MEMBER,
        'aide' => self::ROLE_MEMBER,
        'proctor' => self::ROLE_MEMBER,
        'student' => self::ROLE_MEMBER,
        'guardian' => self::ROLE_MEMBER,
        'parent' => self::ROLE_MEMBER,
        'relative' => self::ROLE_MEMBER,
        'districtadministrator' => null,
        'siteadministrator' => null,
        'systemadministrator' => null,
    ];

    /**
     * The five LTI membership roles, by their simple name in lower case. The
     * full URIs are composed from LTI_STEM and matched first.
     *
     * @var array<string, string>
     */
    private const LTI = [
        'administrator' => self::ROLE_ADMIN,
        'contentdeveloper' => self::ROLE_MEMBER,
        'instructor' => self::ROLE_MEMBER,
        'learner' => self::ROLE_MEMBER,
        'mentor' => self::ROLE_REPORTER,
    ];

    /**
     * Canvas's five built-in base roles, by name and by built-in role_id.
     *
     * @var array<string, string>
     */
    private const CANVAS = [
        'accountadminenrollment' => self::ROLE_ADMIN,
        'accountadmin' => self::ROLE_ADMIN,
        'teacherenrollment' => self::ROLE_MEMBER,
        'taenrollment' => self::ROLE_MEMBER,
        'designerenrollment' => self::ROLE_MEMBER,
        'studentenrollment' => self::ROLE_MEMBER,
        'observerenrollment' => self::ROLE_REPORTER,
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.org_role_mapper.standard';
    }

    public function vocabularies(): array
    {
        return [
            RoleMappingContext::VOCABULARY_ONEROSTER,
            RoleMappingContext::VOCABULARY_LTI,
            RoleMappingContext::VOCABULARY_CANVAS,
        ];
    }

    public function map(string $token, RoleMappingContext $context): RoleMapping
    {
        $trimmed = trim($token);

        if ($trimmed === '') {
            return RoleMapping::unmapped($token);
        }

        // Full URIs first, always, whatever vocabulary the run named: a token
        // that is a URI is an LTI role no matter which file carried it, and a
        // OneRoster export that puts one in its role column has said which
        // vocabulary it meant more clearly than its manifest did.
        if (str_starts_with($trimmed, self::LTI_STEM)) {
            $name = strtolower(substr($trimmed, \strlen(self::LTI_STEM)));

            if (isset(self::LTI[$name])) {
                return RoleMapping::to($token, self::LTI[$name]);
            }

            // A sub-role, which the vocabulary writes as
            // "#Instructor#PrimaryInstructor" after the stem. It used to come
            // back unmapped, so a conformant LTI platform sending the more
            // precise form got nothing while the same platform sending the
            // coarse form got a member. The base role is the part before the
            // second separator and is exactly what this build can express, so
            // it maps and warns that the distinction was dropped.
            $separator = strpos($name, '#');
            $base = $separator === false ? '' : substr($name, 0, $separator);

            if ($base !== '' && isset(self::LTI[$base])) {
                return RoleMapping::deprecated($token, self::LTI[$base], sprintf(
                    'The role "%s" is an LTI sub-role. This build has no finer role than "%s", so the'
                        . ' sub-role was matched to its base role and the distinction was not kept.',
                    $token,
                    self::LTI[$base],
                ));
            }

            return RoleMapping::unmapped($token);
        }

        $name = strtolower($trimmed);

        if ($this->reads($context, RoleMappingContext::VOCABULARY_ONEROSTER)) {
            if (\array_key_exists($name, self::ONEROSTER)) {
                $role = self::ONEROSTER[$name];

                return $role === null ? $this->refuseAdministrator($token) : RoleMapping::to($token, $role);
            }
        }

        if ($this->reads($context, RoleMappingContext::VOCABULARY_CANVAS)) {
            $canvas = $this->canvas($name);

            if ($canvas !== null) {
                return RoleMapping::to($token, $canvas);
            }
        }

        // The compatibility fallback, last and warned. It is last because an
        // LTI simple name is a substring of nothing and a superset of two
        // OneRoster words: "administrator" means an org administrator in
        // OneRoster and an LTI Administrator here, and the OneRoster table above
        // is the one a OneRoster run should hit.
        if ($this->reads($context, RoleMappingContext::VOCABULARY_LTI) && isset(self::LTI[$name])) {
            return RoleMapping::deprecated($token, self::LTI[$name], sprintf(
                'The role "%s" was matched as an LTI simple name. Simple names are deprecated in LTI: send the'
                    . ' full membership URI (%s%s) so this keeps working when the short forms go.',
                $token,
                self::LTI_STEM,
                ucfirst($name),
            ));
        }

        return RoleMapping::unmapped($token);
    }

    /**
     * Whether a run naming one vocabulary should be offered another's table.
     *
     * A run that named its vocabulary gets that one; a run that did not gets
     * every table, in the order above. Nothing here widens a named run into a
     * vocabulary it did not ask for, because that is how a Canvas number would
     * start meaning a OneRoster word.
     */
    private function reads(RoleMappingContext $context, string $vocabulary): bool
    {
        return $context->vocabulary === RoleMappingContext::VOCABULARY_UNKNOWN
            || $context->vocabulary === $vocabulary;
    }

    /**
     * The Canvas base role behind a name or a built-in role_id, or null for a
     * role this installation defined for itself.
     */
    private function canvas(string $name): ?string
    {
        if (isset(self::CANVAS[$name])) {
            return self::CANVAS[$name];
        }

        if (preg_match('/^role_id:(\d+)$/', $name, $matches) !== 1) {
            return null;
        }

        // Canvas's built-in role ids, which are the same on every Canvas
        // installation. Anything above them is site defined and is deliberately
        // not guessed at.
        return match ((int) $matches[1]) {
            1, 2 => self::ROLE_ADMIN,
            3, 4, 5 => self::ROLE_MEMBER,
            default => null,
        };
    }

    private function refuseAdministrator(string $token): RoleMapping
    {
        return RoleMapping::refused($token, sprintf(
            'The role "%s" says this person administers the system that produced the file, not this'
                . ' organisation. No mapping may ever produce site administration, so the row was skipped'
                . ' rather than translated. Assign an organisation administrator in the console, where the'
                . ' rule that a caller may only assign a role that is a subset of their own applies.',
            $token,
        ));
    }
}
