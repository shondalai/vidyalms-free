<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink;

final class PublicLinkTest extends TestCase
{
    public function testQueryRoutesKeepTheCodeInTheNamedParameter(): void
    {
        foreach (['verify' => 'code', 'unsubscribe' => 'token'] as $view => $parameter) {
            $url = PublicLink::append('https://example.test/sub/index.php?option=com_vidyalms&view=' . $view . '&' . $parameter . '={value}', 'abc+/=?');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('com_vidyalms', $query['option']);
            self::assertSame($view, $query['view']);
            self::assertSame('abc+/=?', $query[$parameter]);
        }
        self::assertSame('https://example.test/verify/a%2Fb', PublicLink::append('https://example.test/verify', 'a/b'));
    }
}
