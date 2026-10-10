<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * The ten policies the free build ships, in one list so the kernel, the
 * types' own fallback and the catalogue generator cannot disagree about what
 * "free" means.
 *
 * The membership rule is not taste. A free type's default policy, its legacy
 * default and every policy key a free-build migration writes must all name a
 * free provider, because the alternative is a free site resolving a key the
 * registry does not contain and a migration writing a value into
 * questions.scoring_policy that nothing free can grade. That rule is what
 * moved plus_minus, guess_neutral and absolute_position in: guess_neutral
 * because it is the new multiple-choice default, plus_minus because the free
 * build already computes exactly that arithmetic and it is the legacy pin,
 * and absolute_position because the ordering migration pins existing
 * questions to it.
 *
 * There is no free_v1_balanced. An earlier draft kept the free build's
 * original min(1, max(0, C - I) / |K|) as a deprecated provider so the
 * migration could pin to it; it is arithmetically identical to plus_minus,
 * so the pin is plus_minus and the extra provider is deleted rather than
 * maintained.
 */
final class FreeScoringPolicies
{
    private function __construct()
    {
    }

    /**
     * @return list<\Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface>
     */
    public static function all(): array
    {
        return [
            new MatchCorrectPolicy(),
            new MapResponsePolicy(),
            new PerTargetPolicy(),
            new PlusMinusPolicy(),
            new GuessNeutralPolicy(),
            new TextMatchPolicy(),
            new KendallTauPolicy(),
            new AbsolutePositionPolicy(),
            new ExternalPolicy(),
            new NonePolicy(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(
            static fn (\Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface $policy): string => $policy->key(),
            self::all(),
        );
    }

    public static function registerInto(ScoringPolicyRegistry $registry): void
    {
        foreach (self::all() as $policy) {
            $registry->register($policy);
        }
    }

    /**
     * A standalone registry of the free ten. What a free question type falls
     * back to when nothing hands it one, so grading works outside an attempt
     * (an authoring preview, a gradebook probe) without the type reaching
     * for a global.
     */
    public static function registry(): ScoringPolicyRegistry
    {
        $registry = new ScoringPolicyRegistry();
        self::registerInto($registry);

        return $registry;
    }
}
