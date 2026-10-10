<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Shared\MediaRef;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\MediaBearingInterface;

/**
 * Turns the storage refs a projection carries into signed, expiring URLs, at
 * read time, once, for every type.
 *
 * The standing core invariant is that private files go through the Storage
 * port and the only URL the core ever emits for one is a signed, expiring URL.
 * The assessment path broke it in the free build today: the stimulus media
 * column is declared as holding media refs and was emitted to the learner
 * verbatim.
 *
 * The substitution happens on the way out rather than on the way in, because
 * a signature frozen onto attempt_questions.presented would be dead the moment
 * it expired, and review, appeals, manual grading and the removal placeholder
 * all read that column long after the attempt.
 */
final class SignedMedia
{
    /** Long enough for a slow reader on one screen, short enough to be a link. */
    public const TTL_SECONDS = 3600;

    /** The field a media reference is stored under, throughout the catalogue. */
    public const REF_KEY = 'storage_ref';

    /** The field the signed URL is written to beside it. */
    public const URL_KEY = 'url';

    public function __construct(private readonly ?StoragePort $storage = null)
    {
    }

    /**
     * One signed, expiring URL for a stored ref, or null when this build has
     * no storage port.
     *
     * The grading queue needs it for the learner's own submitted files: a
     * grader asked to mark an upload they cannot open cannot mark it, and the
     * private path reaching the browser as plain text is the leak the seam
     * exists to prevent. It is a URL for one ref rather than a walk over a
     * projection, because an answer is not a projection and the type declares
     * nothing about its shape.
     */
    public function signedUrlFor(string $ref): ?string
    {
        if ($this->storage === null || $ref === '') {
            return null;
        }

        return $this->storage->signedUrl($ref, self::TTL_SECONDS);
    }
    /**
     * The projection with every ref the type declares replaced by a signed
     * URL. A type that declares none is returned untouched.
     *
     * @param array<string, mixed> $presented
     *
     * @return array<string, mixed>
     */
    public function inProjection(ResolvedQuestionType $resolved, array $presented): array
    {
        $storage = $this->storage;
        $provider = $resolved->provider;

        if ($storage === null || !$resolved->isAvailable() || !$provider instanceof MediaBearingInterface) {
            return $presented;
        }

        foreach ($provider->mediaRefs($presented) as $path => $ref) {
            if ($ref === '') {
                continue;
            }

            // A definition's media reference is author-supplied and the schema
            // constrains it only to be a non-empty string, so an authored item
            // naming another course's export, an assignment submission or a
            // learner's artifact would have been signed into a URL for anybody
            // served the question. A reference outside authored course media is
            // removed rather than signed.
            //
            // The ref itself is removed either way, not merely accompanied: it
            // is the private path, and a payload that carries both has
            // published it.
            $presented = self::replaceAt(
                $presented,
                explode('.', $path),
                MediaRef::isAuthored($ref) ? $storage->signedUrl($ref, self::TTL_SECONDS) : null,
            );
        }

        return $presented;
    }

    /**
     * The same substitution for a stimulus media document, which the core
     * owns rather than a provider: a ref becomes a signed url beside it and
     * the ref itself never leaves the server.
     *
     * @param array<string, mixed>|null $media
     *
     * @return array<string, mixed>|null
     */
    public function inStimulusMedia(?array $media): ?array
    {
        if ($media === null || $this->storage === null) {
            return $media;
        }

        $signed = self::signRefs($media, $this->storage);

        /** @var array<string, mixed> */
        return $signed;
    }

    /**
     * @param array<array-key, mixed> $node
     *
     * @return array<array-key, mixed>
     */
    private static function signRefs(array $node, StoragePort $storage): array
    {
        $ref = $node[self::REF_KEY] ?? null;

        if (\is_string($ref) && $ref !== '') {
            if (MediaRef::isAuthored($ref)) {
                $node[self::URL_KEY] = $storage->signedUrl($ref, self::TTL_SECONDS);
            }

            unset($node[self::REF_KEY]);
        }

        foreach ($node as $key => $value) {
            if (\is_array($value)) {
                $node[$key] = self::signRefs($value, $storage);
            }
        }

        return $node;
    }

    /**
     * Drops the field the path names and writes the signed URL to a sibling
     * named "url", so a projection never carries both.
     *
     * A refused reference writes no url at all: the field goes and nothing
     * takes its place, so the client renders the item without the medium and
     * the private path never travels.
     *
     * @param array<string, mixed> $node
     * @param list<string>         $path
     *
     * @return array<string, mixed>
     */
    private static function replaceAt(array $node, array $path, ?string $value): array
    {
        $head = array_shift($path);

        if ($head === null) {
            return $node;
        }

        if ($path === []) {
            unset($node[$head]);

            if ($value !== null) {
                $node[self::URL_KEY] = $value;
            }

            return $node;
        }

        $child = $node[$head] ?? null;

        if (!\is_array($child)) {
            return $node;
        }

        /** @var array<string, mixed> $child */
        $node[$head] = self::replaceAt($child, $path, $value);

        return $node;
    }
}
