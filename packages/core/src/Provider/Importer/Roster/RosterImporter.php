<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Roster;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationInvitations;
use Shondalai\VidyaLMS\Core\Application\Organisation\MembershipEventContext;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MembershipRemovalGuard;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipState;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgMembershipRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\PersonRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterFormatException;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterManifest;
use Shondalai\VidyaLMS\Core\Domain\Roster\RosterSource;
use Shondalai\VidyaLMS\Core\Infrastructure\Archive\ZipReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Provider\Roster\OneRosterCsvMapper;
use Shondalai\VidyaLMS\Core\Provider\Roster\OrgMembersCsvMapper;
use Shondalai\VidyaLMS\Core\Registry\Context\RoleMappingContext;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceTypes;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportLoadOrder;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportSourceKey;
use Shondalai\VidyaLMS\Core\Registry\Importer\KnowsItsOrganisation;
use Shondalai\VidyaLMS\Core\Registry\Importer\PreflightsBeforeWriting;
use Shondalai\VidyaLMS\Core\Registry\Importer\RunsForAnOrganisation;
use Shondalai\VidyaLMS\Core\Registry\Importer\StagedImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\OrgRoleMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper\RoleMapping;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterMapperInterface;
use Shondalai\VidyaLMS\Core\Registry\RosterMapper\RosterRow;

/**
 * One importer for every roster format, driven by a registered
 * {@see RosterMapperInterface}.
 *
 * A roster load is an importer and not a second pipeline, so this registers on
 * the Importer registry beside every other format and inherits jobs,
 * heartbeats, live progress, the job log, the dry run and the declared-options
 * screen for nothing. What it adds over the course importers is what a roster
 * needs and a course backup does not: an organisation from the run context, a
 * dry pass that runs automatically, a fixed load order, a registered role
 * translation and the rule that deletion is never inferred from absence.
 *
 * ## The split with the mapper
 *
 * The mapper is bytes in and canonical records out and holds no port. This
 * class holds the ports and writes every record **through the same use cases
 * the console uses**: `ManageOrganisationUnits::save()`,
 * `ManageOrganisationMembers::add()` and `::update()`,
 * `ManageOrganisationInvitations::issue()`. Nothing here writes a table, which
 * is why a row loaded from a file obeys the same rules a row typed by a person
 * does: the role subset rule, the manager validation, the last-owner
 * invariant, the optimistic revision, the access log.
 *
 * ## The two organisation rules
 *
 * **The organisation comes from the run context and never from the file.** It
 * is read off the progress sink, which is where the runner put the job's own
 * `organisation_id`, and the mapper is not given it at all. A member CSV
 * carrying an organisation column is refused by name before its first data row.
 *
 * **`orgs.csv` is the deliberate exception, and it is gated.** It is the file
 * that defines the tree, so a caller who administers one unit may not load it:
 * the check is site scope or an assignment over the whole organisation, made
 * before the first row is read, from the manifest rather than from the data.
 *
 * ## Deletion is never inferred from absence
 *
 * A row missing from a later file causes nothing at all. The only deletion
 * instruction is `status = tobedeleted`, and a bulk file cannot carry one
 * because a bulk file says every row present is current. The diffing sweep
 * exists, is off by default, has its own option name, and refuses itself when
 * the change it is about to make is larger than the declared threshold.
 */
