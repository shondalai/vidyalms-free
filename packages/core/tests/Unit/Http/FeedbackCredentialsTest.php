<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\FeedbackCredentials;

final class FeedbackCredentialsTest extends TestCase
{
    public function testDedicatedBasicCredentialsAreSupportedWithoutTreatingOtherAuthorizationAsASecret(): void
    {
        self::assertSame('webhook-key', FeedbackCredentials::secret('', 'Basic ' . base64_encode('vidyalms:webhook-key')));
        self::assertSame('', FeedbackCredentials::secret('', 'Basic ' . base64_encode('admin:account-password')));
        self::assertSame('', FeedbackCredentials::secret('', 'Bearer private-account-token'));
        self::assertSame('', FeedbackCredentials::secret('', 'Basic !!!!'));
        self::assertSame('explicit', FeedbackCredentials::secret('explicit', 'Basic ' . base64_encode('vidyalms:other')));
    }
}
