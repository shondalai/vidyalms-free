<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\DescribesNotificationTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/** Values exist only during protected notification hydration, never a DB lookup. */
final class InvitationTokenResolver implements TokenResolverInterface, DescribesNotificationTokens
{
    public function key(): string
    {
        return 'invitation';
    }

    public function prefix(): string
    {
        return $this->key();
    }

    public function label(): string
    {
        return 'vidyalms.token.invitation';
    }

    public function tokens(): array
    {
        return ['invitation.url'];
    }

    public function notificationTokenTypes(): array
    {
        return ['invitation.url' => NotificationTokenType::Url];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        return $token === 'invitation.url' ? $context->string('invitation_url') : null;
    }
}
