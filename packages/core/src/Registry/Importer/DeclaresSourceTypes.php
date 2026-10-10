<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * An importer that says which source_type words it mints.
 *
 * A capability interface rather than a method on ImporterInterface, for the
 * reason every capability in this codebase is one: the three shipped
 * importers mint their own private words and owe nobody a declaration, and an
 * interface a third party has already shipped against must not grow a method
 * underneath them.
 *
 * It exists so the tenant rule can be checked at the top of a run rather than
 * discovered on row nine thousand. A mapper that declares any of
 * ImportSourceKey::RESERVED_TYPES is telling the runner that it writes into
 * the organisation surface, and the runner then refuses to start it without
 * an organisation in the run context: the alternative is an importer that
 * gets four hundred rows in before ImportMap refuses the first unprefixed
 * key, having already written four hundred memberships.
 *
 * The declaration is also what a screen reads to say what a run will touch,
 * and what a retention sweep reads to know which mappings belong to an
 * organisation rather than to the site.
 *
 * Declaring nothing reserved is legal and ordinary. It means the importer's
 * objects are its own, its keys are its own, and no tenant prefix applies,
 * which is exactly the position of every importer that shipped before this
 * interface existed.
 */
interface DeclaresSourceTypes extends ImporterInterface
{
    /**
     * The source_type words this importer passes to ImportMap.
     *
     * Reserved words carry the organisation scoped key composition documented
     * on ImportSourceKey; anything else is this importer's own vocabulary and
     * carries no rule at all. An empty list means the same as not
     * implementing this interface.
     *
     * @return list<string>
     */
    public function sourceTypes(): array;
}
