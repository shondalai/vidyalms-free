<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\DescribesNotificationTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * A credential's public identity, composed rather than stored.
 *
 * The verification URL used to be frozen onto the queue row at enqueue, so a
 * live credential URL sat in a terminal row for the life of the site with no
 * reason to be there. It is composed here instead, from the code the message
 * already names and the site's own verification base, which is also what the
 * render path does: one composition rather than two that can disagree.
 *
 * The code is stored flat and displayed grouped. CODE_LENGTH does not change:
 * twelve characters over the unambiguous 32-symbol alphabet is about sixty
 * bits against a public endpoint, codes are already printed on certificates
 * and quoted in mail, and a display grouping plus a normaliser that strips
 * prefix, hyphens, spaces and case on lookup gets the readable form with
 * nothing invalidated. Lookup accepts the code with or without separators,
 * because people retype what they see.
 *
 * An unresolved placeholder is left intact rather than blanked, which is the
 * published miss direction for a token.
 */
final class CertificateTokenResolver implements TokenResolverInterface, DescribesNotificationTokens
{
    public const PREFIX = 'certificate';

    /** Where the display grouping falls, as Moodle and Credly both group. */
    private const DISPLAY_GROUP = 4;

    public function __construct(
        private readonly string $verificationBaseUrl = '',
        private readonly string $codePrefix = '',
        private readonly string $credentialBaseUrl = '',
    ) {
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
        return 'vidyalms.token.certificate';
    }

    public function tokens(): array
    {
        return [
            'certificate.code',
            'certificate.code_display',
            'certificate.issued_at',
            'certificate.completed_at',
            'certificate.expires_at',
            'certificate.verification_url',
            'certificate.credential_url',
            'certificate.uuid',
        ];
    }

    public function notificationTokenTypes(): array
    {
        $types = array_fill_keys($this->tokens(), NotificationTokenType::Text);
        $types['certificate.verification_url'] = NotificationTokenType::Url;
        $types['certificate.credential_url'] = NotificationTokenType::Url;

        return $types;
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        $code = $context->string('code') ?? $context->string('verification_code');

        $value = match ($token) {
            'certificate.code' => $code,
            'certificate.code_display' => $code === null ? null : $this->display($code),
            'certificate.issued_at' => $context->string('issued_at'),
            'certificate.completed_at' => $context->string('completed_at') ?? $context->string('issued_at'),
            'certificate.expires_at' => $context->string('expires_at'),
            'certificate.uuid' => $context->string('uuid'),
            'certificate.verification_url' => $code === null || $this->verificationBaseUrl === ''
                ? null
                : \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append($this->verificationBaseUrl, $code),
            'certificate.credential_url' => $this->credentialUrl($context),
            default => null,
        };

        return $value === '' ? null : $value;
    }

    /**
     * The stored code, grouped for reading, with the site's optional prefix.
     * Storage stays flat and case insensitive: this is presentation and
     * nothing else reads it back.
     */
    public function display(string $code): string
    {
        $groups = str_split($code, self::DISPLAY_GROUP);
        $grouped = implode('-', $groups);

        return $this->codePrefix === '' ? $grouped : $this->codePrefix . '-' . $grouped;
    }

    /**
     * The reverse: strip prefix, separators and case so a person who retypes
     * what they read finds the certificate they are holding.
     */
    public static function normalise(string $input): string
    {
        $bare = preg_replace('/[^A-Za-z0-9]/', '', $input) ?? $input;
        $upper = strtoupper($bare);

        // A site prefix is letters too, so it cannot be stripped by pattern.
        // The code is fixed length, so the tail is the code and anything in
        // front of it is decoration.
        return \strlen($upper) > 12 ? substr($upper, -12) : $upper;
    }

    private function credentialUrl(TokenContext $context): ?string
    {
        $uuid = $context->string('uuid');

        if ($uuid === null || $uuid === '' || $this->credentialBaseUrl === '') {
            return null;
        }

        return rtrim($this->credentialBaseUrl, '/') . '/' . rawurlencode($uuid);
    }
}
