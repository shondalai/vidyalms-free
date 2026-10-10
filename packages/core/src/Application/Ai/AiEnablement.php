<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresEnablementLevel;

/**
 * The four levels of enablement and the one stop above all four, published as
 * data so the admin screen is generated rather than written.
 *
 * Everything is off by default. The site enables AI at all; the site enables
 * each **placement**; each **route** enables each task, and a route whose
 * bridge answers false to its configured predicate is never selected; and
 * each **person** accepts the versioned data-use policy at first use.
 *
 * **Above all four sits one control that halts every task on the site in a
 * safe state**, without uninstalling anything, and a run in flight that
 * observes it stops and records that it was cancelled. A stop assembled from
 * twelve toggles does not satisfy an obligation to have one, and the admin
 * model this is measured against ships the same four-level shape with ten
 * hand-written toggles. Here the toggles are generated from the registries,
 * so a new helper arrives with its own toggle and no admin screen is edited.
 *
 * **The stop is a stored row with two named read points**, because a control
 * with no storage is a paragraph. It is a provider-settings row at site
 * scope recording who set it and when; the run use case reads it before the
 * reservation and the progress reporter polls it between units, so a
 * four-minute build stops within one unit rather than at the end. A stopped
 * run and a stopped job both record that they were cancelled rather than that
 * they failed: filing the exercise of a safety control in the same list as a
 * genuine breakage is how the evidence gets lost.
 */
final class AiEnablement
{
    /** The registry key every AI setting is stored under. */
    public const REGISTRY_KEY = 'ai';

    public const LEVEL_SITE = 'site';
    public const LEVEL_PLACEMENT = 'placement';
    public const LEVEL_ROUTE = 'route';
    public const LEVEL_PERSON = 'person';

    /** The provider key the one stop control is stored under, at site scope. */
    public const STOP_PROVIDER_KEY = 'stop';

    private function __construct()
    {
    }

    /**
     * The levels every registered policy publishes, in the order a run is
     * refused by them.
     *
     * The order is not cosmetic. A person told that AI is off on this site
     * should not first be told that they have not accepted a policy, and a
     * person who has accepted everything should be told which placement is
     * disabled rather than that something is off somewhere.
     *
     * **Derived rather than enumerated.** The four levels were a hardcoded
     * list naming three free policy keys, while the placement toggles below
     * them were generated from the registry: a site running a vendor gate that
     * denies saw four green levels and every run refused, with nothing on the
     * screen naming the gate that refused it. A policy that is a level says so
     * through {@see DeclaresEnablementLevel}, and this is the union of what the
     * registered policies publish, so a fifth level arrives with its own row
     * and no admin screen is edited.
     *
     * @return list<array{level: string, setting: string, stored_in: string, refused_by: string}>
     */
    public static function levels(AiPolicyRegistry $policies): array
    {
        $levels = [];

        foreach ($policies->all() as $key => $policy) {
            if (!$policy instanceof DeclaresEnablementLevel) {
                continue;
            }

            foreach ($policy->enablementLevels() as $level) {
                $levels[] = [
                    'level' => $level['level'],
                    'setting' => $level['setting'],
                    'stored_in' => $level['stored_in'],
                    'refused_by' => (string) $key,
                ];
            }
        }

        // The shipped four first, in the order a run meets them; anything a
        // package added after them, in key order so the screen is stable.
        $order = array_flip([self::LEVEL_SITE, self::LEVEL_PLACEMENT, self::LEVEL_ROUTE, self::LEVEL_PERSON]);

        usort($levels, static fn (array $a, array $b): int => [$order[$a['level']] ?? PHP_INT_MAX, $a['level']]
            <=> [$order[$b['level']] ?? PHP_INT_MAX, $b['level']]);

        return $levels;
    }

    /**
     * The stop, described the same way, so the screen that renders the four
     * levels renders this above them from the same source.
     *
     * @return array{setting: string, registry_key: string, provider_key: string, scope: string, refused_by: string}
     */
    public static function stop(): array
    {
        return [
            'setting' => \Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PlacementEnabledPolicy::SETTING_STOP,
            'registry_key' => self::REGISTRY_KEY,
            'provider_key' => self::STOP_PROVIDER_KEY,
            'scope' => 'site',
            'refused_by' => \Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PlacementEnabledPolicy::KEY,
        ];
    }

    /**
     * The setting key one placement's toggle is stored under.
     *
     * Generated from the placement key rather than enumerated, which is the
     * whole reason a new helper needs no admin screen edit.
     */
    public static function placementSetting(string $placementKey): string
    {
        return \Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PlacementEnabledPolicy::SETTING_PLACEMENT_PREFIX
            . $placementKey;
    }
}
