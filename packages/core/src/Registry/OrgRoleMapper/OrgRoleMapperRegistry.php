<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgRoleMapper;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\RoleMappingContext;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;

/**
 * @extends AbstractProviderRegistry<OrgRoleMapperInterface>
 */
final class OrgRoleMapperRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return OrgRoleMapperInterface::class;
    }

    /**
     * Translates one foreign token through whichever registered mapper claims
     * its vocabulary, and enforces the two hard rules over the answer.
     *
     * The enforcement is here rather than in each mapper on purpose. A rule a
     * provider is asked to remember is a rule a third-party provider will not,
     * and the second of these is the one that matters: **no mapping may ever
     * produce `Capability::ADMIN`**. A mapper cannot get around it by answering
     * a role key whose provider declares administrative capability, because the
     * check is run against the *registered role*, on this site, at translation
     * time, rather than against a list of role keys somebody wrote down.
     *
     * The first rule is enforced by construction: a token nothing translates
     * comes back unmapped and no default is ever substituted here.
     *
     * ## The order mappers are tried in
     *
     * Mappers claiming the run's own vocabulary first, in registration order,
     * then mappers that claim none, which is what a site-specific mapper for
     * one district's private words is. The first mapped answer wins; a refusal
     * stops the search, because a refusal is a decision and trying the next
     * mapper until one says yes is how a refusal becomes advisory.
     */
    public function translate(
        string $token,
        RoleMappingContext $context,
        OrgRoleRegistry $roles,
        ?string $preferredKey = null,
    ): RoleMapping {
        $token = trim($token);

        if ($token === '') {
            return RoleMapping::unmapped($token);
        }

        foreach ($this->ordered($context, $preferredKey) as $mapper) {
            $mapping = $mapper->map($token, $context);

            if ($mapping->outcome === RoleMapping::OUTCOME_REFUSED) {
                return $mapping;
            }

            if (!$mapping->isMapped()) {
                continue;
            }

            return $this->verify($mapping, $roles);
        }

        return RoleMapping::unmapped($token);
    }

    /**
     * The two hard rules, applied to a role key a file asked for.
     *
     * Published rather than private because a file can name a role key two
     * ways. It can name a foreign token, which comes through
     * {@see translate()}; or it can name one of this site's own registered
     * keys, which the roster importer takes as itself, because translating
     * `org_admin` through a foreign vocabulary would be a category error. That
     * shortcut returned before any rule ran, so a member CSV row naming
     * `org_owner` wrote an ownership grant that no mapping rule is allowed to
     * name. One guard, asked by both paths.
     */
    public function verifyRoleKey(string $token, string $roleKey, OrgRoleRegistry $roles): RoleMapping
    {
        return $this->verify(RoleMapping::to($token, $roleKey), $roles);
    }

    /**
     * Rule two, applied to a mapper's answer rather than trusted from it.
     *
     * Three ways an answer fails. A role key nothing registers is refused,
     * because writing it would produce a membership whose role resolves to no
     * capabilities at all and a console that names a missing provider, several
     * layers away from the file that caused it. A role whose provider declares
     * site administration is refused outright: that is the escalation this seam
     * exists to make impossible, and it is refused even when the role is one
     * this build ships, because the check must not depend on who wrote the
     * provider. And a role that refuses to be named by a directory mapping rule
     * refuses to be named by a file for the same reason: both are a foreign
     * document deciding a role, and ownership is not a thing a document decides.
     */
    private function verify(RoleMapping $mapping, OrgRoleRegistry $roles): RoleMapping
    {
        $key = $mapping->requireRoleKey();

        if (!$roles->has($key)) {
            return RoleMapping::refused($mapping->token, sprintf(
                'A role mapper answered "%s" for the role "%s", and no org role provider is registered under'
                    . ' that key on this site. The row was skipped rather than written against a role that'
                    . ' resolves to no capabilities at all.',
                $key,
                $mapping->token,
            ));
        }

        if (\in_array(Capability::ADMIN, $roles->get($key)->capabilities(), true)) {
            return RoleMapping::refused($mapping->token, sprintf(
                'A role mapper answered "%s" for the role "%s", and that role operates under site'
                    . ' administration. No mapping may ever produce it: a foreign roster asserting an'
                    . ' administrator is asserting one of its own, and honouring that would make uploading a'
                    . ' file a way to become a site administrator here.',
                $key,
                $mapping->token,
            ));
        }

        if (!$roles->get($key)->grantableByMappingRule()) {
            return RoleMapping::refused($mapping->token, sprintf(
                'The role "%s" answered for "%s" refuses to be granted by a rule or a file. A document naming'
                    . ' it is a document deciding who owns this organisation, and that decision is made one'
                    . ' person at a time in the console that applies the subset rule.',
                $key,
                $mapping->token,
            ));
        }

        return $mapping;
    }

    /**
     * @return list<OrgRoleMapperInterface>
     */
    private function ordered(RoleMappingContext $context, ?string $preferredKey = null): array
    {
        // A run naming a mapper gets that one and no other. It is the run
        // option a district sets when it has registered its own mapper beside
        // the shipped one, and falling back to the shipped one when their
        // mapper cannot translate a word would silently undo their decision.
        if ($preferredKey !== null && $preferredKey !== '') {
            return $this->has($preferredKey) ? [$this->get($preferredKey)] : [];
        }

        $claiming = [];
        $general = [];

        foreach ($this->all() as $mapper) {
            $vocabularies = $mapper->vocabularies();

            if ($vocabularies === []) {
                $general[] = $mapper;

                continue;
            }

            if (\in_array($context->vocabulary, $vocabularies, true)) {
                $claiming[] = $mapper;

                continue;
            }

            // A run that did not say which vocabulary it read reaches the
            // published standard vocabularies and nothing else. It used to
            // reach every claiming mapper, which meant a private vocabulary
            // nobody named answered a token the shipped format has never heard
            // of: registering one package turned `HR-200` in a member CSV into
            // a role grant, and two packages with overlapping private words
            // were resolved by registration order. A mapper for a private
            // vocabulary is reached by a run that names it, through the
            // role_map_key option or through a format that declares it.
            if ($context->vocabulary === RoleMappingContext::VOCABULARY_UNKNOWN && $this->standardOnly($vocabularies)) {
                $claiming[] = $mapper;
            }
        }

        return array_merge($claiming, $general);
    }

    /**
     * Whether every vocabulary a mapper claims is one this product publishes.
     *
     * @param list<string> $vocabularies
     */
    private function standardOnly(array $vocabularies): bool
    {
        foreach ($vocabularies as $vocabulary) {
            if (!RoleMappingContext::isStandard($vocabulary)) {
                return false;
            }
        }

        return true;
    }
}
