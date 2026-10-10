<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

/** A domain-separated authenticated cipher keyed outside the application DB. */
final class InvitationSecretBox
{
    public function __construct(private readonly string $hostSecret)
    {
    }

    public function available(): bool
    {
        return strlen($this->hostSecret) >= 32 && function_exists('sodium_crypto_secretbox');
    }

    public function seal(string $plain): string
    {
        $key = $this->key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
    }

    public function open(string $sealed): string
    {
        $key = $this->key();
        $bytes = base64_decode($sealed, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Protected notification payload is unavailable.');
        }
        $nonce = substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
        if ($plain === false) {
            throw new \RuntimeException('Protected notification payload could not be authenticated.');
        }
        return $plain;
    }

    private function key(): string
    {
        if (!$this->available()) {
            throw new \RuntimeException('Protected notification delivery requires a managed host secret and Sodium.');
        }
        return hash_hkdf('sha256', $this->hostSecret, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'vidyalms.org-notification.v1');
    }
}
