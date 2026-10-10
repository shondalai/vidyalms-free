<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * An importer that says which source schemes it answers to.
 *
 * A capability interface rather than a method on ImporterInterface, for the
 * reason every capability in this codebase is one: the shipped interface must
 * not grow a method underneath a third party that already implements it.
 *
 * ## Why it had to exist
 *
 * Both hosts' Import screens decided whether to offer the database control by
 * probing one literal, `ImportSources::DATABASE_SOURCE`, which is `cms-db:`.
 * That was true of every importer that existed when the screens were written
 * and stopped being true the moment R4 registered three importers reading
 * another product's tables: `learndash-db:`, `tutor-db:` and `lifterlms-db:`
 * each answer false to `cms-db:` on purpose, because a LearnDash importer is
 * not a Community Quiz importer and must not claim the other's source string.
 *
 * The consequence was total. `describe()` answered `accepts_database` false, so
 * the screen offered an upload and the whole candidate extension list; the
 * controller then forced file mode and demanded a file; and the only string the
 * screen could compose was `cms-db:...` anyway. Three of the six format
 * importers this wave shipped could not be started from either shipped screen
 * at all.
 *
 * ## The contract
 *
 * Each entry ends in a colon and is the whole prefix, exactly as the importer's
 * own `supports()` reads it: `cms-db:`, `learndash-db:`. The first entry is the
 * one a screen composes a selector onto when the operator types one, because a
 * screen offers one database control and has to pick.
 *
 * An importer that declares nothing is read as answering to `cms-db:` and
 * nothing else, which is what every host already assumed of everybody, so the
 * three importers that shipped before this interface keep working unchanged and
 * the assumption is now written down in one place instead of being spelled into
 * two screens.
 */
interface DeclaresSourceSchemes extends ImporterInterface
{
    /**
     * The scheme prefixes this importer answers to, each ending in a colon,
     * most preferred first.
     *
     * @return list<string>
     */
    public function sourceSchemes(): array;
}
