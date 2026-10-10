<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Catalogue;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgCatalogueContext;

/**
 * One course an organisation reaches through one catalogue entry.
 *
 * This is the whole emit shape of an {@see
 * \Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceInterface}
 * provider, and it is deliberately a record rather than an action. The design
 * says a provider may call `GrantEntitlement` and `RevokeEntitlementBySourceRef`
 * and nothing else; emitting a record instead is stricter, because a provider
 * that holds neither collaborator cannot call anything at all, and the one
 * caller that does grant is the core's own catalogue service, which runs
 * `CourseReleasePolicy` over every emitted course before it grants.
 *
 * The entry, the source key and the grant mode are copied off the context by
 * the named factory rather than passed in, so a provider cannot emit a grant
 * naming another entry, another source or a mode the entry did not carry. What
 * a provider chooses is the course and nothing else.
 */
final class CourseGrant
{
    private function __construct(
        public readonly int $courseId,
        public readonly int $entryId,
        public readonly string $sourceKey,
        public readonly string $grantMode,
        /** The unit whose reach produced this course, when a subtree source resolved it. */
        public readonly ?int $viaUnitId,
    ) {
    }

    /**
     * The grant this entry produced for one course.
     *
     * `$viaUnitId` is provenance and never authority: it says which unit in the
     * entry's own subtree carried the course, so an administrator reading the
     * resolved list can see why a course is there. It is bounded by the
     * subtree the context already resolved, so a provider cannot name a unit
     * outside this organisation through it.
     */
    public static function forEntry(OrgCatalogueContext $context, int $courseId, ?int $viaUnitId = null): self
    {
        return new self(
            $courseId,
            $context->entryId,
            $context->sourceKey,
            $context->grantMode,
            $viaUnitId !== null && \in_array($viaUnitId, $context->unitSubtree, true) ? $viaUnitId : null,
        );
    }

    /**
     * The source ref this grant is written under.
     *
     * The pattern the design fixes for the `course` source, applied to every
     * source, because a ref that changes shape per provider is a ref no
     * revocation sweep can parse. `{grantRef}` is the caller's own suffix: the
     * seat work already keys refs this way and a revocation reads the prefix.
     */
    public function sourceRef(int $userId, string $grantRef): string
    {
        return sprintf(
            'orgcat-entry-%d-user-%d-course-%d-%s',
            $this->entryId,
            $userId,
            $this->courseId,
            $grantRef,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'course_id' => $this->courseId,
            'entry_id' => $this->entryId,
            'source_key' => $this->sourceKey,
            'grant_mode' => $this->grantMode,
            'via_unit_id' => $this->viaUnitId,
        ];
    }
}
