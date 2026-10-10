<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Audience;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;

/**
 * @extends AbstractProviderRegistry<AudienceResolverInterface>
 */
final class AudienceResolverRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'audience';

    /** The reason a rule counts when its audience does not resolve. */
    public const MISS_REASON = 'audience_missing';

    /**
     * The audience is registered and needed an organisation scope the run did
     * not carry, so it resolved to nobody.
     *
     * It exists because MISS_REASON was covering both, and the two are
     * opposite facts. An administrator wiring an escalation to org_admins was
     * told the audience was missing, which sends them looking for an
     * uninstalled package, when the provider is present, resolved correctly,
     * and answered nobody because the fan-out that asked it had no tenant.
     */
    public const UNSCOPED_REASON = 'audience_unscoped';

    /**
     * The run is organisation-scoped and the resolver does not declare
     * {@see \Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope}.
     *
     * This is the refusal four docblocks published and nothing implemented.
     * FiltersByVisibility carries no method, so a provider written before
     * organisations existed cannot have honoured a scope kind that did not
     * exist, and using it inside an organisational run would be exactly the
     * site-wide broadcast the tenancy rule exists to prevent.
     */
    public const SCOPE_UNSUPPORTED_REASON = 'audience_scope_unsupported';

    protected function providerInterface(): string
    {
        return AudienceResolverInterface::class;
    }

    /**
     * The reason a scoped run must not use this resolver, or null.
     *
     * It is here rather than inline in the fan-out because the refusal is a
     * property of the pairing and not of one caller: four docblocks published
     * it, nothing implemented it, and the second caller would have written it
     * again. A predicate that any scoped run can ask is what makes it a rule.
     *
     * The check is instanceof and never a method on the shipped interface.
     * FiltersByVisibility carries no method at all, so a provider written
     * before organisations existed cannot have honoured a scope kind that did
     * not exist, and inheriting a promise about one is exactly the widening
     * the tenancy rule refuses.
     */
    public static function refusalFor(AudienceResolverInterface $resolver, AudienceContext $context): ?string
    {
        if ($context->visibilityScope?->isOrganisational() !== true) {
            return null;
        }

        return $resolver instanceof HonoursOrgScope ? null : self::SCOPE_UNSUPPORTED_REASON;
    }

    /**
     * Why a registered resolver answered nobody.
     *
     * A resolver that needs a tenant and was handed none is not a missing
     * audience, and reporting it as one sends an administrator looking for a
     * package that is already installed.
     */
    public static function emptyReasonFor(AudienceResolverInterface $resolver, AudienceContext $context): string
    {
        return $resolver instanceof HonoursOrgScope && $context->tenant() === null
            ? self::UNSCOPED_REASON
            : self::MISS_REASON;
    }
}
