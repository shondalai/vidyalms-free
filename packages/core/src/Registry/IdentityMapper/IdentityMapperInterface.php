<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\IdentityMapper;

use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Registry\Context\IdentityAssertionContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A dialect that turns a host or protocol assertion into the canonical
 * AssertedIdentity, and touches nothing else.
 *
 * Keyed on the dialect alone, because an identity mapper reads in one
 * direction only. The anti-corruption rule is the point: no protocol word
 * crosses into the core past this seam. A mapper is handed an opaque bag by
 * whatever terminated the protocol, and hands back the core's own vocabulary;
 * nothing downstream can tell which dialect produced it, and nothing upstream
 * can reach a use case without going through here.
 *
 * The two hosts are genuinely different and this interface says so rather than
 * pretending. One CMS's authentication response has a fixed set of properties
 * and carries neither arbitrary attributes nor group memberships, so a seam
 * demanding raw attributes would be unimplementable there in every edition;
 * the other fires hooks carrying whatever the protocol package captured. The
 * bag is therefore whatever the host could supply, and the mapper is the thing
 * that knows what to do with each shape.
 *
 * Miss direction: normal path with a named direction: the assertion is
 * ignored, one degradation is logged, and no membership is ever removed.
 * That is the only safe reading of "nobody here understands this dialect": an
 * assertion nobody could read is not evidence that anybody left.
 */
interface IdentityMapperInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * The completeness every assertion this dialect produces carries, before
     * any per-assertion evidence narrows it.
     *
     * It is declared on the mapper rather than inferred per assertion because
     * it is a property of the dialect: a host group read knows every group a
     * person is in, and a token whose group claim is capped does not. A mapper
     * that declares complete and then produces a truncated assertion has told
     * a lie the removal guard will act on, which is why the declaration is
     * here where a reviewer can see it beside the code that produces the
     * groups.
     *
     * One of AssertedCompleteness's two words.
     */
    public function completeness(): string;

    /**
     * Reads one assertion, or answers null.
     *
     * Null means this mapper could not read this bag, which is not a failure
     * and is never an emptiness: a mapper handed a shape it does not
     * recognise says so, and the caller records a degradation and removes
     * nothing.
     */
    public function map(IdentityAssertionContext $context): ?AssertedIdentity;
}
