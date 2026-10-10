<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Catalogue\CourseGrant;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Domain\Organisation\ProviderParameters;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgCatalogueReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgCatalogueContext;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderEdition;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The organisation's own selection from what a site owner released to it.
 *
 * Two rules hold this whole class up and neither is negotiable.
 *
 * **Nothing an org administrator writes may decide which courses their
 * organisation reaches.** `org_course_releases` is the reachable set and only a
 * site manager writes it; an entry here is a selection from that set, checked
 * at save through {@see CourseReleasePolicy} and checked again every time it is
 * resolved, because a release can be withdrawn after an entry was written.
 * Without that bound an org admin adds every paid course on the site with
 * `grant_mode = auto_grant`, and the sweep gives their whole organisation the
 * catalogue.
 *
 * **A course reaches an organisation through a provider and never a column.**
 * The entry names a registered {@see OrgCatalogueSourceInterface} and the
 * provider resolves it. The free build registers `course` and `unit-subtree`;
 * Pro adds `programme` and `catalogue-query`, Business adds `seat-pool`, and
 * none of those is a core edit.
 *
 * ## The orphaned source
 *
 * An entry whose `source_key` names no installed provider is the fail-closed
 * case, and the copy deck fixes what it looks like: the organisation's course
 * list came from a source that is not installed on this site, the organisation
 * and its members are intact, and there is no action and no link. So the entry
 * grants nothing, resolves to no courses, and is listed with `resolved` false
 * and a reason key beside it rather than being hidden or throwing. Nothing in
 * a read path may fail because a paid package was uninstalled.
 */
final class ManageOrganisationCatalogue
{
    /** Writing the catalogue is the learning right, the same one a grant needs. */
    public const ACTION = 'learning';

    /** Reading it is the read right, so a reporter sees the list and changes none of it. */
    public const READ_ACTION = 'read';

    /** The ledger's name for this extension point, for the palette's edition column. */
    public const EXTENSION_POINT = 'OrgCatalogueSource';

    /** The language key the console renders when a source is not installed. */
    public const UNRESOLVED_SOURCE = 'org.catalogue.source_not_installed';

    /** The shape version of the stored params envelope. */
    public const PARAMS_SHAPE = 1;

    /** @var list<string> */
    public const GRANT_MODES = [OrgCatalogueContext::MODE_VISIBLE, OrgCatalogueContext::MODE_AUTO_GRANT];

