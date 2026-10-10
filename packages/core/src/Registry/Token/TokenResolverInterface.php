<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Token;

use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One placeholder family, owned by its prefix.
 *
 * The registry is shared by the notification templates and the certificate
 * designer, so an author learns one placeholder language and a package that
 * adds a cohort placeholder adds it to both at once.
 *
 * There is deliberately no general template engine behind this. Admin
 * editable bodies rendered through Twig or Blade on a shared-hosting CMS is a
 * server-side template injection surface, so the vocabulary is closed and
 * resolved by these providers, and the admin insert menu reads what the
 * trigger declares.
 *
 * The miss direction is published: an unresolved token is left intact in the
 * output rather than blanked, because a visible placeholder is a bug report
 * and a blank is a silent one.
 */
interface TokenResolverInterface extends ProviderInterface
{
    /**
     * The prefix this provider owns, without the dot: "user", "course",
     * "site". The key of the provider is the same string, so two packages
     * claiming one prefix fail loudly at registration.
     */
    public function prefix(): string;

    public function label(): string;

    /**
     * The full placeholder names this provider answers, without braces, each
     * beginning with its prefix. The insert menu and the save-time
     * placeholder validation both read this.
     *
     * @return list<string>
     */
    public function tokens(): array;

    /**
     * The value for one placeholder, or null when this resolver holds no
     * value for it in this context, which leaves the placeholder intact.
     */
    public function resolve(string $token, TokenContext $context): ?string;
}
