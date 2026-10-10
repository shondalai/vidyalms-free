<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrgRole;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Provider\Organisation\StandardOrgRoleMapper;
use Shondalai\VidyaLMS\Core\Registry\Context\RoleMappingContext;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\OrgRoleMapperInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\OrgRoleMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\RoleMapping;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * Role translation, and the two rules it exists to enforce.
 */
final class OrgRoleMapperTest extends TestCase
{
    public function testTheSeamIsSeatedAndCarriesTheFreeProvider(): void
    {
        $registries = new Registries();
        CoreOrganisationProviders::seed($registries);

        self::assertSame([StandardOrgRoleMapper::KEY], $registries->orgRoleMappers->keys());
    }

    public function testTheElevenOneRosterWordsAreEitherTranslatedOrRefusedByName(): void
    {
        $expected = [
            'administrator' => 'org_admin',
            'aide' => 'org_member',
            'guardian' => 'org_member',
            'parent' => 'org_member',
            'proctor' => 'org_member',
            'relative' => 'org_member',
            'student' => 'org_member',
            'teacher' => 'org_member',
            'districtAdministrator' => null,
            'siteAdministrator' => null,
            'systemAdministrator' => null,
        ];

        foreach ($expected as $token => $role) {
            $mapping = $this->translate($token, RoleMappingContext::VOCABULARY_ONEROSTER);

            if ($role === null) {
                self::assertSame(RoleMapping::OUTCOME_REFUSED, $mapping->outcome, $token);
                self::assertStringContainsString('administers the system that produced the file', (string) $mapping->warning);

                continue;
            }

            self::assertTrue($mapping->isMapped(), $token);
            self::assertSame($role, $mapping->requireRoleKey(), $token);
        }
    }

    /**
     * Rule one, in one sentence: an unmapped role is a warned skip and never a
     * default.
     */
    public function testAnUnmappedTokenIsAWarnedSkipAndNeverADefault(): void
    {
        $mapping = $this->translate('house-captain', RoleMappingContext::VOCABULARY_ONEROSTER);

        self::assertSame(RoleMapping::OUTCOME_UNMAPPED, $mapping->outcome);
        self::assertNull($mapping->roleKey);
        self::assertStringContainsString('house-captain', (string) $mapping->warning);
        self::assertStringContainsString('never given a default role', (string) $mapping->warning);
    }

    /**
     * Rule two, checked against the registered role on this site rather than
     * against a list somebody wrote down. A third-party mapper answering a role
     * whose provider names site administration is refused by the registry, not
     * trusted.
     */
    public function testNoMappingMayEverProduceSiteAdministration(): void
    {
        $roles = $this->roles();
        // A role whose provider names site administration. It is an extension
        // of the shipped role rather than a fresh implementation, so the test
        // exercises the same contract a package would and cannot drift from
        // it when the interface grows a method.
        $roles->register(new class ('acme.super', ['people'], 99, true) extends CoreOrgRole {
            public function capabilities(): array
            {
                return [Capability::ADMIN];
            }
        });

        $mappers = new OrgRoleMapperRegistry();
        $mappers->register($this->mapperAnswering('acme.super'));

        $mapping = $mappers->translate(
            'systemAdministrator',
            RoleMappingContext::forOrganisation(1, $roles->keys()),
            $roles,
        );

        self::assertSame(RoleMapping::OUTCOME_REFUSED, $mapping->outcome);
        self::assertStringContainsString('operates under site', (string) $mapping->warning);
        self::assertNull($mapping->roleKey, 'A refusal is not a downgrade.');
    }

    /**
     * A mapper answering a key nothing registers is refused rather than
     * written, so the failure lands on the row that caused it instead of on a
     * console several layers away.
     */
    public function testAMapperAnsweringAnUnregisteredRoleKeyIsRefused(): void
    {
        $roles = $this->roles();
        $mappers = new OrgRoleMapperRegistry();
        $mappers->register($this->mapperAnswering('unit_manager'));

        $mapping = $mappers->translate('anything', RoleMappingContext::forOrganisation(1, $roles->keys()), $roles);

        self::assertSame(RoleMapping::OUTCOME_REFUSED, $mapping->outcome);
        self::assertStringContainsString('no org role provider is registered', (string) $mapping->warning);
    }