final class RosterImporter extends AbstractImporter implements
    StagedImporterInterface,
    PreflightsBeforeWriting,
    ConfigurableImporterInterface,
    DeclaresSourceTypes,
    RunsForAnOrganisation
{
    /** The option that turns the diffing sweep on. Off by default, and named for what it does. */
    public const OPTION_SWEEP = 'sweep_absent';

    /** The guard on that sweep: a percentage of the roster it refuses to exceed. */
    public const OPTION_THRESHOLD = 'change_threshold';

    /**
     * The two booleans, and why neither of them is declared with a default of
     * true.
     *
     * ImportOption::read() coerces a declared boolean to false when the
     * submitted map does not carry it, because that is exactly what an
     * unticked checkbox posts, and it does so whatever default the declaration
     * carries. A boolean option whose safe state is "on" is therefore
     * unrepresentable through the intake: the operator who ticks nothing gets
     * false. Both options are named so that the absent state is the right one.
     *
     * invite_unknown_addresses off by default means an address with no account
     * is reported and not mailed, which is the safer of the two silences.
     * ignore_tombstones off by default means the tombstone is honoured, which
     * is the design's rule: it is the only deletion instruction the free build
     * has, and an option nobody ticked must not turn it off.
     */
    public const OPTION_INVITE = 'invite_unknown_addresses';
    public const OPTION_IGNORE_TOMBSTONES = 'ignore_tombstones';
    public const OPTION_ROLE_MAP = 'role_map_key';

    /** The default sweep guard, as a percentage of the organisation's roster. */
    public const DEFAULT_THRESHOLD = 10;

    /** How many memberships one sweep reads, which is also how large a roster it will sweep. */
    private const SWEEP_LIMIT = 5000;

    /** The provenance every membership this importer writes is stamped with. */
    public const PROVENANCE = 'roster';

    /** The parsed source of the current run, so the dry pass and the real pass share one parse. */
    private ?RosterSource $parsed = null;

    private string $parsedPath = '';

    /**
     * The membership external ids this pass saw, for the sweep to compare
     * against.
     *
     * It holds `OrgMembershipRecord::$externalId`, which is the value
     * `org_memberships.source_ref` is stamped with, and never the person's own
     * id. For a member CSV the two are the same string; for OneRoster the first
     * is a `roles.csv` sourcedId and the second a `users.csv` sourcedId, so
     * keying this on the person made every stored row look absent and the sweep
     * closed the whole roster it had just written.
     *
     * @var array<string, true>
     */
    private array $seen = [];

    /**
     * The external ids this pass invited rather than added.
     *
     * Their membership row arrives when they accept, so the memberships stage
     * reports them as a clean skip rather than as a referential miss: a person
     * the same run just invited is not a person the file got wrong.
     *
     * @var array<string, true>
     */
    private array $invited = [];

    /** @var array<string, int> Accounts resolved in this pass, including previews. */
    private array $resolvedPeople = [];

    public function __construct(
        private readonly RosterMapperInterface $mapper,
        private readonly StoragePort $storage,
        private readonly ImportMap $map,
        private readonly TranslatorPort $translator,
        private readonly OrganisationAccess $access,
        private readonly OrganisationStore $store,
        private readonly ManageOrganisationMembers $members,
        private readonly ManageOrganisationUnits $units,
        private readonly OrgRoleMapperRegistry $roleMappers,
        private readonly OrgRoleRegistry $roles,
        private readonly UsersPort $users,
        /**
         * Optional, because an address with no host account is an ordinary
         * outcome and a site with no invitation delivery configured still has
         * to be able to load a roster of people who already have accounts. A
         * null here makes invite_unknown_addresses a no-op with a note, never
         * a failure.
         */
        private readonly ?ManageOrganisationInvitations $invitations = null,
        /**
         * The role the format uses when a row names none. The member CSV
         * documents plain membership as its default and OneRoster does not:
         * an empty role in roles.csv is a row that says nothing, and a default
         * there would be this product deciding what a directory meant.
         */
        private readonly ?string $defaultRoleKey = null,
        /** Which foreign vocabulary the format's role tokens are drawn from. */
        private readonly string $vocabulary = RoleMappingContext::VOCABULARY_UNKNOWN,
    ) {
    }

    public function key(): string
    {
        return $this->mapper->key();
    }

    public function label(): string
    {
        return $this->translator->translate($this->mapper->label());
    }

    public function supports(string $sourcePath): bool
    {
        return $this->mapper->supports($sourcePath);
    }

    public function sourceTypes(): array
    {
        return [
            ImportSourceKey::TYPE_ORG_UNIT,
            ImportSourceKey::TYPE_USER,
            ImportSourceKey::TYPE_ORG_MEMBER,
            ImportSourceKey::TYPE_INVITATION,
        ];
    }

    public function importOptions(): array
    {
        $options = [
            UpsertPolicy::option(
                $this->translator->translate('vidyalms.importer.option.upsert'),
                [
                    UpsertPolicy::CREATE_ONLY => $this->translator->translate('vidyalms.importer.option.upsert.create_only'),
                    UpsertPolicy::CREATE_AND_UPDATE => $this->translator->translate(
                        'vidyalms.importer.option.upsert.create_and_update'
                    ),
                    UpsertPolicy::UPDATE_ONLY => $this->translator->translate('vidyalms.importer.option.upsert.update_only'),
                    UpsertPolicy::CREATE_WITH_SUFFIX => $this->translator->translate(
                        'vidyalms.importer.option.upsert.create_with_suffix'
                    ),
                ],
                $this->translator->translate('vidyalms.importer.option.upsert.help'),
            ),
            ImportOption::boolean(
                self::OPTION_INVITE,
                $this->translator->translate('vidyalms.roster.option.invite'),
                $this->translator->translate('vidyalms.roster.option.invite.help'),
            ),
            new ImportOption(
                self::OPTION_ROLE_MAP,
                $this->translator->translate('vidyalms.roster.option.role_map'),
                ImportOption::KIND_TEXT,
                $this->translator->translate('vidyalms.roster.option.role_map.help'),
            ),
            ImportOption::boolean(
                self::OPTION_SWEEP,
                $this->translator->translate('vidyalms.roster.option.sweep'),
                $this->translator->translate('vidyalms.roster.option.sweep.help'),
                false,
            ),
            new ImportOption(
                self::OPTION_THRESHOLD,
                $this->translator->translate('vidyalms.roster.option.threshold'),
                ImportOption::KIND_NUMBER,
                $this->translator->translate('vidyalms.roster.option.threshold.help'),
                (string) self::DEFAULT_THRESHOLD,
            ),
        ];

        if ($this->mapper instanceof OneRosterCsvMapper) {
            $options[] = ImportOption::boolean(
                self::OPTION_IGNORE_TOMBSTONES,
                $this->translator->translate('vidyalms.roster.option.tombstones'),
                $this->translator->translate('vidyalms.roster.option.tombstones.help'),
            );
        }

        return $options;
    }

    public function stages(string $sourcePath, array $options): array
    {
        return $this->mapper->stages($this->source($sourcePath));
    }

    public function importWithProgress(
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        // The path the runner does not take. ImportRunner drives a staged
        // importer stage by stage in ImportLoadOrder, so this only runs when
        // somebody calls import() outside a job, and it applies the same order
        // rather than a second one of its own.
        foreach (ImportLoadOrder::sort($this->stages($sourcePath, $options)) as $stage) {
            $this->importStage($stage, $sourcePath, $options, $principal, $progress);
        }
    }

    public function importStage(
        string $stage,
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        $source = $this->source($sourcePath);
        $scope = $this->scope($principal, $progress);

        if ($stage === ImportLoadOrder::sort($this->stages($sourcePath, $options))[0]) {
            $this->openRun($source, $scope, $progress);
        }

        try {
            match ($stage) {
                ImportLoadOrder::STAGE_UNITS => $this->loadUnits($source, $scope, $progress, $options),
                ImportLoadOrder::STAGE_PEOPLE => $this->loadPeople($source, $scope, $progress, $options, $principal),
                ImportLoadOrder::STAGE_MEMBERSHIPS => $this->loadMemberships(
                    $source,
                    $scope,
                    $progress,
                    $options,
                    $principal
                ),
                default => $this->reportUnwritten($source, $stage, $progress),
            };
        } catch (RosterFormatException $refusal) {
            // A file that is wrong is one sentence and not forty thousand row
            // problems. It is rethrown as a plain failure so the job ends
            // failed with the translated reason, which is what an operator who
            // has to edit their file needs to read.
            throw new \RuntimeException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->translator->translate($refusal->translationKey, $refusal->parameters),
                0,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $refusal,
            );
        }

        if ($stage === ImportLoadOrder::STAGE_MEMBERSHIPS) {
            $this->sweep($source, $scope, $progress, $options);
        }
    }

    /**
     * The two things a run says before it reads a row: what it conforms to, and
     * whether the caller may load the file that defines the tree.
     */
    private function openRun(RosterSource $source, OrgScope $scope, ImportProgress $progress): void
    {
        $progress->note(ImportNote::INFO, $this->mapper->conformance()->line());
        $this->reportManifest($source, $progress);

        if (!\in_array(ImportLoadOrder::STAGE_UNITS, $this->mapper->stages($source), true)) {
            return;
        }

        // Checked before the first row is read, and from the manifest rather
        // than from the data: orgs.csv is the file that defines the tree, so a
        // caller whose assignment covers one unit may not load it. Site scope
        // or an assignment over the whole organisation, and nothing else.
        if (!$scope->siteManager && $scope->unitId !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException($this->translator->translate('vidyalms.roster.error.orgs_scope'));
        }
    }

    /**
     * What the manifest itself got wrong, reported rather than absorbed.
     *
     * `RosterManifest::fromRows()` has always accumulated these and nothing has
     * ever read them, so a package declaring `file.roles,full` or
     * `oneroster.version,1.1` completed with zero memberships, zero row problems
     * and no line in the log naming the file that was silently dropped: the bad
     * mode word falls back to absent and the stage disappears. Each problem is a
     * row against `manifest.csv`, so it reaches the problems page and the failed
     * rows download the same way a bad data row does, and the job ends
     * completed_with_problems rather than green.
     *
     * A file the manifest declares present and the package does not carry is the
     * other half of the same silence, and it is reported here for the same
     * reason.
     */
    private function reportManifest(RosterSource $source, ImportProgress $progress): void
    {
        $manifest = $this->mapper->manifest($source);

        foreach ($manifest->problems as $problem) {
            $this->reportRow($progress, RowProblem::inFile(
                RosterManifest::FILE_NAME,
                RowProblem::FIRST_DATA_ROW,
                null,
                RowProblem::CODE_MISSING_REQUIRED,
                $problem,
                '',
                RowProblem::OUTCOME_SKIPPED,
            ));
        }

        foreach ($manifest->declaredFiles() as $file) {
            if ($source->has($file . '.csv')) {
                continue;
            }

            $this->reportRow($progress, RowProblem::inFile(
                RosterManifest::FILE_NAME,
                RowProblem::FIRST_DATA_ROW,
                null,
                RowProblem::CODE_REFERENTIAL_MISS,
                sprintf(
                    'The manifest declares %s%s as present and the package does not carry it. A package that'
                        . ' says one thing and carries another is a package whose row counts nobody can trust.',
                    RosterManifest::FILE_PREFIX,
                    $file,
                ),
                $file,
                RowProblem::OUTCOME_SKIPPED,
            ));
        }
    }

    /**
     * `orgs.csv`, loaded as units inside the organisation the run names.
     *
     * @param array<string, mixed> $options
     */
    private function loadUnits(
        RosterSource $source,
        OrgScope $scope,
        ImportProgress $progress,
        array $options,
    ): void {
        $policy = $this->policy($options);

        foreach ($this->mapper->read($source, ImportLoadOrder::STAGE_UNITS) as $row) {
            $record = $this->accept($row, $progress);

            if (!$record instanceof OrgRecord) {
                continue;
            }

            $key = ImportSourceKey::forOrganisation($scope->organisationId, $record->externalId);
            $existing = $this->map->lookup($this->key(), ImportSourceKey::TYPE_ORG_UNIT, $key);
            $parentId = null;

            if ($record->parentExternalId !== null) {
                $parentId = $this->map->lookup(
                    $this->key(),
                    ImportSourceKey::TYPE_ORG_UNIT,
                    ImportSourceKey::forOrganisation($scope->organisationId, $record->parentExternalId),
                );

                if ($parentId === null) {
                    $this->reject($progress, $row, RowProblem::CODE_REFERENTIAL_MISS, sprintf(
                        'This unit names the parent "%s", and no row in this file set defines it. The row was'
                            . ' skipped and is not retried: with a fixed load order a miss is a fact about the'
                            . ' file rather than about which stage happened to run first.',
                        $record->parentExternalId,
                    ), $record->parentExternalId);

                    continue;
                }
            }

            if (!$this->permits($policy, $existing !== null)) {
                $this->outcome($progress, $row, RowProblem::OUTCOME_UNCHANGED);

                continue;
            }

            if ($progress->isDryRun()) {
                $this->outcome($progress, $row, $existing === null
                    ? RowProblem::OUTCOME_CREATED
                    : RowProblem::OUTCOME_UPDATED);

                continue;
            }

            $result = $this->units->save($this->principalOf($scope), $scope->organisationId, $existing, [
                'expected_revision' => $this->revision($scope),
                'name' => $record->name,
                'parent_id' => $parentId,
            ]);
            $unitId = (int) ($result['item']['id'] ?? $existing ?? 0);

            if ($unitId > 0) {
                $this->map->remember(
                    $this->key(),
                    ImportSourceKey::TYPE_ORG_UNIT,
                    $key,
                    'org_unit',
                    $unitId,
                    $progress->jobId(),
                );
            }

            $progress->counted(ImportResult::TOTAL_ORGS);
            $this->outcome($progress, $row, $existing === null
                ? RowProblem::OUTCOME_CREATED
                : RowProblem::OUTCOME_UPDATED);
        }
    }

    /**
     * `users.csv`, or the person half of a member CSV row.
     *
     * The stage's whole job is to decide, once per person, which host account a
     * later membership row is written against, and to record it in the import
     * map so the membership stage is a lookup rather than a second search over
     * the host's user table.
     *
     * @param array<string, mixed> $options
     */
    private function loadPeople(
        RosterSource $source,
        OrgScope $scope,
        ImportProgress $progress,
        array $options,
        Principal $principal,
    ): void {
        $invite = ($options[self::OPTION_INVITE] ?? false) === true;

        foreach ($this->mapper->read($source, ImportLoadOrder::STAGE_PEOPLE) as $row) {
            $record = $this->accept($row, $progress);

            if (!$record instanceof PersonRecord) {
                continue;
            }

            $key = ImportSourceKey::forOrganisation($scope->organisationId, $record->externalId);
            $account = $record->email === null ? null : $this->users->findByEmail($record->email);

            if ($account !== null) {
                $this->resolvedPeople[$key] = $account->id;
                if (!$progress->isDryRun()) {
                    $this->map->remember(
                        $this->key(),
                        ImportSourceKey::TYPE_USER,
                        $key,
                        'user',
                        $account->id,
                        $progress->jobId(),
                    );
                }

                $this->outcome($progress, $row, RowProblem::OUTCOME_UNCHANGED);

                continue;
            }

            if (!$invite || $record->email === null || $this->invitations === null) {
                $this->reject($progress, $row, RowProblem::CODE_REFERENTIAL_MISS, $record->email === null
                    ? 'This person carries no email address, so there is no host account to write a'
                        . ' membership against and nobody to invite.'
                    : sprintf(
                        'No account on this site uses %s. %s',
                        $record->email,
                        $invite && $this->invitations === null
                            ? 'Invitations are not available on this site, so the row was skipped.'
                            : 'Turn on inviting unknown addresses to send them an invitation instead.',
                    ), (string) $record->email, RowProblem::OUTCOME_SKIPPED);

                continue;
            }

            $this->invite($record, $scope, $progress, $row, $key, $principal);
        }
    }

    /**
     * `roles.csv`, or the membership half of a member CSV row.
     *
     * @param array<string, mixed> $options
     */
    private function loadMemberships(
        RosterSource $source,
        OrgScope $scope,
        ImportProgress $progress,
        array $options,
        Principal $principal,
    ): void {
        $policy = $this->policy($options);
        $tombstones = ($options[self::OPTION_IGNORE_TOMBSTONES] ?? false) !== true;
        $roleMapKey = \is_string($options[self::OPTION_ROLE_MAP] ?? null) ? $options[self::OPTION_ROLE_MAP] : null;
        $definesUnits = \in_array(ImportLoadOrder::STAGE_UNITS, $this->mapper->stages($source), true);

        foreach ($this->mapper->read($source, ImportLoadOrder::STAGE_MEMBERSHIPS) as $row) {
            $record = $this->accept($row, $progress);

            if (!$record instanceof OrgMembershipRecord) {
                continue;
            }

            $this->seen[$record->externalId] = true;
            $personKey = ImportSourceKey::forOrganisation($scope->organisationId, $record->personExternalId);
            $userId = $this->resolvedPeople[$personKey] ?? $this->map->lookup(
                $this->key(),
                ImportSourceKey::TYPE_USER,
                ImportSourceKey::forOrganisation($scope->organisationId, $record->personExternalId),
            );

            if ($userId === null && isset($this->invited[$record->personExternalId])) {
                $this->outcome($progress, $row, RowProblem::OUTCOME_SKIPPED);

                continue;
            }

            if ($userId === null) {
                $this->reject($progress, $row, RowProblem::CODE_REFERENTIAL_MISS, sprintf(
                    'This membership names the person "%s", and no row of this run produced an account for'
                        . ' them. They were either not in the people file or their address matched no'
                        . ' account here.',
                    $record->personExternalId,
                ), $record->personExternalId, RowProblem::OUTCOME_SKIPPED);

                continue;
            }

            $roleKey = $this->roleFor($record, $progress, $row, $scope, $roleMapKey);

            if ($roleKey === null) {
                continue;
            }

            $this->writeMembership(
                $record,
                $scope,
                $progress,
                $row,
                $principal,
                $userId,
                $roleKey,
                $policy,
                $tombstones,
                $definesUnits
            );
        }
    }

    /**
     * The role this row asks for, translated, or null when the row was
     * rejected.
     *
     * Two paths, and the order matters. A key this site already registers is
     * taken as itself, because the member CSV documents `role_key` as one of
     * this product's own roles and translating `org_admin` through a foreign
     * vocabulary would be a category error. Anything else is a foreign token
     * and goes through the registry, where the two hard rules are enforced.
     */
    private function roleFor(
        OrgMembershipRecord $record,
        ImportProgress $progress,
        RosterRow $row,
        OrgScope $scope,
        ?string $roleMapKey,
    ): ?string {
        $token = $record->roleToken;

        if ($token === null || $token === '') {
            if ($this->defaultRoleKey !== null) {
                return $this->defaultRoleKey;
            }

            $this->reject(
                $progress,
                $row,
                RowProblem::CODE_MISSING_REQUIRED,
                'This row names no role. A roster that says nothing about somebody\'s role is not a roster'
                    . ' that says they are an ordinary member, so nothing was written.',
                '',
                RowProblem::OUTCOME_SKIPPED
            );

            return null;
        }

        if ($this->roles->has($token)) {
            // Taken as itself, and still guarded. The shortcut used to return
            // here before any rule ran, so a member CSV naming org_owner wrote
            // an ownership grant that no mapping rule is allowed to name, and
            // the guard the design says is holding was not the guard holding.
            $verified = $this->roleMappers->verifyRoleKey($token, $token, $this->roles);

            if ($verified->outcome === RoleMapping::OUTCOME_REFUSED) {
                $this->reject(
                    $progress,
                    $row,
                    RowProblem::CODE_FORBIDDEN_SCOPE,
                    (string) $verified->warning,
                    $token,
                    RowProblem::OUTCOME_SKIPPED
                );

                return null;
            }

            return $token;
        }

        $mapping = $this->roleMappers->translate(
            $token,
            RoleMappingContext::forScope($scope, $this->roles->keys(), $this->vocabulary),
            $this->roles,
            $roleMapKey,
        );

        if ($mapping->outcome === RoleMapping::OUTCOME_REFUSED) {
            $this->reject(
                $progress,
                $row,
                RowProblem::CODE_FORBIDDEN_SCOPE,
                (string) $mapping->warning,
                $token,
                RowProblem::OUTCOME_SKIPPED
            );

            return null;
        }

        if (!$mapping->isMapped()) {
            $this->reject(
                $progress,
                $row,
                RowProblem::CODE_UNMAPPED_ROLE,
                (string) $mapping->warning,
                $token,
                RowProblem::OUTCOME_SKIPPED
            );

            return null;
        }

        if ($mapping->warning !== null) {
            $this->reportRow($progress, RowProblem::inFile(
                $row->file,
                $row->line,
                'role',
                RowProblem::CODE_UNMAPPED_ROLE,
                $mapping->warning,
                $token,
                RowProblem::OUTCOME_UPDATED,
            ));
        }

        return $mapping->requireRoleKey();
    }

    private function writeMembership(
        OrgMembershipRecord $record,
        OrgScope $scope,
        ImportProgress $progress,
        RosterRow $row,
        Principal $principal,
        int $userId,
        string $roleKey,
        string $policy,
        bool $tombstones,
        bool $definesUnits = false,
    ): void {
        $key = ImportSourceKey::forOrganisation($scope->organisationId, $record->externalId);
        $existing = $this->map->lookup($this->key(), ImportSourceKey::TYPE_ORG_MEMBER, $key)
            ?? $this->store->activeMemberId($scope, $userId);

        // The one guard, asked rather than re-implemented. The roster half
        // used to decide removal from an inline isTombstone() test while
        // MembershipRemovalGuard::permitsFromRoster, which the design names as
        // the single answer for both halves of this area, had no caller
        // anywhere.
        $removes = MembershipRemovalGuard::rule()->permitsFromRoster($record->status);

        if ($removes && !$tombstones) {
            $this->outcome($progress, $row, RowProblem::OUTCOME_UNCHANGED);

            return;
        }

        if ($removes && $existing === null) {
            $this->outcome($progress, $row, RowProblem::OUTCOME_UNCHANGED);

            return;
        }

        if (!$this->permits($policy, $existing !== null)) {
            $this->outcome($progress, $row, RowProblem::OUTCOME_UNCHANGED);

            return;
        }

        if ($progress->isDryRun()) {
            $this->outcome($progress, $row, $existing === null
                ? RowProblem::OUTCOME_CREATED
                : RowProblem::OUTCOME_UPDATED);

            return;
        }

        $unitId = $this->unitFor($record, $scope, $progress, $row, $definesUnits);

        if ($unitId === false) {
            return;
        }

        $attributes = $this->attributes($record, $scope, $progress, $row);

        if ($attributes === null) {
            return;
        }

        // The provenance travels as a context object rather than as a body
        // key, so the membership row records that a roster wrote it, on which
        // foreign key, through the same use case a person uses. Nothing here
        // touches org_memberships directly, which is the importer contract's
        // own rule: an importer that writes a table directly is a bug, no
        // matter how much faster it would be.
        $provenance = MembershipEventContext::roster(
            $record->externalId,
            $unitId,
            $roleKey,
            $record->modifiedAt === null ? null : $this->instant($record->modifiedAt),
        );

        try {
            if ($existing === null) {
                $result = $this->members->add($principal, $scope->organisationId, [
                    'expected_revision' => $this->revision($scope),
                    'user_id' => $userId,
                    'role_key' => $roleKey,
                    'org_unit_id' => $unitId,
                ] + $attributes, $provenance);
                $membershipId = (int) ($result['item']['id'] ?? 0);
            } else {
                $result = $this->members->update($principal, $scope->organisationId, $existing, [
                    'expected_revision' => $this->revision($scope),
                    'role_key' => $roleKey,
                    'state' => $record->membershipState(),
                    'org_unit_id' => $unitId,
                ] + $attributes, $provenance);
                $membershipId = $existing;
            }
        } catch (\Throwable $failure) {
            $this->reportRow($progress, RowProblem::fromFailure(
                $row->file,
                $row->line,
                null,
                RowProblem::CODE_FORBIDDEN_SCOPE,
                $failure,
                $record->externalId,
                RowProblem::OUTCOME_FAILED,
            ));

            return;
        }

        if ($membershipId > 0) {
            $this->map->remember(
                $this->key(),
                ImportSourceKey::TYPE_ORG_MEMBER,
                $key,
                'org_membership',
                $membershipId,
                $progress->jobId(),
            );
        }

        $progress->counted(ImportResult::TOTAL_ORG_MEMBERS);
        $this->outcome($progress, $row, $existing === null
            ? RowProblem::OUTCOME_CREATED
            : RowProblem::OUTCOME_UPDATED);
    }

    /**
     * The unit a membership row names, or false when the row was rejected.
     *
     * A `unit_path` is resolved against this organisation's own tree and never
     * against a digest alone, so a path that exists in another organisation is
     * a referential miss here rather than a placement there.
     *
     * The second form is the one a OneRoster package uses. `roles.csv` names
     * the unit as an `orgSourcedId`, which is the key `orgs.csv` was remembered
     * under, so it is resolved through the import map rather than through a
     * path nothing in the package carries. Without it every imported membership
     * landed with a null unit: both units were created, nobody was in either,
     * and a unit-scoped org_admin saw none of the roster the run had just
     * loaded.
     *
     * A named org that resolves to nothing is a referential miss only when this
     * package declares an orgs stage at all. A package of people and roles with
     * no `orgs.csv` is making no claim about the tree, so its rows keep landing
     * at the organisation's own level exactly as they did.
     *
     * @return int|null|false
     */
    private function unitFor(
        OrgMembershipRecord $record,
        OrgScope $scope,
        ImportProgress $progress,
        RosterRow $row,
        bool $definesUnits = false,
    ): int|null|false {
        if ($record->unitPath === null) {
            return $this->unitFromOrgKey($record, $scope, $progress, $row, $definesUnits);
        }

        $unit = $this->store->database->selectOne(
            OrgScopedReader::from($scope, 'org_units')->where('path', '=', $record->unitPath)
        );

        if ($unit === null) {
            $this->reject($progress, $row, RowProblem::CODE_REFERENTIAL_MISS, sprintf(
                'No unit of this organisation has the path "%s".',
                $record->unitPath,
            ), $record->unitPath, RowProblem::OUTCOME_SKIPPED);

            return false;
        }

        return (int) $unit['id'];
    }

    /**
     * The unit a membership row names by the source's own org key.
     *
     * @return int|null|false
     */
    private function unitFromOrgKey(
        OrgMembershipRecord $record,
        OrgScope $scope,
        ImportProgress $progress,
        RosterRow $row,
        bool $definesUnits,
    ): int|null|false {
        if ($record->orgExternalId === null) {
            return null;
        }

        $unitId = $this->map->lookup(
            $this->key(),
            ImportSourceKey::TYPE_ORG_UNIT,
            ImportSourceKey::forOrganisation($scope->organisationId, $record->orgExternalId),
        );

        if ($unitId !== null) {
            return $unitId;
        }

        if (!$definesUnits) {
            return null;
        }

        $this->reject($progress, $row, RowProblem::CODE_REFERENTIAL_MISS, sprintf(
            'This membership names the organisation "%s", and no row of this file set defines it. The row was'
                . ' skipped rather than written at the top of the tree, because a person placed in no unit is'
                . ' invisible to every unit-scoped administrator of this organisation.',
            $record->orgExternalId,
        ), $record->orgExternalId, RowProblem::OUTCOME_SKIPPED);

        return false;
    }

    /**
     * The directory attributes a membership write carries, or null when the row
     * was rejected.
     *
     * The manager is the one that can fail here, and it fails deliberately
     * rather than being dropped: `manager_user_id` is validated against an
     * active membership in this same organisation by the use case, and the
     * roster resolves the address to an id first so the use case has something
     * to validate. Without both halves a roster could name a manager in another
     * organisation and that person would start receiving this organisation's
     * compliance reminders, carrying its learners' names, through the free
     * line_manager audience.
     *
     * @return array<string, mixed>|null
     */
    private function attributes(
        OrgMembershipRecord $record,
        OrgScope $scope,
        ImportProgress $progress,
        RosterRow $row,
    ): ?array {
        $attributes = [];

        if ($record->beginsAt !== null) {
            $attributes['begins_at'] = $record->beginsAt . ' 00:00:00';
        }

        if ($record->endsAt !== null) {
            $attributes['ends_at'] = $record->endsAt . ' 00:00:00';
        }

        if ($record->managerEmail === null) {
            return $attributes;
        }

        $manager = $this->users->findByEmail($record->managerEmail);

        if ($manager === null) {
            $this->reject($progress, $row, RowProblem::CODE_FORBIDDEN_SCOPE, sprintf(
                'No account on this site uses %s, so it cannot be a manager here. The row was skipped rather'
                    . ' than written without the manager it names.',
                $record->managerEmail,
            ), $record->managerEmail, RowProblem::OUTCOME_SKIPPED);

            return null;
        }

        return $attributes + ['manager_user_id' => $manager->id];
    }

    /**
     * The diffing sweep: off by default, named for what it does, and guarded.
     *
     * Canvas documents the exact failure mode this guard exists for: anything
     * changed outside the previous import is invisible to the diff, so a sweep
     * run against a partial export closes everybody it did not see. The
     * threshold is what stops that being irreversible at roster scale, and the
     * refusal is loud rather than partial: either the whole sweep runs or none
     * of it does.
     *
     * It only ever considers memberships this importer wrote, in bulk mode, in
     * a real pass. A membership somebody added by hand is never swept, because
     * a file that never mentioned them is not evidence about them.
     *
     * @param array<string, mixed> $options
     */
    private function sweep(
        RosterSource $source,
        OrgScope $scope,
        ImportProgress $progress,
        array $options,
    ): void {
        if (($options[self::OPTION_SWEEP] ?? false) !== true || $progress->isDryRun()) {
            return;
        }

        $manifest = $this->mapper->manifest($source);

        if ($manifest->modes !== [] && !$manifest->isBulk('roles')) {
            $progress->note(
                ImportNote::WARNING,
                'The sweep was asked for and did not run: it compares a file against the roster, and only a'
                    . ' bulk file is a statement about the whole roster. A delta file says what changed and'
                    . ' says nothing at all about what it does not mention.'
            );

            return;
        }

        $absent = [];
        $total = 0;

        foreach ($this->rosterMemberships($scope) as $membership) {
            $total++;

            if (!isset($this->seen[(string) ($membership['source_ref'] ?? '')])) {
                $absent[] = $membership;
            }
        }

        $threshold = $this->threshold($options);
        $share = $total === 0 ? 0.0 : (\count($absent) / $total) * 100;

        if ($absent === []) {
            return;
        }

        if ($share > $threshold) {
            $progress->note(ImportNote::WARNING, sprintf(
                'The sweep refused itself. It would have closed %d of %d memberships this importer wrote,'
                    . ' which is %.1f%% and above the %d%% threshold this run declared. A partial export is'
                    . ' the usual cause, and closing that many memberships on the strength of one is not'
                    . ' recoverable by re-running the correct file. Nothing was closed.',
                \count($absent),
                $total,
                $share,
                $threshold,
            ));

            return;
        }

        $this->store->database->transactional(function () use ($absent, $scope): void {
            foreach ($absent as $membership) {
                $this->members->update(
                    $this->principalOf($scope),
                    $scope->organisationId,
                    (int) $membership['id'],
                    ['expected_revision' => $this->revision($scope), 'state' => MembershipState::LEFT],
                );
            }
        });

        $progress->note(ImportNote::INFO, sprintf(
            'The sweep closed %d of %d memberships this importer wrote, which were absent from a bulk file.',
            \count($absent),
            $total,
        ));
    }

    /**
     * Every active membership **this** importer wrote for this organisation.
     *
     * The provenance word is shared: both roster importers stamp
     * `provisioned_by = roster`, so a member-CSV sweep reading it alone would
     * consider the rows a OneRoster package wrote, and the other way round. The
     * import map is what tells them apart, because it is keyed on the importer
     * and it is the same row `writeMembership()` remembers. A membership this
     * importer never remembered is not swept, which is the same rule as "a
     * membership somebody added by hand is never swept", one step earlier.
     *
     * @return list<array<string, mixed>>
     */
    private function rosterMemberships(OrgScope $scope): array
    {
        $query = OrgScopedReader::from($scope, 'org_memberships')
                ->where('state', '=', MembershipState::ACTIVE)
                ->where('provisioned_by', '=', self::PROVENANCE)
                ->where('directory_managed', '=', 0)
                ->orderBy('id')
                ->limit(self::SWEEP_LIMIT);
        $scope->constrainUnit($query, 'org_unit_id');
        $rows = $this->store->database->selectAll($query);

        return array_values(array_filter($rows, function (array $row) use ($scope): bool {
            $member = $this->store->member($scope, (int) $row['id']);
            if ($this->roles->holdsOwnership($member['role_keys'])) {
                return false;
            }
            return $this->map->provenanceOf('org_membership', (int) ($row['id'] ?? 0))?->importer === $this->key();
        }));
    }

    /**
     * A stage this build reads and deliberately does not write, counted and
     * reported so an operator whose export carries four thousand enrolments is
     * told that four thousand enrolments were read.
     */
    private function reportUnwritten(RosterSource $source, string $stage, ImportProgress $progress): void
    {
        $count = 0;

        foreach ($this->mapper->read($source, $stage) as $row) {
            $record = $this->accept($row, $progress);

            if ($record === null) {
                continue;
            }

            $count++;
            $this->outcome($progress, $row, RowProblem::OUTCOME_SKIPPED);
        }

        if ($count > 0) {
            $progress->note(ImportNote::WARNING, sprintf(
                '%d %s row(s) were read and not written. %s',
                $count,
                $stage,
                $this->mapper->conformance()->statement,
            ));
        }
    }

    private function invite(
        PersonRecord $record,
        OrgScope $scope,
        ImportProgress $progress,
        RosterRow $row,
        string $key,
        Principal $principal,
    ): void {
        if ($progress->isDryRun()) {
            $this->invited[$record->externalId] = true;
            $this->outcome($progress, $row, RowProblem::OUTCOME_CREATED);

            return;
        }

        try {
            $result = $this->invitations?->issue($principal, $scope->organisationId, [
                'expected_revision' => $this->revision($scope),
                'email' => (string) $record->email,
                'role_key' => $this->defaultRoleKey ?? 'org_member',
            ]);
        } catch (\Throwable $failure) {
            $this->reportRow($progress, RowProblem::fromFailure(
                $row->file,
                $row->line,
                'email',
                RowProblem::CODE_INVALID_EMAIL,
                $failure,
                (string) $record->email,
                RowProblem::OUTCOME_FAILED,
            ));

            return;
        }

        // An address that already holds an active membership is an unchanged
        // outcome and never a second invitation. The use case answers that
        // itself, which is what stops an operator who re-uploads a corrected
        // file mailing everybody twice.
        if (($result['outcome'] ?? '') === 'already_member') {
            $this->outcome($progress, $row, RowProblem::OUTCOME_UNCHANGED);

            return;
        }

        $invitationId = (int) ($result['item']['id'] ?? 0);

        if ($invitationId > 0) {
            $this->map->remember(
                $this->key(),
                ImportSourceKey::TYPE_INVITATION,
                $key,
                'invitation',
                $invitationId,
                $progress->jobId(),
            );
        }

        $this->invited[$record->externalId] = true;
        $progress->counted(ImportResult::TOTAL_INVITATIONS);
        $this->outcome($progress, $row, RowProblem::OUTCOME_CREATED);
    }

    /** The record of a row, or null when the row carried a problem this reports. */
    private function accept(RosterRow $row, ImportProgress $progress): ?object
    {
        if (!$row->isRejected()) {
            // A warning is a problem the row is still written for. It is
            // reported before the record is handed on, so a row keyed on an
            // address says so in the operator's own problems list rather than
            // only in a format's documentation.
            if ($row->warning !== null) {
                $this->reportRow($progress, $row->warning);
            }

            return $row->requireRecord();
        }

        $problem = $row->problem;

        if ($problem !== null) {
            $this->reportRow($progress, $problem);
        }

        return null;
    }

    private function reject(
        ImportProgress $progress,
        RosterRow $row,
        string $code,
        string $message,
        string $value = '',
        string $outcome = RowProblem::OUTCOME_FAILED,
    ): void {
        $this->reportRow($progress, RowProblem::inFile(
            $row->file,
            $row->line,
            null,
            $code,
            $message,
            $value,
            $outcome,
        ));
    }

    private function outcome(ImportProgress $progress, RosterRow $row, string $outcome): void
    {
        $this->reportOutcome($progress, $row->file, $outcome);
    }

    /** Whether the run's upsert policy allows writing a row that does or does not already exist. */
    private function permits(string $policy, bool $exists): bool
    {
        return match ($policy) {
            UpsertPolicy::CREATE_ONLY => !$exists,
            UpsertPolicy::UPDATE_ONLY => $exists,
            default => true,
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function policy(array $options): string
    {
        $policy = $options[UpsertPolicy::OPTION_KEY] ?? UpsertPolicy::DEFAULT;

        return \is_string($policy) && \in_array($policy, UpsertPolicy::POLICIES, true)
            ? $policy
            : UpsertPolicy::DEFAULT;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function threshold(array $options): int
    {
        $declared = $options[self::OPTION_THRESHOLD] ?? null;

        if (\is_int($declared) || (\is_string($declared) && $declared !== '' && ctype_digit($declared))) {
            return max(0, min(100, (int) $declared));
        }

        return self::DEFAULT_THRESHOLD;
    }

    /**
     * A OneRoster dateLastModified in the storage instant this product keeps.
     *
     * It is the first of the two clocks: a delta row modified last Tuesday
     * happened last Tuesday and is recorded today. A value this cannot read
     * is dropped rather than guessed at, which leaves occurred_at equal to
     * recorded_at, which is the honest answer for a row whose own timestamp
     * nobody can parse.
     */
    private function instant(string $modifiedAt): ?string
    {
        try {
            return (new \DateTimeImmutable($modifiedAt, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /** The organisation's current revision, read for each write the roster makes. */
    private function revision(OrgScope $scope): int
    {
        return (int) $this->store->organisation($scope)['revision'];
    }

    private function principalOf(OrgScope $scope): Principal
    {
        return Principal::user($scope->actorId);
    }

    /**
     * The run's own organisation, minted from the caller and the job.
     *
     * The organisation is read off the progress sink, which is where the runner
     * put the job's own organisation_id, and never out of the options map or the
     * file. The right is "people", the same right that adds one member by hand.
     */
    private function scope(Principal $principal, ImportProgress $progress): OrgScope
    {
        $organisationId = $progress instanceof KnowsItsOrganisation ? $progress->organisationId() : null;

        if ($organisationId === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException($this->translator->translate('vidyalms.roster.error.no_organisation'));
        }

        return $this->access->require($principal, $organisationId, 'people');
    }

    /**
     * The parsed source, read once per run and shared by the dry pass and the
     * real pass.
     *
     * "Preview and run share one parse" is what makes the automatic dry pass
     * nearly free, and it is the importer's to implement rather than the
     * runner's: an importer that cannot share one should not declare
     * PreflightsBeforeWriting and pay for two.
     */
    private function source(string $sourcePath): RosterSource
    {
        if ($this->parsed !== null && $this->parsedPath === $sourcePath) {
            return $this->parsed;
        }

        $bytes = $this->storage->readPrivate($sourcePath);
        $this->parsedPath = $sourcePath;
        $this->seen = [];
        $this->invited = [];
        $this->resolvedPeople = [];
        $this->parsed = str_ends_with(strtolower($sourcePath), '.zip')
            ? RosterSource::ofPackage(ZipReader::entries($bytes))
            : RosterSource::ofFile($this->fileNameOf($sourcePath), $bytes);

        return $this->parsed;
    }

    private function fileNameOf(string $sourcePath): string
    {
        $name = substr((string) strrchr('/' . str_replace('\\', '/', $sourcePath), '/'), 1);

        return $name === '' ? OrgMembersCsvMapper::FILE : $name;
    }
}
