<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

/**
 * Identifiers and verification codes from a cryptographically secure source.
 *
 * Certificate codes are read aloud and typed in by hand, so the alphabet drops
 * the pairs that get confused: O and 0, I and 1.
 *
 * @since 1.0.0
 */
final class JoomlaRandom implements RandomPort
{
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public function uuid(): string
    {
        $bytes = random_bytes(16);

        // RFC 4122 version 4, variant 10xx.
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function verificationCode(int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('A verification code needs at least one character.');
        }

        $alphabet = self::CODE_ALPHABET;
        $bound = \strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $bound)];
        }

        return $code;
    }
}