    /** @var list<string> */
    public const SCOPE_TYPES = [OrgCatalogueContext::SCOPE_ORGANISATION, OrgCatalogueContext::SCOPE_UNIT];

    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $organisations,
        private readonly OrganisationLearningStore $store,
        private readonly CourseReleasePolicy $releases,
        private readonly OrgCatalogueSourceRegistry $sources,
        private readonly ClockPort $clock,
        /** Optional, and only ever used to print an edition beside a palette entry. */
        private readonly ?ProviderPackages $packages = null,
    ) {
    }

    /**
     * This organisation's entries, each with the courses it reaches now.
     *
     * @return list<array<string, mixed>>
     */
    public function entries(Principal $actor, int $orgId): array
    {
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        $reader = $this->readerFor($scope);
        $items = [];

        foreach ($this->rows($scope) as $row) {
            $items[] = $this->present($scope, $row, $reader);
        }

        return $items;
    }

    /**
     * The palette: every registered source with the parameters it takes.
     *
     * The console draws its picker from this rather than from a hard-coded
     * list, which is the whole reason the registry exists: a Pro package that
     * registers `programme` appears here on the site that installed it and
     * nowhere else, with no core edit and no feature flag.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(Principal $actor, int $orgId): array
    {
        $this->access->require($actor, $orgId, self::READ_ACTION);
        $items = [];

        foreach ($this->sources->all() as $source) {
            $items[] = [
                'key' => $source->key(),
                'label' => $source->label(),
                'edition' => ProviderEdition::of($this->packages, self::EXTENSION_POINT, $source->key()),
                'params_schema' => $source->paramsSchema(),
                'params_version' => $source->paramsVersion(),
            ];
        }

        return $items;
    }

    /**
     * The shipped catalogue narrowed to what this organisation reaches.
     *
     * The release check runs a second time here, so a course whose release was
     * withdrawn after the entry was written disappears from the list without
     * anybody editing the entry. Two entries reaching one course fold into one
     * row and `auto_grant` wins, because membership already being access
     * cannot be undone by a second entry that only offers the course.
     *
     * @return array{items: list<array<string, mixed>>, unresolved_entries: list<array<string, mixed>>}
     */
    public function courses(Principal $actor, int $orgId): array
    {
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        $reader = $this->readerFor($scope);
        $byCourse = [];
        $unresolved = [];

        foreach ($this->rows($scope) as $row) {
            if ((string) $row['state'] !== 'active' || !$this->inWindow($row)) {
                continue;
            }

            $entryId = (int) $row['id'];

            if (!$this->sources->has((string) $row['source_key'])) {
                $unresolved[] = [
                    'entry_id' => $entryId,
                    'source_key' => (string) $row['source_key'],
                    'reason' => self::UNRESOLVED_SOURCE,
                ];

                continue;
            }

            foreach ($this->resolve($scope, $row, $reader) as $grant) {
                $courseId = $grant->courseId;
                $byCourse[$courseId] ??= ['course_id' => $courseId, 'grant_mode' => $grant->grantMode, 'entry_ids' => []];

                if ($grant->grantMode === OrgCatalogueContext::MODE_AUTO_GRANT) {
                    $byCourse[$courseId]['grant_mode'] = OrgCatalogueContext::MODE_AUTO_GRANT;
                }

                if (!\in_array($entryId, $byCourse[$courseId]['entry_ids'], true)) {
                    $byCourse[$courseId]['entry_ids'][] = $entryId;
                }
            }
        }

        return ['items' => $this->withTitles($byCourse), 'unresolved_entries' => $unresolved];
    }

    /**
     * Adds one entry, after both halves of the rule.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function add(Principal $actor, int $orgId, array $input): array
    {
        OrganisationInput::only($input, [
            'source_key',
            'scope_type',
            'scope_id',
            'course_id',
            'params',
            'grant_mode',
            'applies_to_descendants',
            'starts_at',
            'ends_at',
        ]);
        $sourceKey = OrganisationInput::text($input, 'source_key', 40);
        $grantMode = $this->grantMode($input['grant_mode'] ?? OrgCatalogueContext::MODE_VISIBLE);
        $starts = OrganisationInput::datetime($input['starts_at'] ?? null, 'starts_at');
        $ends = OrganisationInput::datetime($input['ends_at'] ?? null, 'ends_at');

        if ($starts !== null && $ends !== null && $starts >= $ends) {
            throw ValidationException::forField('ends_at', 'The entry must end after it starts.');
        }

        $descendants = $input['applies_to_descendants'] ?? true;

        if (!\is_bool($descendants)) {
            throw ValidationException::forField('applies_to_descendants', 'This is true or false.');
        }

        return $this->store->database->transactional(function () use (
            $actor,
            $orgId,
            $sourceKey,
            $grantMode,
            $starts,
            $ends,
            $descendants,
            $input
        ): array {
            $scope = $this->access->require($actor, $orgId, self::ACTION);

            // A key nothing answers is refused at save rather than stored as a
            // row that grants nothing. The orphaned-source rendering exists for
            // a package that WAS installed and is not any more, which is a
            // different fact from an administrator typing a key that never
            // existed on this site.
            if (!$this->sources->has($sourceKey)) {
                throw ValidationException::forField('source_key', 'No installed source answers that key.');
            }

            $source = $this->source($sourceKey);
            [$scopeType, $scopeId] = $this->entryScope($scope, $input);
            $courseId = isset($input['course_id'])
                ? OrganisationInput::integer($input, 'course_id')
                : null;
            $params = ProviderParameters::validate(
                $source->paramsSchema(),
                $this->paramsFor($source, \is_array($input['params'] ?? null) ? $input['params'] : [], $courseId),
            );
            $this->guardUnitParams($scope, $params);

            // The release check at save, on every course the entry resolves to
            // right now. It is not the only check: resolve() runs it again on
            // every read, because a release withdrawn tomorrow must remove the
            // course from a list written today.
            $courseId ??= \is_int($params['course_id'] ?? null) ? $params['course_id'] : null;

            if ($courseId !== null) {
                $this->requireReleased($scope, $courseId);
            }

            $id = $this->store->insert('org_catalogue_entries', $scope, [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'source_key' => $sourceKey,
                'course_id' => $courseId,
                'params' => json_encode(StoredShape::wrap($params, self::PARAMS_SHAPE), JSON_THROW_ON_ERROR),
                'params_version' => $source->paramsVersion(),
                'grant_mode' => $grantMode,
                'applies_to_descendants' => $descendants ? 1 : 0,
                'state' => 'active',
                'starts_at' => $starts,
                'ends_at' => $ends,
                'created_by' => $scope->actorId,
            ]);
            $row = $this->row($scope, $id);

            foreach ($this->resolve($scope, $row, $this->readerFor($scope)) as $grant) {
                // Every course the source actually emitted, not only the one a
                // column named. A subtree entry reaching a course nobody
                // released is the case this catches, and it is refused rather
                // than stored and quietly filtered on every later read.
                $this->requireReleased($scope, $grant->courseId);
            }

            $this->record($scope, 'catalogue.entry_added', [
                'entry_id' => $id,
                'source_key' => $sourceKey,
                'grant_mode' => $grantMode,
            ]);

            return $this->present($scope, $row, $this->readerFor($scope));
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(Principal $actor, int $orgId, int $entryId, array $input): array
    {
        OrganisationInput::only($input, ['grant_mode', 'applies_to_descendants', 'state', 'starts_at', 'ends_at', 'params']);

        return $this->store->database->transactional(function () use ($actor, $orgId, $entryId, $input): array {
            $scope = $this->access->require($actor, $orgId, self::ACTION);
            $row = $this->row($scope, $entryId);
            $values = [];

            if (\array_key_exists('grant_mode', $input)) {
                $values['grant_mode'] = $this->grantMode($input['grant_mode']);
            }

            if (\array_key_exists('applies_to_descendants', $input)) {
                if (!\is_bool($input['applies_to_descendants'])) {
                    throw ValidationException::forField('applies_to_descendants', 'This is true or false.');
                }

                $values['applies_to_descendants'] = $input['applies_to_descendants'] ? 1 : 0;
            }

            if (\array_key_exists('state', $input)) {
                $state = \is_string($input['state']) ? $input['state'] : '';

                if (!\in_array($state, ['active', 'retired'], true)) {
                    throw ValidationException::forField('state', 'Expected active or retired.');
                }

                $values['state'] = $state;
            }

            foreach (['starts_at', 'ends_at'] as $key) {
                if (\array_key_exists($key, $input)) {
                    $values[$key] = OrganisationInput::datetime($input[$key], $key);
                }
            }

            if (\array_key_exists('params', $input)) {
                if (!$this->sources->has((string) $row['source_key'])) {
                    // An entry whose source is not installed cannot have its
                    // parameters validated, and storing an unvalidated bag on
                    // it would mean the first thing an uninstalled package sees
                    // on reinstall is a row nobody checked.
                    throw new ConflictException('This entry\'s source is not installed, so its parameters cannot be changed.');
                }

                $source = $this->source((string) $row['source_key']);
                $params = ProviderParameters::validate(
                    $source->paramsSchema(),
                    \is_array($input['params']) ? $input['params'] : [],
                );
                $this->guardUnitParams($scope, $params);
                $values['params'] = json_encode(StoredShape::wrap($params, self::PARAMS_SHAPE), JSON_THROW_ON_ERROR);
                $values['params_version'] = $source->paramsVersion();
            }

            if ($values === []) {
                throw ValidationException::forField('grant_mode', 'Nothing was changed.');
            }

            $this->store->update('org_catalogue_entries', $scope, $entryId, $values);
            $updated = $this->row($scope, $entryId);

            if ((string) $updated['state'] === 'active') {
                foreach ($this->resolve($scope, $updated, $this->readerFor($scope)) as $grant) {
                    $this->requireReleased($scope, $grant->courseId);
                }
            }

            $this->record($scope, 'catalogue.entry_updated', ['entry_id' => $entryId] + $values);

            return $this->present($scope, $updated, $this->readerFor($scope));
        });
    }

    /**
     * Retires one entry. Never a delete: the grants it caused name it.
     *
     * @return array<string, mixed>
     */
    public function retire(Principal $actor, int $orgId, int $entryId): array
    {
        return $this->store->database->transactional(function () use ($actor, $orgId, $entryId): array {
            $scope = $this->access->require($actor, $orgId, self::ACTION);
            $row = $this->row($scope, $entryId);

            if ((string) $row['state'] === 'retired') {
                throw new ConflictException('This entry is already retired.');
            }

            $this->store->update('org_catalogue_entries', $scope, $entryId, ['state' => 'retired']);
            $this->record($scope, 'catalogue.entry_retired', ['entry_id' => $entryId]);

            return $this->present($scope, $this->row($scope, $entryId), $this->readerFor($scope));
        });
    }

    /**
     * Every course one entry reaches now, after the release check.
     *
     * A source that throws produces no grants rather than an exception on a
     * read path: an entry whose provider cannot resolve is exactly the
     * unresolved case, and a list screen must not fail because one row's
     * package is unhappy.
     *
     * @param array<string, mixed> $row
     *
     * @return list<CourseGrant>
     */
    public function resolve(OrgScope $scope, array $row, ?OrgCatalogueReader $reader = null): array
    {
        $sourceKey = (string) $row['source_key'];

        if (!$this->sources->has($sourceKey)) {
            return [];
        }

        $reader ??= $this->readerFor($scope);
        $context = $this->contextFor($scope, $row, $reader);

        try {
            $grants = $this->source($sourceKey)->resolve($context);
        } catch (\Throwable) {
            return [];
        }

        $allowed = [];

        foreach ($grants as $grant) {
            if ($this->releases->allows($scope, $grant->courseId)) {
                $allowed[] = $grant;
            }
        }

        return $allowed;
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $row
     */
    private function contextFor(OrgScope $scope, array $row, OrgCatalogueReader $reader): OrgCatalogueContext
    {
        $scopeType = (string) $row['scope_type'];
        $scopeId = (int) $row['scope_id'];
        $descendants = (int) ($row['applies_to_descendants'] ?? 1) === 1;
        $subtree = $scopeType === OrgCatalogueContext::SCOPE_UNIT && $scopeId > 0
            ? ($descendants ? $this->organisations->unitSubtree($scope->organisationId, $scopeId) : [$scopeId])
            : $this->unitIds($scope);

        return OrgCatalogueContext::forEntry(
            $scope->organisationId,
            (int) $row['id'],
            (string) $row['source_key'],
            $scopeType,
            $scopeId,
            (string) $row['grant_mode'],
            $subtree,
            $this->storedParams($row),
            (int) ($row['params_version'] ?? 1),
            $this->clock->now(),
            $reader,
        );
    }

    /**
     * The stored bag, unwrapped from its envelope, with the entry's own column
     * folded in.
     *
     * `course_id` is a column and a parameter at once by design: the column is
     * what the two indexed reads use and the parameter is what the provider
     * reads, and folding one into the other here is what stops them differing.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function storedParams(array $row): array
    {
        $raw = $row['params'] ?? null;
        $params = [];

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (\is_array($decoded) && StoredShape::versionOf($decoded) <= self::PARAMS_SHAPE) {
                // A bag written in a later shape than this build understands is
                // read as empty rather than guessed at, which is the fail-closed
                // direction: an entry nobody here can read grants nothing.
                $params = StoredShape::bodyOf($decoded);
            }
        }

        if ($row['course_id'] !== null) {
            $params['course_id'] = (int) $row['course_id'];
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function paramsFor(OrgCatalogueSourceInterface $source, array $params, ?int $courseId): array
    {
        if ($courseId !== null && !\array_key_exists('course_id', $params)) {
            $schema = $source->paramsSchema();
            $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

            if (isset($properties['course_id'])) {
                $params['course_id'] = $courseId;
            }
        }

        return $params;
    }

    /**
     * A unit named in a source's parameters is this organisation's unit or it
     * is nothing.
     *
     * The refusal is at save as well as at resolution, on the standing rule
     * that a stored reference is resolved when it is written and again when it
     * is read: the write refusal tells an administrator what is wrong, and the
     * read refusal is what holds after a unit is moved or removed.
     *
     * @param array<string, mixed> $params
     */
    private function guardUnitParams(OrgScope $scope, array $params): void
    {
        $unitId = $params['org_unit_id'] ?? null;

        if (!\is_int($unitId)) {
            return;
        }

        if (!\in_array($unitId, $this->unitIds($scope), true)) {
            throw ValidationException::forField('params.org_unit_id', 'That unit is not part of this organisation.');
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{string, int}
     */
    private function entryScope(OrgScope $scope, array $input): array
    {
        $scopeType = $input['scope_type'] ?? OrgCatalogueContext::SCOPE_ORGANISATION;

        if (!\is_string($scopeType) || !\in_array($scopeType, self::SCOPE_TYPES, true)) {
            throw ValidationException::forField('scope_type', 'Expected organisation or unit.');
        }

        if ($scopeType === OrgCatalogueContext::SCOPE_ORGANISATION) {
            if ($scope->unitId !== null) {
                throw new EntityNotFoundException('No organisation-wide catalogue entry in this scope.');
            }
            return [$scopeType, 0];
        }

        $scopeId = OrganisationInput::integer($input, 'scope_id');

        if (!\in_array($scopeId, $this->unitIds($scope), true)) {
            throw ValidationException::forField('scope_id', 'That unit is not part of this organisation.');
        }

        return [$scopeType, $scopeId];
    }

    private function grantMode(mixed $value): string
    {
        if (!\is_string($value) || !\in_array($value, self::GRANT_MODES, true)) {
            throw ValidationException::forField('grant_mode', 'Expected visible or auto_grant.');
        }

        return $value;
    }

    private function requireReleased(OrgScope $scope, int $courseId): void
    {
        if (!$this->releases->allows($scope, $courseId)) {
            // forbidden_scope in the same words the identity surface uses for
            // the same mistake: a reference that names something outside what
            // this organisation reaches.
            throw new ConflictException(sprintf(
                'forbidden_scope: course %d has not been released to this organisation.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $courseId,
            ));
        }
    }

    /**
     * @return list<int>
     */
    private function unitIds(OrgScope $scope): array
    {
        $query = OrgScopedReader::from($scope, 'org_units', 'u')->select(['u.id'])->where('u.state', '=', 'active');
        $scope->constrainUnit($query, 'u.id');
        $rows = $this->store->database->selectAll($query);

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(OrgScope $scope): array
    {
        return $this->store->database->selectAll(
            OrgScopedReader::from($scope, 'org_catalogue_entries')->orderBy('id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(OrgScope $scope, int $entryId): array
    {
        $row = $this->store->database->selectOne(
            OrgScopedReader::from($scope, 'org_catalogue_entries')->where('id', '=', $entryId),
        ) ?? throw new EntityNotFoundException('No catalogue entry here.');
        $this->entryScope($scope, $row);
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function inWindow(array $row): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $starts = $row['starts_at'] ?? null;
        $ends = $row['ends_at'] ?? null;

        return ($starts === null || (string) $starts <= $now) && ($ends === null || (string) $ends > $now);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(OrgScope $scope, array $row, OrgCatalogueReader $reader): array
    {
        $resolved = $this->sources->has((string) $row['source_key']);
        $courseIds = $resolved && (string) $row['state'] === 'active'
            ? array_map(
                static fn (CourseGrant $grant): int => $grant->courseId,
                $this->resolve($scope, $row, $reader),
            )
            : [];

        return [
            'id' => (int) $row['id'],
            'organisation_id' => (int) $row['organisation_id'],
            'scope_type' => (string) $row['scope_type'],
            'scope_id' => (int) $row['scope_id'],
            'source_key' => (string) $row['source_key'],
            'course_id' => $row['course_id'] === null ? null : (int) $row['course_id'],
            'params' => $this->storedParams($row) ?: null,
            'params_version' => (int) ($row['params_version'] ?? 1),
            'grant_mode' => (string) $row['grant_mode'],
            'applies_to_descendants' => (int) ($row['applies_to_descendants'] ?? 1) === 1,
            'state' => (string) $row['state'],
            'starts_at' => $row['starts_at'] === null ? null : (string) $row['starts_at'],
            'ends_at' => $row['ends_at'] === null ? null : (string) $row['ends_at'],
            'resolved' => $resolved,
            'unresolved_reason' => $resolved ? null : self::UNRESOLVED_SOURCE,
            'course_ids' => array_values(array_unique($courseIds)),
        ];
    }

    /**
     * @param array<int, array{course_id: int, grant_mode: string, entry_ids: list<int>}> $byCourse
     *
     * @return list<array<string, mixed>>
     */
    private function withTitles(array $byCourse): array
    {
        if ($byCourse === []) {
            return [];
        }

        $titles = [];

        foreach (
            $this->store->database->selectAll(
                Query::from('courses')->select(['id', 'title'])->whereIn('id', array_keys($byCourse)),
            ) as $course
        ) {
            $titles[(int) $course['id']] = (string) $course['title'];
        }

        $items = [];

        foreach ($byCourse as $courseId => $entry) {
            $items[] = $entry + ['title' => $titles[$courseId] ?? ''];
        }

        return $items;
    }

    private function readerFor(OrgScope $scope): OrgCatalogueReader
    {
        return new OrgCatalogueReader($this->store->database, $this->clock, $scope);
    }

    private function source(string $key): OrgCatalogueSourceInterface
    {
        $source = $this->sources->get($key);
        \assert($source instanceof OrgCatalogueSourceInterface);

        return $source;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(OrgScope $scope, string $key, array $details): void
    {
        $organisation = $this->organisations->organisation($scope);
        $this->organisations->event(
            $scope,
            (int) ($organisation['revision'] ?? 1),
            MembershipEvent::GOVERNANCE_CHANGED,
            null,
            ['change' => $key] + $details,
            MembershipEventContext::manual(),
        );
    }
}
