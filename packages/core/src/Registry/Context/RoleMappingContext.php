<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;

/**
 * What a foreign role token is translated against: which organisation, which
 * vocabulary the token came from, and which org role keys are actually
 * registered on this site.
 *
 * A context object rather than three positional arguments, on the standing
 * terms, and one of the three is load-bearing rather than decorative.
 * `registeredRoleKeys` is what makes rule one checkable at the seam instead of
 * at every call site: a mapper that answered `unit_manager` on a free build
 * would name a role no provider is registered for, and the membership write
 * would then fail closed several layers away from the file that caused it.
 *
 * It carries no database handle and no port, which is the org context rule: a
 * raw handle on the context every org provider receives would let any provider
 * read an org-scoped table with no tenant predicate and nothing would say so.
 */
final class RoleMappingContext
{
    /** A OneRoster CSV file set. */
    public const VOCABULARY_ONEROSTER = 'oneroster';

    /** An LTI membership role, full URI or deprecated simple name. */
    public const VOCABULARY_LTI = 'lti';

    /** A Canvas numeric role_id, or one of its base role names. */
    public const VOCABULARY_CANVAS = 'canvas';

    /** The source did not say, so a mapper tries every standard form it knows. */
    public const VOCABULARY_UNKNOWN = '';

    /**
     * The vocabularies this product publishes, which is the closed list a run
     * that names none is answered from.
     *
     * A registered mapper may claim a word outside this list, and that is what
     * a private vocabulary is: one district's own encoding, reached by a run
     * that names it and never by a run that named nothing. Without the
     * distinction, installing any package that claimed a private vocabulary
     * silently changed how the shipped member CSV granted roles, which is both
     * an escalation path and a break of the rule that the free build behaves
     * the same with or without a paid package installed.
     *
     * @var list<string>
     */
    public const STANDARD_VOCABULARIES = [
        self::VOCABULARY_ONEROSTER,
        self::VOCABULARY_LTI,
        self::VOCABULARY_CANVAS,
    ];

    /**
     * @param list<string> $registeredRoleKeys the org role keys this site actually has providers for
     */
    private function __construct(
        public readonly int $organisationId,
        public readonly string $vocabulary,
        public readonly array $registeredRoleKeys,
    ) {
    }

    /**
     * @param list<string> $registeredRoleKeys
     */
    public static function forOrganisation(
        int $organisationId,
        array $registeredRoleKeys,
        string $vocabulary = self::VOCABULARY_UNKNOWN,
    ): self {
        return new self($organisationId, $vocabulary, array_values($registeredRoleKeys));
    }

    /**
     * @param list<string> $registeredRoleKeys
     */
    public static function forScope(
        OrgScope $scope,
        array $registeredRoleKeys,
        string $vocabulary = self::VOCABULARY_UNKNOWN,
    ): self {
        return self::forOrganisation($scope->organisationId, $registeredRoleKeys, $vocabulary);
    }

    /** The same context reading a named vocabulary. */
    public function inVocabulary(string $vocabulary): self
    {
        return new self($this->organisationId, $vocabulary, $this->registeredRoleKeys);
    }

    /** Whether a vocabulary word is one of the three this product publishes. */
    public static function isStandard(string $vocabulary): bool
    {
        return \in_array($vocabulary, self::STANDARD_VOCABULARIES, true);
    }

    /** Whether this site has a provider for the role key a mapper wants to answer. */
    public function isRegistered(string $roleKey): bool
    {
        return \in_array($roleKey, $this->registeredRoleKeys, true);
    }
}
