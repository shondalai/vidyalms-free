<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\DeclaresUnfrozenTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * The recipient's own details.
 *
 * The first name is derived rather than stored, because the people port
 * supplies one display name and asking every host to split it would be a
 * port change for a nicety.
 */
final class UserTokenResolver implements TokenResolverInterface, DeclaresUnfrozenTokens
{
    public const PREFIX = 'user';

    public function __construct(private readonly UsersPort $users)
    {
    }

    /**
     * The unmasked address belongs to the mail surface, where the recipient
     * is the addressee. On a credential it would sit in context_snapshot for
     * the retention life of the row and be copied verbatim into every
     * reissue, which the design's own user token family for a credential
     * excludes: first name, last name, full name and masked address.
     *
     * The family declares it rather than core listing it, so a package
     * registering an eighth family can keep its own plaintext identifier out
     * of the freeze without a core edit.
     */
    public function neverFrozen(): array
    {
        return ['user.email'];
    }

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
        return 'vidyalms.token.user';
    }

    public function tokens(): array
    {
        return [
            'user.name',
            'user.full_name',
            'user.first_name',
            'user.last_name',
            'user.email',
            'user.email_masked',
            'user.id',
        ];
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        $userId = $context->recipientUserId;

        if ($userId === null || $userId < 1) {
            return null;
        }

        if ($token === 'user.id') {
            return (string) $userId;
        }

        // The address is never frozen on the queue row, so it is always read
        // from the port here; the display name may be, and the frozen value
        // wins because it is what the message said when it was written.
        $subjectUserId = (int) ($context->parameters['user_id'] ?? $userId);
        $name = $subjectUserId === $userId ? $context->string('learner_name') : null;
        $email = null;

        if ($name === null || $token === 'user.email' || $token === 'user.email_masked') {
            $user = $this->users->findById($userId);
            $name ??= $user->name ?? null;
            $email = $user->email ?? null;
        }

        return match ($token) {
            'user.name', 'user.full_name' => $name,
            'user.email' => $email,
            // What a certificate may carry. A public verification page and a
            // shareable credential must never publish an address, and an
            // author who wants to show one anyway should have to reach for
            // the unmasked token deliberately.
            'user.email_masked' => $email === null ? null : $this->maskedAddress($email),
            'user.first_name' => $name === null ? null : $this->firstNameOf($name),
            'user.last_name' => $name === null ? null : $this->lastNameOf($name),
            default => null,
        };
    }

    private function firstNameOf(string $name): string
    {
        $trimmed = trim($name);
        $space = strpos($trimmed, ' ');

        return $space === false ? $trimmed : substr($trimmed, 0, $space);
    }

    /**
     * Everything after the first space, which is as much as one display name
     * can honestly be split into. A host that knows better supplies its own
     * resolver.
     */
    private function lastNameOf(string $name): ?string
    {
        $trimmed = trim($name);
        $space = strrpos($trimmed, ' ');

        return $space === false ? null : trim(substr($trimmed, $space + 1));
    }

    private function maskedAddress(string $email): string
    {
        $at = strpos($email, '@');

        if ($at === false || $at < 1) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        return $local[0] . str_repeat('*', max(1, \strlen($local) - 1)) . $domain;
    }
}
