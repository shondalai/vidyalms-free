<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper;

use Shondalai\VidyaLMS\Core\Registry\Context\RoleMappingContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Translates a foreign role token into a registered org role key.
 *
 * ## Why it is a registry and not a table
 *
 * The tokens a customer's roster carries are their source system's, not ours:
 * the eleven OneRoster words, the five LTI membership URIs and their deprecated
 * simple names, and a Canvas `role_id`, which is a number whose meaning is
 * private to one Canvas installation. A product that answers those from a
 * hardcoded map answers a Canvas 3 the same way on every site, which is wrong
 * on every site but one. So the translation is a provider: the free build ships
 * the standard vocabularies, and a customer whose district encodes something of
 * its own registers a mapper beside it with no core edit.
 *
 * ## The two hard rules, and they are enforced here rather than remembered
 *
 * **Rule one: an unmapped role is a warned skip and never a default.** A mapper
 * that cannot translate a token answers {@see RoleMapping::unmapped()} and the
 * caller reports a `RowProblem::CODE_UNMAPPED_ROLE` against the row. Defaulting
 * to the member role would look kind and would be the wrong kind: it gives
 * somebody a membership their own directory never asserted, and it does it
 * quietly, at roster scale.
 *
 * **Rule two: no mapping may ever produce `Capability::ADMIN`.** A foreign
 * roster asserting `systemAdministrator` is a foreign system saying that person
 * administers *it*. Turning that into site administration here would mean any
 * customer who can upload a CSV can promote themselves, which is the whole
 * threat model of a roster load in one sentence. The rule is checked by
 * {@see OrgRoleMapperRegistry::translate()} over the registered role's own
 * declared capabilities, so a third-party mapper cannot get around it by
 * answering a role key whose provider happens to name ADMIN: the registry
 * refuses the answer rather than trusting the mapper.
 *
 * ## Full URIs match first
 *
 * LTI simple names are deprecated. A mapper handling LTI therefore matches the
 * full `http://purl.imsglobal.org/vocab/lis/v2/membership#...` URI first and
 * accepts `Instructor` only as a compatibility fallback, answering
 * {@see RoleMapping::deprecated()} so the operator is told their integration is
 * on a form the standard has moved off.
 *
 * Miss direction: fail closed. No registered mapper translating a token is the
 * same outcome as a mapper that could not: the row is skipped with a row
 * problem naming the token, and no membership is written. Absence never grants
 * and absence never removes.
 */
interface OrgRoleMapperInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * The foreign vocabularies this mapper claims, from the
     * RoleMappingContext::VOCABULARY_* words.
     *
     * The registry asks before it tries, so a run naming its vocabulary is not
     * translated by a mapper for a different standard that happens to be
     * registered first. A mapper that declares nothing is asked for every
     * vocabulary, which is what a site-specific mapper for one district's own
     * words is.
     *
     * @return list<string>
     */
    public function vocabularies(): array;

    /**
     * Translates one token, or says why it could not.
     *
     * The token is the source's own string, untrimmed of its meaning: a
     * OneRoster `role` cell, an LTI role URI, a Canvas `role_id` rendered as a
     * string. A mapper normalises whatever it needs to and never rewrites the
     * token it reports back, because the operator has to find that string in
     * their own file.
     */
    public function map(string $token, RoleMappingContext $context): RoleMapping;
}
