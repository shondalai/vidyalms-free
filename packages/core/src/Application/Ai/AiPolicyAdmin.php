<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AiPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Drafting, activating and superseding a data-use policy version.
 *
 * This is the more regulated of the two administration writes and the one that
 * had no endpoint at all: route administration had four and the write that
 * Articles 50(1), 50(5), 6 and 7 all rest on had none.
 *
 * Three rules are enforced here rather than described.
 *
 * `retention_days` is refused below the 180 floor at the write path rather than
 * in the column, so a site owner cannot silently break Article 26(6) by typing
 * 30, and the floor stays readable as a number a person can be shown.
 *
 * Activating supersedes the currently active version inside one transaction and
 * therefore re-prompts every principal, because an acceptance of the old
 * version is not an acceptance of this text.
 *
 * An active or superseded version's body is immutable. Editing text somebody
 * already accepted would make the acceptance a claim about a document that no
 * longer exists, which is exactly the failure the version column was added to
 * close.
 *
 * **Activation refuses a version that has not said something for every one of
 * the eight parts.** {@see AiDataUsePolicyContent} publishes the four baseline
 * parts and the four this design adds, and it published a validator that no
 * write path called: a site could activate a one-character body and then
 * record every person's acceptance of it, which is a consent record about a
 * document that discloses nothing. A DRAFT may be incomplete, because being
 * incomplete is what a draft is for; activating one may not be.
 *
 * The body is therefore the eight-key document rather than prose, and the two
 * shapes are not both accepted. The column stays text and holds the document
 * encoded, which is why `body_hash` still hashes exactly the stored bytes.
 */
final class AiPolicyAdmin
{
    /** Article 26(6): deployer logs for at least six months. */
    public const RETENTION_FLOOR_DAYS = 180;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    /** @var list<string> */
    private const FIELDS = [
        'title',
        'body',
        'locale',
        'redaction_level',
        'retention_days',
        'training_reuse',
        'enabled_tasks',
        'effective_from',
        'state',
    ];

