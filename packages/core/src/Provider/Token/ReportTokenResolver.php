<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * The report run a delivery message is about.
 *
 * `NotificationLinkDelivery` seeds a template whose subject and body carry
 * `{report.key}`, `{report.row_count}`, `{report.url}` and
 * `{report.url_expires_at}`, and puts exactly those names in the queued
 * parameter bag. `NotificationRenderer` resolves a dotted placeholder through
 * this registry and never from the bag directly, so with no provider owning
 * the prefix every scheduled report email left the site with its placeholders
 * still in it: the subject read "Your {report.key} report is ready" and the
 * body offered a download link spelled "{report.url}".
 *
 * That was not a rendering bug and it was not a template bug. It was the
 * free-provider rule deferred: the prefix had a caller, a template and a
 * parameter bag, and no registration. The Pro attachment delivery publishes
 * the same four names, so one provider answers both and a paid package adds
 * nothing.
 *
 * Every name is read from the bag under its own dotted spelling, because that
 * is what both deliveries write. A token the bag does not carry answers null,
 * which leaves the placeholder visible rather than emptying it: a message that
 * shows a missing value is one somebody reports, and a message that quietly
 * offers an empty link is one nobody does.
 */
final class ReportTokenResolver implements TokenResolverInterface
{
    public const PREFIX = 'report';

    public function key(): string
    {
        return self::PREFIX;
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function label(): string
    {
        return 'vidyalms.token.report';
    }

    public function tokens(): array
    {
        return [
            'report.key',
            'report.format',
            'report.row_count',
            'report.url',
            'report.url_expires_at',
            'report.expires_at',
        ];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        return \in_array($token, $this->tokens(), true) ? $context->string($token) : null;
    }
}
