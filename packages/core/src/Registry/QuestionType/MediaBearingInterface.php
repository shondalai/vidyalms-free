<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * Which storage refs a definition holds, and where they sit inside its
 * learner projection.
 *
 * Every media-bearing type in the catalogue specifies "a signed expiring media
 * URL" in its learner projection, minted at read time from the stored ref, and
 * without this there was no interface, no context member and no presenter hook
 * through which a provider could obtain one. That is a hard blocker for
 * select_media, order_media, associate_media, point_media, signal_media,
 * artifact_drawing and artifact_recording at once.
 *
 * The signature is deliberately the projection rather than the definition:
 * a signed URL is not what is frozen on the attempt. attempt_questions.presented
 * freezes refs, because a frozen signature would be dead the moment it expired,
 * which would break review, appeals, manual grading and the removal placeholder
 * on exactly the image-bearing types that need them most. So the type declares
 * where its refs sit and the read path substitutes, once, for every type.
 *
 * The default when a provider does not implement this is that export and media
 * sweeps cannot find its media, and no substitution happens, which is right for
 * every type that carries none.
 */
interface MediaBearingInterface
{
    /**
     * The storage refs a presented projection carries, keyed by their dotted
     * path inside it.
     *
     * A path segment is a key or a list index, so
     * "media.storage_ref" and "regions.0.media.storage_ref" are both valid.
     *
     * **What the read path writes back is the seam's real output contract,
     * and it is not the path this method named.** SignedMedia removes the key
     * the path ends in and writes a sibling named SignedMedia::URL_KEY inside
     * that key's own parent node, so a ref declared at
     * "canvas.background.storage_ref" arrives at the client as
     * "canvas.background.url". A client written against the declared path
     * finds nothing and silently renders no media, which is exactly what
     * happened: the only way to learn the output key was to read a private
     * method in core. tooling/contract/projections.php records the real
     * projection of every registered type so a client reader is written
     * against server output rather than a guess.
     *
     * **Two refs may not share a parent node.** The sibling the signer writes
     * is one fixed key, so two refs directly under one node collapse onto one
     * url and one is lost. A type carrying a medium plus its captions and its
     * transcript nests each in its own node. MediaRefs::in is the published
     * walk that yields every ref at its dotted path.
     *
     * @param array<string, mixed> $presented the learner projection, never the
     *                                        authored definition
     *
     * @return iterable<string, string> path inside the projection => storage ref
     */
    public function mediaRefs(array $presented): iterable;
}
