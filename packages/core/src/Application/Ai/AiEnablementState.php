<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;

/**
 * The four levels of enablement and the one stop above them, read from the
 * rows they are stored in.
 *
 * They live in `provider_settings` rather than in columns of their own,
 * because the toggles are generated from the placement registry: a newly
 * registered helper arrives with its own toggle, and a ninth level that needed
 * a dedicated column would make the tenth a migration.
 *
 * Two provider keys under the registry key `ai` carry what is not per
 * placement: `enablement` holds level one, the site switch, and `stop` holds
 * the Article 14 halt with the person who set it and when. The per placement
 * switches are rows under the `AiPlacement` registry key, which is the same
 * key the generic provider settings route already lists, so a site owner can
 * reach them from either screen and read the same answer.
 *
 * Everything is off by default. Absent rows therefore mean disabled and not
 * enabled, which is the direction a gate has to fail.
 */
final class AiEnablementState
{
    public const REGISTRY = 'ai';
    public const PLACEMENT_REGISTRY = 'AiPlacement';
    public const ENABLEMENT_KEY = 'enablement';
    public const STOP_KEY = 'stop';

    /**
     * The provider key every ceiling is stored under, at site scope and again
     * per organisation.
     *
     * One row holding a map rather than a row per ceiling, because the set is
     * open: a vendor policy declaring its own cap needs somewhere to put it,
     * and a row per key would make the seventh cap a migration.
     */
    public const CEILINGS_KEY = 'ceilings';

    /**
     * The scope word a per-organisation ceiling is stored under.
     *
     * Named here rather than added to the shared settings repository's scope
     * order, because that order is the notification resolution chain and an
     * organisation is not a step in it: a ceiling reads its own two scopes and
     * nothing else.
     */
    public const SCOPE_ORGANISATION = 'organisation';

    public function __construct(
        private readonly SqlProviderSettingsRepository $settings,
    ) {
    }

    /** Level one. Absent means off, because everything is off by default. */
    public function siteEnabled(): bool
    {
        $stored = $this->settings->find(self::REGISTRY, self::ENABLEMENT_KEY, SqlProviderSettingsRepository::SCOPE_SITE, 0);

        return $this->flag($stored, 'enabled');
    }

    /** Level two, for one placement. */
    public function placementEnabled(string $placementKey): bool
    {
        $stored = $this->settings->find(
            self::PLACEMENT_REGISTRY,
            $placementKey,
            SqlProviderSettingsRepository::SCOPE_SITE,
            0,
        );

        return $this->flag($stored, 'enabled');
    }

    /**
     * One configured ceiling, at organisation scope first and site scope
     * behind it.
     *
     * Every spend and rate ceiling used to be unreachable. The settings
     * closure the policies read answered three keys and returned the caller's
     * own default for everything else, so all six shipped ceilings were pinned
     * to their constants and a site writing a per-scope call limit of one saw
     * four consecutive runs succeed. A ceiling nobody can configure is a
     * constant with a screen in front of it.
     */
    public function ceiling(string $key, mixed $default, ?int $organisationId = null): mixed
    {
        if ($organisationId !== null && $organisationId > 0) {
            $scoped = $this->settings->find(
                self::REGISTRY,
                self::CEILINGS_KEY,
                self::SCOPE_ORGANISATION,
                $organisationId,
            );

            if (\is_array($scoped) && \array_key_exists($key, $scoped)) {
                return $scoped[$key];
            }
        }

        $site = $this->settings->find(
            self::REGISTRY,
            self::CEILINGS_KEY,
            SqlProviderSettingsRepository::SCOPE_SITE,
            0,
        );

        return \is_array($site) && \array_key_exists($key, $site) ? $site[$key] : $default;
    }

    /**
     * Every ceiling a site has configured, for the admin screen that lists
     * them.
     *
     * @return array<string, mixed>
     */
    public function ceilings(): array
    {
        $stored = $this->settings->find(
            self::REGISTRY,
            self::CEILINGS_KEY,
            SqlProviderSettingsRepository::SCOPE_SITE,
            0,
        );

        return \is_array($stored) ? $stored : [];
    }

    /**
     * The stop, as a shape the contract already declares. A cleared stop keeps
     * who cleared it and when, for the same reason setting keeps who set it: a
     * control whose exercise leaves no trace is a control nobody can audit.
     *
     * @return array<string, mixed>
     */
    public function stop(): array
    {
        $stored = $this->settings->find(self::REGISTRY, self::STOP_KEY, SqlProviderSettingsRepository::SCOPE_SITE, 0);
        $params = $this->params($stored);

        return [
            'stopped' => ($params['stopped'] ?? false) === true,
            'reason' => \is_string($params['reason'] ?? null) ? $params['reason'] : null,
            'set_by' => $this->nullableInt($params['set_by'] ?? null),
            'set_at' => \is_string($params['set_at'] ?? null) ? $params['set_at'] : null,
            'cleared_by' => $this->nullableInt($params['cleared_by'] ?? null),
            'cleared_at' => \is_string($params['cleared_at'] ?? null) ? $params['cleared_at'] : null,
        ];
    }

    public function stopped(): bool
    {
        return ($this->stop()['stopped'] ?? false) === true;
    }

    /**
     * `find()` already answers the decoded parameter bag rather than the row,
     * so this only has to normalise "no row at all" to "no parameters", which
     * is the reading that keeps everything off by default.
     *
     * @param array<string, mixed>|null $stored
     *
     * @return array<string, mixed>
     */
    private function params(?array $stored): array
    {
        return $stored ?? [];
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    private function flag(?array $stored, string $name): bool
    {
        $value = $this->params($stored)[$name] ?? false;

        return $value === true || $value === 1 || $value === '1';
    }

    private function nullableInt(mixed $value): ?int
    {
        return \is_numeric($value) ? (int) $value : null;
    }
}
