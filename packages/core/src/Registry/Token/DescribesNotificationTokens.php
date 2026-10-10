<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Token;

/**
 * Optional notification-authoring metadata for a token family.
 *
 * Default when absent: every token is text. That keeps existing and
 * third-party resolvers compatible while making URL placement an explicit
 * opt-in by the provider that knows the value's semantics.
 */
interface DescribesNotificationTokens
{
    /**
     * @return array<string, NotificationTokenType> fully-qualified token to type
     */
    public function notificationTokenTypes(): array;
}
