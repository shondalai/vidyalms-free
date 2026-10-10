<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Notification;

use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\DescribesNotificationTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

final class CompilerTokenResolver implements TokenResolverInterface, DescribesNotificationTokens
{
    /** @param array<string, NotificationTokenType> $types */
    public function __construct(
        private readonly string $providerPrefix,
        private readonly array $types,
    ) {
    }

    public function key(): string
    {
        return $this->providerPrefix;
    }

    public function prefix(): string
    {
        return $this->providerPrefix;
    }

    public function label(): string
    {
        return ucfirst($this->providerPrefix);
    }

    public function tokens(): array
    {
        return array_keys($this->types);
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        return $context->string($token);
    }

    public function notificationTokenTypes(): array
    {
        return $this->types;
    }
}
