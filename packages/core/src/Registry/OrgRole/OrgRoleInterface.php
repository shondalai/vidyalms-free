<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgRole;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A role held inside one organisation, registered rather than enumerated.
 *
 * IOMAD states flatly that new such roles cannot be added and fixes three by
 * the product. That is the fork the extensibility rule forbids, so here a new
 * org role is one provider class in a package registered at KernelReady, with
 * no core edit at all.
 *
 * Two rules are stated here rather than in a document, because this is where
 * the author of a third-party role is working.
 *
 * **A role narrows and can never grant.** capabilities() is intersected with
 * what AuthorizationPort answers for that person, so a role whose
 * capabilities() names site administration grants nothing to anyone the host
 * has not already made a site administrator. The consequence is stated too: a
 * person given a role by a directory push holds no host capability, their
 * intersection is empty, and the console shows the assignment as granted and
 * inactive rather than pretending it works. The core never writes host group
 * membership.
 *
 * **A caller may assign only roles whose rights and capabilities are a subset
 * of their own.** Assigning a role that carries any right at all is itself the
 * "roles" right, and the subset is compared over actions() and over the
 * intersected capability set. Without it a roster column naming a higher role
 * is a vertical privilege escalation with a spreadsheet for a weapon.
 *
 * Miss direction: fail closed. The membership row survives, the person
 * resolves to no capabilities at all, and the console names the missing
 * provider rather than showing a member with no role and no reason.
 */
interface OrgRoleInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * The host capabilities this role operates under, drawn from the shipped
     * Capability constants and nothing else.
     *
     * A capability not in that vocabulary is invisible in Joomla's permissions
     * UI, sits on no WordPress role, and can therefore be granted to nobody
     * who is not already a site manager. Naming one here would be a seam that
     * looks like a grant and is not.
     *
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * The rights this role permits inside the resolved scope.
     *
     * This is the enumerable form of permits(). It exists because the shipped
     * action vocabulary used to be a literal list repeated in two files, which
     * meant a registered role could neither introduce an action nor be granted
     * one. The union over every registered provider is the site's vocabulary.
     *
     * @return list<string>
     */
    public function actions(): array;

    /** Whether this role permits one right; the same answer actions() lists. */
    public function permits(string $action): bool;

    /**
     * Whether a directory mapping rule may grant this role.
     *
     * An identity provider group never names a product role by itself; an
     * administrator-authored rule does, and a role may refuse to be one of the
     * roles such a rule can name.
     */
    public function grantableByMappingRule(): bool;

    /**
     * The JSON Schema of this role's stored assignment payload, unwrapped.
     *
     * StoredShape adds the reserved version key on write and strips it on
     * read, so a provider never sees it and none may declare one.
     *
     * **It was declared ahead of its column and the column has since
     * arrived.** org_role_assignments now carries params with params_version
     * mirrored beside it, added by the wave that shipped the first
     * parameterised role, which is what the earlier text here said that wave
     * would do. A payload is validated against this schema at save, wrapped by
     * StoredShape on write and unwrapped on read, so a provider never handles
     * the envelope.
     *
     * A role that takes no parameters returns the closed empty schema the free
     * four return and versions it 1, and its assignments store a null params.
     * Both methods stay required, because this interface may never gain a
     * method after its first third-party provider ships.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;

    /** The payload version paramsSchema() describes, from one on first release. */
    public function currentShape(): int;

    /**
     * The family this role belongs to in a picker.
     *
     * Declared rather than derived from registration order, for the reason
     * AbstractProviderRegistry::replace() gives: a decorated provider keeps its
     * position, which is right for iteration and wrong for a list a person
     * reads, because it would pin the built-in roles above every later family.
     */
    public function group(): string;

    /** The position inside that family; ties break on the key. */
    public function order(): int;
}
