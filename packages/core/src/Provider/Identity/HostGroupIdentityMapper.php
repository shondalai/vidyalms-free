<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Identity;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\HostGroupIssuer;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedCompleteness;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedGroup;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\CanonicalAttributes;
use Shondalai\VidyaLMS\Core\Registry\Context\IdentityAssertionContext;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperInterface;

/**
 * The free build's one identity dialect: the host's own groups or roles.
 *
 * It is what makes the identity seam a thing the free build actually
 * exercises rather than an empty registry with a paid provider promised. On
 * day one, on both hosts, an organisation can claim a local issuer, write
 * mapping rules against the host's own groups, and see a MappingDecision
 * explaining exactly what a person was granted and why. Everything a paid
 * dialect adds is a different way of filling the same bag.
 *
 * Three properties and each is a decision:
 *
 * **The issuer is minted per organisation.** {@see HostGroupIssuer} is the one
 * place that composes it and the class docblock there says why a single
 * site-wide value would be wrong.
 *
 * **Every key is an identifier.** A host group is a row with a primary key on
 * both shipped hosts, so the key kind is id and never name. A rule author
 * writing against a group's display name will match nothing, which is the
 * correct outcome: renaming a group would otherwise silently change who is
 * granted what.
 *
 * **The completeness is complete.** The host is reading its own tables, so it
 * knows every group the person is in, and there is no cap and no truncation to
 * worry about. That is what makes this the one free dialect a removal could be
 * driven from, if the connection were also marked authoritative by a
 * site-scoped principal. A dialect that terminates a federation cannot honestly
 * say the same, and none of them does.
 */
final class HostGroupIdentityMapper implements IdentityMapperInterface
{
    /** The registered key, which is also the connection's default dialect. */
    public const KEY = 'host-group';

    /**
     * The one setting: which host groups this organisation does not want read.
     *
     * A site's own catalogue of groups routinely contains several that mean
     * nothing to an organisation, and a rule author scrolling past forty of
     * them is a rule author who writes the wrong one.
     */
    public const EXCLUDE = 'exclude_group_ids';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'org.identity.mapper.host_group';
    }

    public function completeness(): string
    {
        return AssertedCompleteness::COMPLETE;
    }

    public function map(IdentityAssertionContext $context): ?AssertedIdentity
    {
        if ($context->kind !== IdentityAssertionContext::KIND_HOST_GROUPS) {
            // A bag of captured attributes is somebody else's dialect. Null is
            // "I cannot read this", which the caller records as a degradation
            // and which removes nothing.
            return null;
        }

        if ($context->userId < 1) {
            return null;
        }

        $excluded = $this->excluded($context->config);
        $groups = [];

        foreach ($context->hostGroups as $group) {
            $key = trim((string) ($group['key'] ?? ''));

            if ($key === '' || \in_array($key, $excluded, true)) {
                continue;
            }

            $label = isset($group['label']) ? (string) $group['label'] : null;
            $groups[] = AssertedGroup::hostGroup($key, $label === '' ? null : $label);
        }

        return AssertedIdentity::asserted(
            HostGroupIssuer::forOrganisation($context->organisationId),
            // The host account id, as an opaque string. It is a string because
            // a subject is opaque everywhere else in this design, and a local
            // subject that was an integer would be the one place somebody
            // could compare it numerically and get a different answer than the
            // byte comparison the index makes.
            (string) $context->userId,
            $groups,
            // The canonical bag carries the groups too, so a rule written
            // against "groups" reads the same values whichever dialect
            // produced them.
            [CanonicalAttributes::GROUPS => array_map(
                static fn (AssertedGroup $group): string => $group->key,
                $groups
            )],
            $this->completeness(),
        );
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private function excluded(array $config): array
    {
        $raw = $config[self::EXCLUDE] ?? [];

        if (!\is_array($raw)) {
            return [];
        }

        $keys = [];

        foreach ($raw as $entry) {
            if (\is_int($entry) && $entry > 0) {
                $keys[] = (string) $entry;

                continue;
            }

            if (\is_string($entry) && trim($entry) !== '') {
                $keys[] = trim($entry);
            }
        }

        return $keys;
    }
}
