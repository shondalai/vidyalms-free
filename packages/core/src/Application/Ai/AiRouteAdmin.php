<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AiPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\DeclaresDataHandling;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Route administration: level three of the four enablement levels.
 *
 * A bridge is a class and a route is a row. Per task enablement and ordering
 * live in queryable rows rather than inside one configuration document, because
 * "which routes serve this task on this site, in which order" is an index scan
 * and it is the query the admin screen is made of. The fallback chain is per
 * task rather than per bridge instance, which is what lets a site run a cheap
 * model for a critique and a strong one for outline generation without
 * maintaining two instances or duplicating a credential.
 *
 * Three refusals live here, and each is about the row rather than about the
 * run. A field the operation does not name is refused by name rather than
 * dropped with a 200. A bridge key no registry answers is refused, so a row
 * cannot name a package that is not installed. And `max_cost_per_run` of zero
 * refuses: a route that declares no per-run ceiling may not be enabled, so a
 * provisioning import cannot create an uncapped route, and there is
 * deliberately no value meaning unlimited.
 */
final class AiRouteAdmin
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    /** @var list<string> */
    private const FIELDS = [
        'task_key',
        'bridge_key',
        'model',
        'prompt_ref',
        'params',
        'position',
        'enabled',
        'scope_type',
        'scope_id',
        'max_cost_per_run',
    ];

    /** @var list<string> */
    private const SCOPES = ['site', 'course', 'cohort', 'organisation'];

    public function __construct(
        private readonly SqlAiReadModel $reads,
        private readonly AiAccess $access,
        private readonly Registries $registries,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly TranslatorPort $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(
        Principal $principal,
        ?string $taskKey,
        ?string $bridgeKey,
        ?bool $enabled,
        int $page,
        int $perPage,
    ): array {
        $this->access->requireManage($principal);

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);
        $result = $this->reads->routePage($taskKey, $bridgeKey, $enabled, $perPage, ($page - 1) * $perPage);

        return AiPresenter::page(
            array_map($this->present(...), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
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

        $taskKey = $this->requiredString($body, 'task_key');
        $bridgeKey = $this->requiredString($body, 'bridge_key');
        $this->requireBridge($bridgeKey);

        $ceiling = $body['max_cost_per_run'] ?? null;

        if (!\is_int($ceiling) || $ceiling < 0) {
            throw ValidationException::forField(
                'max_cost_per_run',
                'a route declares its own per-run ceiling, and zero refuses rather than meaning unlimited',
            );
        }

        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $params = \is_array($body['params'] ?? null) ? $body['params'] : null;
        $scopeType = $this->scope($body['scope_type'] ?? null);
        $scopeId = self::scopeId($scopeType, $body['scope_id'] ?? null);

        $id = $this->database->insert(SqlAiReadModel::ROUTES, [
            'task_key' => $taskKey,
            'bridge_key' => $bridgeKey,
            'model' => $this->nullableString($body['model'] ?? null),
            'prompt_ref' => $this->nullableString($body['prompt_ref'] ?? null),
            'params' => $params === null ? null : json_encode(StoredShape::wrap($params, 1), \JSON_THROW_ON_ERROR),
            'params_version' => 1,
            'position' => \is_int($body['position'] ?? null) ? $body['position'] : 0,
            // Created disabled, so a route an import wrote is inert until a
            // person turns it on.
            'enabled' => 0,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'max_cost_per_run' => $ceiling,
            'updated_by' => $principal->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->get($principal, $id);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function update(Principal $principal, int $id, array $body): array
    {
        $this->access->requireManage($principal);
        $this->refuseUnknownFields($body);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->route($id) ?? throw EntityNotFoundException::for('AiRoute', $id);
        $values = ['updated_by' => $principal->userId, 'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT)];

        if (\array_key_exists('task_key', $body)) {
            $values['task_key'] = $this->requiredString($body, 'task_key');
        }

        if (\array_key_exists('bridge_key', $body)) {
            $bridgeKey = $this->requiredString($body, 'bridge_key');
            $this->requireBridge($bridgeKey);
            $values['bridge_key'] = $bridgeKey;
        }

        foreach (['model', 'prompt_ref'] as $field) {
            if (\array_key_exists($field, $body)) {
                $values[$field] = $this->nullableString($body[$field]);
            }
        }

        if (\array_key_exists('params', $body)) {
            $params = $body['params'];
            $values['params'] = \is_array($params)
                ? json_encode(StoredShape::wrap($params, 1), \JSON_THROW_ON_ERROR)
                : null;
        }

        foreach (['position', 'scope_id', 'max_cost_per_run'] as $field) {
            if (!\array_key_exists($field, $body)) {
                continue;
            }

            if (!\is_int($body[$field]) || $body[$field] < 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field, 'must be a whole number of zero or more');
            }

            $values[$field] = $body[$field];
        }

        if (\array_key_exists('scope_type', $body)) {
            $values['scope_type'] = $this->scope($body['scope_type']);
        }

        if (\array_key_exists('enabled', $body)) {
            $enabled = $body['enabled'] === true;
            $ceiling = $values['max_cost_per_run'] ?? (int) ($row['max_cost_per_run'] ?? 0);

            if ($enabled && (int) $ceiling === 0) {
                throw ValidationException::forField(
                    'max_cost_per_run',
                    'a route with no declared per-run ceiling may not be enabled, because a ceiling whose default is off is not a ceiling',
                );
            }

            $values['enabled'] = $enabled ? 1 : 0;
        }

        $values['scope_id'] = self::scopeId(
            (string) ($values['scope_type'] ?? $row['scope_type']),
            $values['scope_id'] ?? (int) ($row['scope_id'] ?? 0),
        );

        $this->database->update(SqlAiReadModel::ROUTES, $values, ['id' => $id]);

        return $this->get($principal, $id);
    }

    private static function scopeId(string $scopeType, mixed $value): int
    {
        if ($scopeType === 'site') {
            return 0;
        }
        if (!\is_int($value) || $value <= 0) {
            throw ValidationException::forField('scope_id', 'Choose the course, cohort or organisation for this route.');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(Principal $principal, int $id): array
    {
        $this->access->requireManage($principal);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $this->reads->route($id) ?? throw EntityNotFoundException::for('AiRoute', $id);

        // The attempts keep the bridge key and the model they really called,
        // so a billing dispute survives the row being removed.
        $this->database->delete(SqlAiReadModel::ROUTES, ['id' => $id]);

        return ['id' => $id, 'deleted' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $principal, int $id): array
    {
        $this->access->requireManage($principal);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->route($id) ?? throw EntityNotFoundException::for('AiRoute', $id);

        return $this->present($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $bridgeKey = (string) ($row['bridge_key'] ?? '');
        $available = $bridgeKey !== '' && $this->registries->modelBridges->has($bridgeKey);
        $bridge = $available ? $this->registries->modelBridges->get($bridgeKey) : null;
        $taskKey = (string) ($row['task_key'] ?? '');
        $task = $this->registries->aiTasks->has($taskKey) ? $this->registries->aiTasks->get($taskKey) : null;

        return AiPresenter::route(
            $row,
            $available,
            $bridge instanceof ModelBridgeInterface ? $this->translator->translate($bridge->label()) : null,
            $task instanceof AiTaskInterface ? $this->translator->translate($task->label()) : null,
            $bridge instanceof DeclaresDataHandling ? $bridge->dataHandling() : null,
        );
    }

    private function requireBridge(string $bridgeKey): void
    {
        if (!$this->registries->modelBridges->has($bridgeKey)) {
            throw ValidationException::forField(
                'bridge_key',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('no bridge answers "%s" on this site, so a route naming it could never be selected', $bridgeKey),
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function refuseUnknownFields(array $body): void
    {
        foreach (array_keys($body) as $field) {
            if (!\in_array((string) $field, self::FIELDS, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField((string) $field, 'is not a field of a route');
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

        return trim($value);
    }

    /**
     * Absent means site, which is the column's own default and the only scope a
     * route needs before a site has courses to narrow to. Only a value that is
     * present and wrong is refused.
     */
    private function scope(mixed $value): string
    {
        if ($value === null) {
            return 'site';
        }

        if (!\is_string($value) || !\in_array($value, self::SCOPES, true)) {
            throw ValidationException::forField('scope_type', 'must be site, course, cohort or organisation');
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    public static function defaultPerPage(): int
    {
        return self::DEFAULT_PER_PAGE;
    }
}
