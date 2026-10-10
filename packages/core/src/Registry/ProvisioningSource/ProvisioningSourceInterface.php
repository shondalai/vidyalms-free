<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ProvisioningSource;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Where a membership came from, as one vocabulary shared by the console,
 * the invitation flow, the importer and every live directory.
 *
 * One registry rather than four, so org_memberships.provisioned_by has a
 * single provenance vocabulary. A source that can be swept incrementally
 * declares SupportsDelta; one that governs its rows declares
 * NamesGoverningSource and the console renders them read-only.
 *
 * **It is a separate registry from Importer and it stays separate.** An
 * importer reads a file an operator uploaded, once, and finishes. A
 * provisioning source is a standing relationship: it has a watermark, it runs
 * again tomorrow without anybody asking, it may govern the rows it wrote, and
 * it has an opinion about what happens when a person edits a field it owns.
 * Overloading one interface with both would give every file importer three
 * methods it has no answer for, and would make "is this row governed by
 * somewhere else" a question with two homes.
 *
 * **This is provenance, not authorisation.** A source says where a membership
 * came from; it never decides what the person may do. Creation and lifecycle
 * are two different jobs and neither of them sets a capability: an identity
 * provider names a group, an administrator-authored rule names a role, and no
 * source anywhere in this product turns the first into the second.
 *
 * Miss direction: fail closed: the write is refused and the source is named.
 */
interface ProvisioningSourceInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Whether this source keeps asserting, or fires once and finishes.
     *
     * The difference is what the console tells a customer about offboarding. A
     * source that creates and never asserts again cannot close a membership or
     * release a seat when somebody leaves, and an organisation running only
     * such sources is told so rather than discovering it when a seat pool
     * never frees up.
     */
    public function isContinuous(): bool;

    /**
     * What happens when this source and a person disagree about a field.
     *
     * One of FieldConflictPolicy's words. It is on the interface rather than
     * on a capability because every source has an answer, including "I do not
     * govern anything, so a person's edit stands", and a provenance with no
     * stated conflict policy is one whose behaviour is discovered the first
     * time an administrator's correction is silently overwritten.
     */
    public function conflictPolicy(): string;
}