    /**
     * LTI simple names are deprecated, so the full URI matches first and the
     * short form matches only as a compatibility fallback, with a warning that
     * says so.
     */
    public function testFullLtiUrisMatchFirstAndSimpleNamesAreAWarnedFallback(): void
    {
        $uri = $this->translate(
            StandardOrgRoleMapper::LTI_STEM . 'Mentor',
            RoleMappingContext::VOCABULARY_LTI,
        );
        self::assertSame('org_reporter', $uri->requireRoleKey());
        self::assertNull($uri->warning, 'A full URI is the current form and warns about nothing.');

        $simple = $this->translate('Mentor', RoleMappingContext::VOCABULARY_LTI);
        self::assertSame('org_reporter', $simple->requireRoleKey());
        self::assertStringContainsString('deprecated in LTI', (string) $simple->warning);
        self::assertStringContainsString(StandardOrgRoleMapper::LTI_STEM, (string) $simple->warning);

        // A URI in the vocabulary's own shape that nobody publishes is unmapped
        // rather than falling through to the short-name table.
        $unknown = $this->translate(StandardOrgRoleMapper::LTI_STEM . 'Officer', RoleMappingContext::VOCABULARY_LTI);
        self::assertSame(RoleMapping::OUTCOME_UNMAPPED, $unknown->outcome);
    }

    /**
     * A OneRoster run reads its own table. "administrator" is an org
     * administrator in OneRoster and an LTI Administrator here, and a run that
     * named its vocabulary must not be widened into another one.
     */
    public function testANamedVocabularyIsNotWidenedIntoAnother(): void
    {
        self::assertSame(
            RoleMapping::OUTCOME_UNMAPPED,
            $this->translate('StudentEnrollment', RoleMappingContext::VOCABULARY_ONEROSTER)->outcome,
            'A Canvas base role is not a OneRoster word.',
        );

        self::assertSame(
            'org_member',
            $this->translate('StudentEnrollment', RoleMappingContext::VOCABULARY_CANVAS)->requireRoleKey(),
        );
    }

    /**
     * A Canvas role_id is private to one installation, so only the built-in
     * ids are translated and a site-defined one is reported rather than
     * guessed.
     */
    public function testOnlyCanvasBuiltInRoleIdsAreTranslated(): void
    {
        self::assertSame('org_member', $this->translate('role_id:3', RoleMappingContext::VOCABULARY_CANVAS)->requireRoleKey());
        self::assertSame('org_admin', $this->translate('role_id:1', RoleMappingContext::VOCABULARY_CANVAS)->requireRoleKey());
        self::assertSame(
            RoleMapping::OUTCOME_UNMAPPED,
            $this->translate('role_id:412', RoleMappingContext::VOCABULARY_CANVAS)->outcome,
        );
    }

    /**
     * A run naming a mapper gets that one and no other, so a district that
     * registered its own vocabulary does not silently fall back to the shipped
     * one for the words its mapper cannot translate.
     */
    public function testARunMayNameOneMapperAndGetsNoFallback(): void
    {
        $roles = $this->roles();
        $mappers = new OrgRoleMapperRegistry();
        $mappers->register(new StandardOrgRoleMapper());

        $context = RoleMappingContext::forOrganisation(1, $roles->keys(), RoleMappingContext::VOCABULARY_ONEROSTER);

        self::assertTrue($mappers->translate('teacher', $context, $roles, StandardOrgRoleMapper::KEY)->isMapped());
        self::assertSame(
            RoleMapping::OUTCOME_UNMAPPED,
            $mappers->translate('teacher', $context, $roles, 'acme.district')->outcome,
            'A named mapper that is not installed translates nothing, and the shipped one does not stand in.',
        );
    }

    public function testAnEmptyTokenTranslatesToNothingRatherThanToAMember(): void
    {
        self::assertSame(RoleMapping::OUTCOME_UNMAPPED, $this->translate('   ', '')->outcome);
    }

    private function translate(string $token, string $vocabulary): RoleMapping
    {
        $roles = $this->roles();
        $mappers = new OrgRoleMapperRegistry();
        $mappers->register(new StandardOrgRoleMapper());

        return $mappers->translate(
            $token,
            RoleMappingContext::forOrganisation(1, $roles->keys(), $vocabulary),
            $roles,
        );
    }

    private function roles(): OrgRoleRegistry
    {
        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        return $roles;
    }

    private function mapperAnswering(string $roleKey): OrgRoleMapperInterface
    {
        return new class ($roleKey) implements OrgRoleMapperInterface {
            public function __construct(private readonly string $roleKey)
            {
            }

            public function key(): string
            {
                return 'acme.always';
            }

            public function label(): string
            {
                return 'Always';
            }

            public function vocabularies(): array
            {
                return [];
            }

            public function map(string $token, RoleMappingContext $context): RoleMapping
            {
                return RoleMapping::to($token, $this->roleKey);
            }
        };
    }
}
