<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgRole;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgRoleContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The roles a person may hold inside one organisation.
 *
 * It was a bespoke class in Domain that threw LogicException, had no
 * replace(), and was constructed as a local variable inside the kernel, so
 * nothing outside the core could register a role at all. Seating it here is
 * what makes the whole organisations area extensible rather than merely
 * described as extensible.
 *
 * The action vocabulary is the union over the registered providers rather than
 * a literal list, which is the second half of the same fix: a role that could
 * be registered but could neither introduce nor be granted a right would be a
 * seam with a wall behind it.
 *
 * @extends AbstractProviderRegistry<OrgRoleInterface>
 */
final class OrgRoleRegistry extends AbstractProviderRegistry
{
    /**
     * The right to give somebody a role.
     *
     * Named here because the subset rule needs to know which right assigning
     * is, and because the alternative is the same string appearing in the
     * membership use case, the importer and every invitation path.
     */
    public const ACTION_ROLES = 'roles';

    protected function providerInterface(): string
    {
        return OrgRoleInterface::class;
    }

    /**
     * Whether a role carries one right, answered from its own actions() list.
     *
     * **It deliberately does not ask the provider's predicate.** Two
     * independent declarations were deciding two different security
     * questions: the resolver authorised through permits() while the
     * assignability subset rule was computed over actions(), and nothing
     * cross-checked them. A role whose actions() was empty and whose
     * permits() answered true was therefore rightless to the subset rule and
     * omnipotent to the resolver, so an ordinary org_admin could grant rights
     * they did not hold, including the settings right that gates personal
     * data export and erasure. Deriving the answer from the enumerable list
     * makes the two questions one, and {@see guardProvider()} tells an author
     * whose predicate disagrees rather than letting it drift unnoticed.
     */
    public function availableFor(string $key, ?int $organisationId): bool
    {
        if (!$this->has($key)) {
            return false;
        }
        $role = $this->get($key);
        return !$role instanceof ScopedToOrganisation || $role->organisationId() === $organisationId;
    }

    public function permits(string $key, string $action, ?int $organisationId = null): bool
    {
        return $this->availableFor($key, $organisationId) && \in_array($action, $this->get($key)->actions(), true);
    }

