<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgJoinRule;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgJoinContext;

/**
 * @extends AbstractProviderRegistry<OrgJoinRuleInterface>
 */
final class OrgJoinRuleRegistry extends AbstractProviderRegistry
{
    /**
     * The rule every organisation is under until a site chooses another.
     *
     * There is no org_join_rules row to read yet, so the free build applies
     * this key on every join. That is not a placeholder for the check: the
     * check runs, it resolves through the registry, and removing the provider
     * stops every acceptance, which is the published direction.
     *
     * **The consequence for a package author is stated rather than left to be
     * found.** The registry accepts a registration and no site can select it:
     * there is no org_join_rules table, no column on organisations and no
     * route, so the acceptance path asks for this key and only this key. The
     * column arrives with the seat and invitation slice, and until it does a
     * registered rule is reachable only by replacing this one.
     */
    public const DEFAULT_KEY = 'invitation_only';

    protected function providerInterface(): string
    {
        return OrgJoinRuleInterface::class;
    }

    /**
     * Whether this join may proceed under the named rule.
     *
     * False for an unresolved key, which is the fail-closed direction: a gate
     * nobody can answer answers no.
     */
    public function admits(string $key, OrgJoinContext $context): bool
    {
        return $this->has($key) && $this->get($key)->admits($context);
    }
}