    public function __construct(
        private readonly SqlAiReadModel $reads,
        private readonly AiAccess $access,
        private readonly Registries $registries,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(Principal $principal, ?string $state, int $page, int $perPage): array
    {
        $this->access->requireManage($principal);

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);
        $result = $this->reads->policyPage($state, $perPage, ($page - 1) * $perPage);

        $items = [];

        foreach ($result['items'] as $row) {
            $items[] = AiPresenter::policy($row, $this->reads->acceptanceCount((int) $row['version']));
        }

        return AiPresenter::page($items, $result['total'], $page, $perPage);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function create(Principal $principal, array $body): array
    {
        $this->access->requireManage($principal);
        $this->refuseUnknownFields($body);

        $title = $this->requiredString($body, 'title');
        $text = $this->policyBody($body['body'] ?? null);
        $retention = $this->retention($body['retention_days'] ?? null);
        $redaction = $this->redaction($body['redaction_level'] ?? null);
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $enabledTasks = $this->enabledTasks($body['enabled_tasks'] ?? null);

        $version = $this->nextVersion();

        $this->database->insert(SqlAiReadModel::POLICIES, [
            'version' => $version,
            'title' => $title,
            'body' => $text,
            'locale' => \is_string($body['locale'] ?? null) && $body['locale'] !== '' ? $body['locale'] : 'en-GB',
            'redaction_level' => $redaction,
            'retention_days' => $retention,
            'training_reuse' => $this->trainingReuse($body['training_reuse'] ?? null),
            'enabled_tasks' => $enabledTasks === null
                ? null
                : json_encode(StoredShape::wrap(['tasks' => $enabledTasks], 1), \JSON_THROW_ON_ERROR),
            'enabled_tasks_version' => 1,
            'body_hash' => hash('sha256', $text),
            'effective_from' => \is_string($body['effective_from'] ?? null) && $body['effective_from'] !== ''
                ? $body['effective_from']
                : $now,
            // Drafted, never active. Activation supersedes the previous
            // version and re-prompts everybody, which is an act rather than a
            // side effect of writing text.
            'state' => 'draft',
            'created_by' => $principal->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->get($principal, $version);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(Principal $principal, int $version, array $body): array
    {
        $this->access->requireManage($principal);
        $this->refuseUnknownFields($body);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->policy($version) ?? throw EntityNotFoundException::for('AiPolicy', $version);
        $state = (string) ($row['state'] ?? 'draft');
        $activating = ($body['state'] ?? null) === 'active';
        $textFields = [
            'title',
            'body',
            'locale',
            'redaction_level',
            'retention_days',
            'training_reuse',
            'enabled_tasks',
            'effective_from',
        ];
        $editsText = array_intersect(array_keys($body), $textFields) !== [];

        if ($state !== 'draft' && $editsText) {
            throw ConflictException::with(
                'An active or superseded policy version is immutable, because somebody has already accepted this text.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['reason' => 'ai.policy.immutable', 'state' => $state],
            );
        }

        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $values = ['updated_at' => $now];

        if (\array_key_exists('title', $body)) {
            $values['title'] = $this->requiredString($body, 'title');
        }

        if (\array_key_exists('body', $body)) {
            $values['body'] = $this->policyBody($body['body']);
        }

        if (\array_key_exists('body', $values)) {
            $values['body_hash'] = hash('sha256', (string) $values['body']);
        }

        if (\array_key_exists('locale', $body)) {
            $values['locale'] = \is_string($body['locale']) && $body['locale'] !== '' ? $body['locale'] : 'en-GB';
        }

        if (\array_key_exists('redaction_level', $body)) {
            $values['redaction_level'] = $this->redaction($body['redaction_level']);
        }

        if (\array_key_exists('retention_days', $body)) {
            $values['retention_days'] = $this->retention($body['retention_days']);
        }

        if (\array_key_exists('training_reuse', $body)) {
            $values['training_reuse'] = $this->trainingReuse($body['training_reuse']);
        }

        if (\array_key_exists('enabled_tasks', $body)) {
            $tasks = $this->enabledTasks($body['enabled_tasks']);
            $values['enabled_tasks'] = $tasks === null
                ? null
                : json_encode(StoredShape::wrap(['tasks' => $tasks], 1), \JSON_THROW_ON_ERROR);
            $values['enabled_tasks_version'] = 1;
        }

        if (\array_key_exists('effective_from', $body) && \is_string($body['effective_from']) && $body['effective_from'] !== '') {
            $values['effective_from'] = $body['effective_from'];
        }

        if (\array_key_exists('state', $body) && !\in_array($body['state'], ['draft', 'active'], true)) {
            throw ValidationException::forField(
                'state',
                'only draft and active are settable; superseding is what activating the next version does',
            );
        }

        if (!$activating) {
            $this->database->update(SqlAiReadModel::POLICIES, $values, ['version' => $version]);

            return $this->get($principal, $version);
        }

        if ($state === 'superseded') {
            throw ConflictException::with(
                'A superseded policy version cannot be reactivated, because the text it superseded is what people accepted since.',
                ['reason' => 'ai.policy.superseded'],
            );
        }

        $missing = AiDataUsePolicyContent::missingParts(
            self::partsOf(\is_string($values['body'] ?? null) ? $values['body'] : (string) ($row['body'] ?? '')),
        );

        if ($missing !== []) {
            throw ConflictException::with(
                'This policy version has not said anything for ' . \count($missing) . ' of its parts, so '
                . 'activating it would record every person\'s acceptance of a document that discloses nothing. '
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                . 'The parts still empty are: ' . implode(', ', $missing) . '.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['reason' => 'ai.policy.incomplete', 'missing_parts' => implode(',', $missing)],
            );
        }

        $values['state'] = 'active';

        $this->database->transactional(function () use ($values, $version, $now): void {
            // Superseding first, so no window exists in which two versions are
            // both active and a run could stamp either one.
            $this->database->update(
                SqlAiReadModel::POLICIES,
                ['state' => 'superseded', 'updated_at' => $now],
                ['state' => 'active'],
            );
            $this->database->update(SqlAiReadModel::POLICIES, $values, ['version' => $version]);
        });

        return $this->get($principal, $version);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $principal, int $version): array
    {
        $this->access->requireManage($principal);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->policy($version) ?? throw EntityNotFoundException::for('AiPolicy', $version);

        return AiPresenter::policy($row, $this->reads->acceptanceCount($version));
    }

    /**
     * The body as it is stored: the eight-part document, encoded.
     *
     * A caller sends an object whose keys are the parts. A string is still
     * accepted and is read as the free-prose form a site had before the parts
     * existed, which cannot be activated: the activation check finds every
     * part missing and says so, naming them, rather than refusing with a shrug.
     */
    private function policyBody(mixed $value): string
    {
        if (\is_array($value)) {
            $parts = [];

            foreach ($value as $key => $text) {
                $key = (string) $key;

                if (!\in_array($key, AiDataUsePolicyContent::parts(), true)) {
                    throw ValidationException::forField(
                        'body',
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        sprintf('"%s" is not one of the eight parts a data-use policy is made of', $key),
                    );
                }

                $parts[$key] = \is_string($text) ? trim($text) : '';
            }

            return json_encode($parts, \JSON_THROW_ON_ERROR);
        }

        if (!\is_string($value) || trim($value) === '') {
            throw ValidationException::forField('body', 'is required');
        }

        return $value;
    }

    /**
     * The parts a stored body carries, or none when it is prose.
     *
     * @return array<string, mixed>
     */
    public static function partsOf(string $body): array
    {
        $decoded = json_decode($body, true);

        /** @var array<string, mixed> $decoded */
        return \is_array($decoded) ? $decoded : [];
    }

    private function nextVersion(): int
    {
        $row = $this->database->selectOne(
            Query::from(SqlAiReadModel::POLICIES)->select(['version'])->orderBy('version', Query::SORT_DESC)->limit(1),
        );

        return $row === null ? 1 : ((int) ($row['version'] ?? 0)) + 1;
    }

    private function retention(mixed $value): int
    {
        if ($value === null) {
            return 400;
        }

        if (!\is_int($value)) {
            throw ValidationException::forField('retention_days', 'must be a whole number of days');
        }

        if ($value < self::RETENTION_FLOOR_DAYS) {
            throw ValidationException::forField(
                'retention_days',
                sprintf(
                    'Article 26(6) requires deployer logs for at least six months, so %d days is the floor.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    self::RETENTION_FLOOR_DAYS,
                ),
            );
        }

        return $value;
    }

    private function redaction(mixed $value): string
    {
        if ($value === null) {
            return 'strict';
        }

        if (!\is_string($value) || $value === '') {
            throw ValidationException::forField('redaction_level', 'names the redactor this policy runs under');
        }

        if (!$this->registries->aiRedactors->has($value)) {
            throw ValidationException::forField(
                'redaction_level',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('no redactor answers "%s" on this site, and an unresolved redactor refuses every run', $value),
            );
        }

        return $value;
    }

    private function trainingReuse(mixed $value): string
    {
        if ($value === null) {
            return 'declined';
        }

        if (!\in_array($value, ['declined', 'permitted', 'not_declared'], true)) {
            throw ValidationException::forField('training_reuse', 'must be declined, permitted or not_declared');
        }

        return $value;
    }

    /**
     * @return list<string>|null
     */
    private function enabledTasks(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!\is_array($value)) {
            throw ValidationException::forField('enabled_tasks', 'must be a list of task keys');
        }

        $keys = [];

        foreach ($value as $key) {
            if (!\is_string($key) || $key === '') {
                throw ValidationException::forField('enabled_tasks', 'must be a list of task keys');
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function refuseUnknownFields(array $body): void
    {
        foreach (array_keys($body) as $field) {
            if (!\in_array((string) $field, self::FIELDS, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField((string) $field, 'is not a field of a data-use policy');
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requiredString(array $body, string $field): string
    {
        $value = $body[$field] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'is required');
        }

        return $value;
    }

    public static function defaultPerPage(): int
    {
        return self::DEFAULT_PER_PAGE;
    }
}
