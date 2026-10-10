<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\FieldDisclosure;

use Shondalai\VidyaLMS\Core\Registry\Context\FieldDisclosureContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Which columns an organisation-scoped report or export may carry.
 *
 * It sits beside the cell suppression policy in the same pipeline, and its
 * key and version ride inside the OrgScope digest so a reader allowed more
 * columns cannot be served an artefact computed for a reader allowed
 * fewer.
 *
 * Miss direction: fail closed: the field is omitted.
 *
 * The shape is completed here rather than in the wave that seated the seam,
 * under the rule that seating stated: nothing implemented it, so it could still
 * be corrected, and after the first provider ships it may never gain a method.
 * {@see \Shondalai\VidyaLMS\Core\Provider\FieldDisclosure\OrgMinimalDisclosure}
 * is that first provider, so this is the last edit this interface takes.
 *
 * **A policy is asked per field and answers for one field.** The design's triple
 * is (org role, field, purpose) and a policy that answered with a list of
 * allowed fields would have to know every column of every report ever
 * registered, including the ones a third-party package adds, which is the God
 * object the brief forbids wearing a policy's name.
 */
interface FieldDisclosurePolicyInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Whether this reader may see this field, for this purpose, here.
     *
     * @return string one of the three {@see FieldDisclosureDecision} words
     */
    public function decide(FieldDisclosureContext $context): string;
}
