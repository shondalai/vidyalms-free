<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

/** Machine credentials are accepted from transport headers, never JSON fields. */
final class FeedbackCredentials
{
    public static function secret(string $header, string $authorization): string
    {
        if ($header !== '') {
            return trim($header);
        }
        if (strlen($authorization) > 4096 || !preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/i', trim($authorization), $match)) {
            return '';
        }
        $decoded = base64_decode($match[1], true);
        if ($decoded === false || !str_starts_with($decoded, 'vidyalms:')) {
            return '';
        }
        return substr($decoded, strlen('vidyalms:'));
    }
}
