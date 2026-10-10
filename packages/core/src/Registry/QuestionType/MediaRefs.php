<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Application\Assessment\SignedMedia;

/**
 * Every storage ref a projection carries, at its dotted path, in one walker.
 *
 * `MediaBearingInterface::mediaRefs()` is the seam a media-bearing type
 * declares its refs through, and it was implemented five independent times
 * across one package, each re-writing the same is-a-non-empty-string test
 * and the same dotted-path formatting. Two traps ride on that duplication and
 * neither is caught by any shipped test on its own:
 *
 * - `SignedMedia::replaceAt` writes a fixed sibling named `url`, so two refs
 *   declared inside one node collapse onto one key and one is silently lost.
 *   One type re-parents its captions and transcript into separate nodes by
 *   hand specifically to avoid that, with nothing enforcing the rule.
 * - A type that forgets a nested ref publishes a private storage path to the
 *   learner, and nothing failed.
 *
 * So the walk is published once. A type calls this over its projection and
 * adds only the family-specific paths whose key is not `storage_ref`.
 */
final class MediaRefs
{
    private function __construct()
    {
    }

    /**
     * Every non-empty `storage_ref` under `$node`, keyed by its dotted path
     * relative to `$path`.
     *
     * @param array<array-key, mixed> $node
     *
     * @return iterable<string, string>
     */
    public static function in(array $node, string $path = ''): iterable
    {
        $ref = $node[SignedMedia::REF_KEY] ?? null;

        if (\is_string($ref) && $ref !== '') {
            yield self::join($path, SignedMedia::REF_KEY) => $ref;
        }

        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                yield from self::in($value, self::join($path, (string) $key));
            }
        }
    }

    /**
     * The same, restricted to one child of the projection, which is what a
     * type wanting only its own media node asks for.
     *
     * @param array<array-key, mixed> $node
     *
     * @return iterable<string, string>
     */
    public static function under(array $node, string $key): iterable
    {
        $child = $node[$key] ?? null;

        if (\is_array($child)) {
            yield from self::in($child, $key);
        }
    }

    private static function join(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path . '.' . $segment;
    }
}
