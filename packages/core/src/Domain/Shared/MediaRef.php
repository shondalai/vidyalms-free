<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * Which parts of private storage a media reference in a definition may name.
 *
 * A definition's `storage_ref` is author-supplied and constrained only to be a
 * non-empty string, and two readers hand the bytes behind it out: the learner
 * projection signs it into a URL, and the QTI export reads it into the package.
 * With nothing narrowing it, one authored question naming
 * "exports/qti/999/strict-afc97ea131fd.zip" turned either reader into a read of
 * another course's answer key, and the same reference could name an assignment
 * submission or a learner's uploaded artifact. The storage adapters bound a
 * reference to the storage root and to nothing narrower, which is a different
 * question from what a question is allowed to show.
 *
 * So the prefixes are named here, once, and the readers ask. Two sets, because
 * they answer different questions: authored media is what a definition may
 * show every learner, and learner work is what a grader may open for one
 * attempt. A reference outside both is refused and named, never fetched.
 */
final class MediaRef
{
    /**
     * Where authored and imported course media lives: the two upload purposes
     * a teacher writes through, and everything an importer copies in.
     *
     * `questions/` and `stimuli/` are here for the item-scoped media the
     * authoring surface addresses directly.
     *
     * @var list<string>
     */
    public const AUTHORED_PREFIXES = [
        'lesson-files/',
        'course-images/',
        'imports/moodle/',
        'imports/qti/',
        'questions/',
        'stimuli/',
    ];

    /**
     * Where a learner's own work lives. A grader opens it for one attempt;
     * nothing authored may point at it, and no package carries it.
     *
     * @var list<string>
     */
    public const LEARNER_PREFIXES = [
        'attempts/',
        'submissions/',
    ];

    private function __construct()
    {
    }

    /**
     * Whether a definition may show this reference.
     */
    public static function isAuthored(string $reference): bool
    {
        return self::under($reference, self::AUTHORED_PREFIXES);
    }

    /**
     * Whether a grader may open this reference for the attempt they are
     * marking.
     */
    public static function isLearnerWork(string $reference): bool
    {
        return self::under($reference, self::LEARNER_PREFIXES);
    }

    /**
     * The prefixes named for a refusal message, in the words an author sees.
     */
    public static function authoredPrefixes(): string
    {
        return implode(', ', self::AUTHORED_PREFIXES);
    }

    /**
     * @param list<string> $prefixes
     */
    private static function under(string $reference, array $prefixes): bool
    {
        $trimmed = trim($reference);

        // A climb, an absolute path or a scheme is refused before the prefix
        // is even looked at: "lesson-files/../../exports/x.zip" starts with an
        // allowed prefix and names something else entirely.
        if (
            $trimmed === ''
            || str_starts_with($trimmed, '/')
            || str_contains($trimmed, '\\')
            || preg_match('#(^|/)\.\.(/|$)#', $trimmed) === 1
            || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $trimmed) === 1
        ) {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($trimmed, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