    /**
     * Two admission rules, both of which the interface publishes in prose.
     *
     * capabilities() says it is drawn from the shipped Capability constants
     * and nothing else; unchecked, a package could make the core ask the host
     * authorization port about arbitrary strings, once per distinct string,
     * on the role catalogue and role assignment paths.
     *
     * permits() says it is "the same answer actions() lists"; unchecked, the
     * two drift. Authorisation no longer reads the predicate, so a drift is
     * no longer an escalation, but a provider whose two halves disagree is a
     * provider whose author believes something false about their own role.
     */
    protected function guardProvider(ProviderInterface $provider): void
    {
        if (!$provider instanceof OrgRoleInterface) {
            return;
        }

        $unknown = array_values(array_diff($provider->capabilities(), Capability::all()));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'The organisation role "%s" names %s, which is not one of the shipped capabilities (%s). '
                . 'A capability outside that vocabulary sits on no host role and can be granted to nobody.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $provider->key(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', $unknown),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', Capability::all()),
            ));
        }

        $vocabulary = array_values(array_unique([...$this->actions(), ...$provider->actions()]));
        $disagrees = [];

        foreach ($vocabulary as $action) {
            if ($provider->permits($action) !== \in_array($action, $provider->actions(), true)) {
                $disagrees[] = $action;
            }
        }

        if ($disagrees !== []) {
            throw new \InvalidArgumentException(sprintf(
                'The organisation role "%s" answers permits() and actions() differently for %s. '
                . 'They are one declaration: actions() is the enumerable form of permits(), and authorisation reads it.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $provider->key(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', $disagrees),
            ));
        }
    }

    /**
     * Every right any registered role names, sorted.
     *
     * This is the site's action vocabulary. It is derived rather than declared
     * so that a registered role introducing a right introduces it everywhere
     * the vocabulary is read, which is the console's action list and the
     * subset comparison.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        $actions = [];

        foreach ($this->all() as $role) {
            foreach ($role->actions() as $action) {
                $actions[$action] = true;
            }
        }

        $found = array_keys($actions);
        sort($found);

        return $found;
    }

    /**
     * The keys of every registered role that owns its organisation.
     *
     * The last-owner invariant, the erasure refusal and the owner count all
     * ask this rather than naming a key, so a package registering its own
     * ownership role inherits the invariant.
     *
     * @return list<string>
     */
    public function ownershipKeys(): array
    {
        $keys = [];

        foreach ($this->all() as $role) {
            if ($role instanceof DeclaresOwnership) {
                $keys[] = $role->key();
            }
        }

        return $keys;
    }

    /**
     * Whether any of these keys is an ownership role.
     *
     * @param list<string> $keys
     */
    public function holdsOwnership(array $keys): bool
    {
        return array_intersect($keys, $this->ownershipKeys()) !== [];
    }

    /**
     * The capabilities this role is actually effective under for this person.
     *
     * The narrowing rule made arithmetic: declared capabilities intersected
     * with what the host answers. A role declaring site administration returns
     * nothing here for anyone the host has not already made a site
     * administrator, which is why privilege escalation through a registration
     * is impossible rather than merely unlikely.
     *
     * @return list<string>
     */
    public function effectiveCapabilities(string $key, OrgRoleContext $context): array
    {
        if (!$this->availableFor($key, $context->organisationId)) {
            return [];
        }

        $effective = array_values(array_intersect(
            $this->get($key)->capabilities(),
            $context->hostCapabilities,
        ));
        sort($effective);

        return $effective;
    }

    /**
     * The role keys this caller may assign, computed rather than enumerated.
     *
     * Three conditions, and each answers a real attack.
     *
     * A role carrying any right at all may be assigned only by a caller who
     * holds the right to assign roles, which is what stops an administrator
     * who may add people from also deciding what those people may do.
     *
     * The assigned role's rights must be a subset of the caller's own, which
     * is what stops the roster column naming a higher role: an administrator
     * cannot upload a row that gives somebody the two rights the table says
     * they themselves may not have, one of which prunes the log of their own
     * reads.
     *
     * And the assigned role's capabilities must be a subset of the caller's,
     * compared **twice**: over the declared list and over the effective one.
     * The effective comparison alone was vacuous for the case it was added
     * for, because effectiveCapabilities() intersects the role's declared list
     * with what the host answers for the *caller*, so a role declaring site
     * administration effectively declared nothing in an ordinary caller's
     * context and was a subset of everything. Comparing the declared list
     * against what the caller actually holds is the half that refuses it.
     *
     * A site manager entering through an org route is scoped like everyone
     * else, and their own right set is the whole vocabulary rather than a
     * bypass, so a right no registered role names is assignable by nobody.
     *
     * @return list<string>
     */
    public function assignableBy(OrgRoleContext $context): array
    {
        $callerActions = $context->siteManager ? $this->actions() : $this->actionsOf($context->roleKeys, $context->organisationId);
        $callerCapabilities = $context->siteManager
            ? $context->hostCapabilities
            : $this->capabilitiesOf($context->roleKeys, $context);
        $mayAssign = \in_array(self::ACTION_ROLES, $callerActions, true);
        $assignable = [];

        foreach ($this->all() as $role) {
            if (!$this->availableFor($role->key(), $context->organisationId)) {
                continue;
            }
            $actions = $role->actions();

            if ($actions !== [] && !$mayAssign) {
                continue;
            }

            if (array_diff($actions, $callerActions) !== []) {
                continue;
            }

            if (array_diff($this->effectiveCapabilities($role->key(), $context), $callerCapabilities) !== []) {
                continue;
            }

            // A site manager stands above the organisation and their own right
            // set is the whole vocabulary, exactly as it is for actions()
            // above; the declared comparison is the rule for a caller who is
            // inside the tenant, which is the caller it was written for.
            if (!$context->siteManager && array_diff($role->capabilities(), $context->hostCapabilities) !== []) {
                continue;
            }

            $assignable[] = $role->key();
        }

        return $assignable;
    }

    /**
     * The rights this caller holds, which is the union over their own roles.
     *
     * @param list<string> $keys
     *
     * @return list<string>
     */
    public function actionsOf(array $keys, ?int $organisationId = null): array
    {
        $actions = [];

        foreach ($keys as $key) {
            if (!$this->availableFor($key, $organisationId)) {
                continue;
            }

            foreach ($this->get($key)->actions() as $action) {
                $actions[$action] = true;
            }
        }

        $found = array_keys($actions);
        sort($found);

        return $found;
    }

    /**
     * The catalogue a person reads, ordered by the providers' own declaration.
     *
     * @return list<array{key: string, label: string, actions: list<string>, group: string, order: int}>
     */
    public function catalogue(?int $organisationId = null): array
    {
        $rows = [];

        foreach ($this->all() as $role) {
            if (!$this->availableFor($role->key(), $organisationId)) {
                continue;
            }
            $rows[] = [
                'key' => $role->key(),
                'label' => $role->label(),
                'actions' => $role->actions(),
                'group' => $role->group(),
                'order' => $role->order(),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['group'], $a['order'], $a['key']]
            <=> [$b['group'], $b['order'], $b['key']]);

        return $rows;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function capabilitiesOf(array $keys, OrgRoleContext $context): array
    {
        $capabilities = [];

        foreach ($keys as $key) {
            foreach ($this->effectiveCapabilities($key, $context) as $capability) {
                $capabilities[$capability] = true;
            }
        }

        $found = array_keys($capabilities);
        sort($found);

        return $found;
    }
}
