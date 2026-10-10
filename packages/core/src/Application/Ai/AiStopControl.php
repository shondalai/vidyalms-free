<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AiPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiSurfaceInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\DescribesAiPlacement;
use Shondalai\VidyaLMS\Core\Registry\ProviderEdition;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * The four levels of enablement and the one stop above all four.
 *
 * The stop is the AI Act Article 14 control, and it is a stored row with a
 * route and two named read points rather than a paragraph: a control assembled
 * from twelve toggles does not satisfy the requirement, and a control with no
 * storage is not a control at all. `StartAiRun` reads it before the reservation
 * and the progress sink polls it between units, so a four-minute build stops
 * within one unit rather than at the end. A stopped run records `cancelled` and
 * a stopped job records `cancelled`, because filing the exercise of a safety
 * control as an ordinary failure would put it in the same list as a breakage.
 *
 * The per placement toggles are generated from the placement registry rather
 * than hand written, which is the whole reason a newly registered helper
 * arrives with its own toggle and no admin screen is edited.
 */
final class AiStopControl
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly AiEnablementState $enablement,
        private readonly AiAccess $access,
        private readonly Registries $registries,
        private readonly SqlAiReadModel $reads,
        private readonly SqlProviderSettingsRepository $settings,
        private readonly ClockPort $clock,
        // A placement's label() answers its catalogue key, the way every other
        // registry's does, so the reader translates it before it is published.
        // Publishing the key put "ai.placement.admin_narrative.label" on the
        // enablement panel of every real site, which is what a live run found.
        private readonly TranslatorPort $translator,
        private readonly ?ProviderPackages $packages = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function enablement(Principal $principal): array
    {
        $this->access->requireManage($principal);

        return $this->state();
    }

    /**
     * Levels one and two in one act, because a screen that saves them
     * separately can leave a site enabled with every placement off and call it
     * a save.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function setEnablement(Principal $principal, array $body): array
    {
        $this->access->requireManage($principal);

        foreach (array_keys($body) as $field) {
            if (!\in_array((string) $field, ['site_enabled', 'placements', 'ceilings'], true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField((string) $field, 'is not a field of the enablement state');
            }
        }

        if (\array_key_exists('site_enabled', $body)) {
            if (!\is_bool($body['site_enabled'])) {
                throw ValidationException::forField('site_enabled', 'must be true or false');
            }

            $this->settings->save(
                AiEnablementState::REGISTRY,
                AiEnablementState::ENABLEMENT_KEY,
                SqlProviderSettingsRepository::SCOPE_SITE,
                0,
                ['enabled' => $body['site_enabled']],
                $principal->userId,
            );
        }

        if (\array_key_exists('placements', $body)) {
            if (!\is_array($body['placements'])) {
                throw ValidationException::forField('placements', 'must map placement keys to true or false');
            }

            foreach ($body['placements'] as $key => $enabled) {
                $key = (string) $key;

                if (!$this->registries->aiPlacements->has($key)) {
                    throw ValidationException::forField(
                        'placements',
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        sprintf('no placement answers "%s" on this site, so a switch for it would read nothing', $key),
                    );
                }

                if (!\is_bool($enabled)) {
                    throw ValidationException::forField('placements', 'must map placement keys to true or false');
                }

                $this->settings->save(
                    AiEnablementState::PLACEMENT_REGISTRY,
                    $key,
                    SqlProviderSettingsRepository::SCOPE_SITE,
                    0,
                    ['enabled' => $enabled],
                    $principal->userId,
                );
            }
        }

        if (\array_key_exists('ceilings', $body)) {
            $this->saveCeilings($principal, $body['ceilings']);
        }

        return $this->state();
    }

    /**
     * The spend and rate ceilings, written as one row.
     *
     * They had no write at all, and the resolver behind them answered the
     * caller's own default for every key, so all six were pinned to their
     * constants and the screen that lists them listed six numbers nobody could
     * change. The values are stored as given, because a ceiling's unit is the
     * policy's business and not this method's; what is refused is a value that
     * is not a number at all.
     */
    private function saveCeilings(Principal $principal, mixed $ceilings): void
    {
        if (!\is_array($ceilings)) {
            throw ValidationException::forField('ceilings', 'maps a ceiling key to a number');
        }

        $values = $this->enablement->ceilings();

        foreach ($ceilings as $key => $value) {
            $key = (string) $key;

            if (!str_starts_with($key, 'ceiling.')) {
                throw ValidationException::forField(
                    'ceilings',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('"%s" is not a ceiling key, which begins "ceiling."', $key),
                );
            }

            if ($value === null) {
                unset($values[$key]);
                continue;
            }

            if (!\is_int($value) && !\is_float($value)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField('ceilings', sprintf('"%s" must be a number', $key));
            }

            if ($value < 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField('ceilings', sprintf('"%s" cannot be negative', $key));
            }

            $values[$key] = $value;
        }

        $this->settings->save(
            AiEnablementState::REGISTRY,
            AiEnablementState::CEILINGS_KEY,
            SqlProviderSettingsRepository::SCOPE_SITE,
            0,
            $values,
            $principal->userId,
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function stop(Principal $principal, array $body): array
    {
        $this->access->requireManage($principal);

        $reason = $body['reason'] ?? null;

        if ($reason !== null && !\is_string($reason)) {
            throw ValidationException::forField('reason', 'must be text, or absent');
        }

        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->settings->save(
            AiEnablementState::REGISTRY,
            AiEnablementState::STOP_KEY,
            SqlProviderSettingsRepository::SCOPE_SITE,
            0,
            [
                'stopped' => true,
                'reason' => \is_string($reason) && $reason !== '' ? $reason : null,
                'set_by' => $principal->userId,
                'set_at' => $now,
                'cleared_by' => null,
                'cleared_at' => null,
            ],
            $principal->userId,
        );

        return $this->enablement->stop();
    }

    /**
     * @return array<string, mixed>
     */
    public function clear(Principal $principal): array
    {
        $this->access->requireManage($principal);

        $existing = $this->enablement->stop();
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->settings->save(
            AiEnablementState::REGISTRY,
            AiEnablementState::STOP_KEY,
            SqlProviderSettingsRepository::SCOPE_SITE,
            0,
            [
                'stopped' => false,
                'reason' => $existing['reason'] ?? null,
                'set_by' => $existing['set_by'] ?? null,
                'set_at' => $existing['set_at'] ?? null,
                'cleared_by' => $principal->userId,
                'cleared_at' => $now,
            ],
            $principal->userId,
        );

        return $this->enablement->stop();
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        $placements = [];

        foreach ($this->registries->aiPlacements->all() as $placement) {
            if (!$placement instanceof AiPlacementInterface) {
                continue;
            }

            $surface = $this->registries->aiSurfaces->has($placement->surfaceKey())
                ? $this->registries->aiSurfaces->get($placement->surfaceKey())
                : null;

            $placements[] = [
                'key' => $placement->key(),
                'label' => $this->translator->translate($placement->label()),
                'description' => $placement instanceof DescribesAiPlacement
                    ? $this->translator->translate($placement->description())
                    : '',
                'surface_key' => $placement->surfaceKey(),
                'surface_label' => $surface instanceof AiSurfaceInterface
                    ? $this->translator->translate($surface->label())
                    : $placement->surfaceKey(),
                'enabled' => $this->enablement->placementEnabled($placement->key()),
                'package' => $this->packages?->packageOf(AiEnablementState::PLACEMENT_REGISTRY, $placement->key()),
                'edition' => $this->edition($placement->key()),
            ];
        }

        usort($placements, static fn (array $a, array $b): int => (string) $a['key'] <=> (string) $b['key']);

        $active = $this->reads->activePolicy($this->clock->now()->format(self::DATETIME_FORMAT));

        return [
            'site_enabled' => $this->enablement->siteEnabled(),
            'stop' => $this->enablement->stop(),
            // Derived from the registered policies. A vendor gate publishes
            // its own row here, so a site owner is no longer shown four green
            // levels while a fifth one refuses every run unnamed.
            'levels' => AiEnablement::levels($this->registries->aiPolicies),
            'ceilings' => $this->enablement->ceilings(),
            'placements' => $placements,
            'routes' => $this->reads->routeSummary(),
            'policy' => $active === null
                ? null
                : AiPresenter::policy($active, $this->reads->acceptanceCount((int) $active['version'])),
        ];
    }

    /**
     * The contract publishes free, pro and third_party; the shared edition
     * helper answers free, pro and business, so business reads as pro here.
     * A screen only needs to know whether a switch belongs to something the
     * site owner paid for.
     */
    private function edition(string $placementKey): string
    {
        $edition = ProviderEdition::of($this->packages, AiEnablementState::PLACEMENT_REGISTRY, $placementKey);

        return match ($edition) {
            ProviderEdition::FREE => 'free',
            ProviderEdition::PRO, ProviderEdition::BUSINESS => 'pro',
            default => 'third_party',
        };
    }
}
