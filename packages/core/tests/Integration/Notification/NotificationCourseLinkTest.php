<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Links\CourseLinkPort;
use Shondalai\VidyaLMS\Core\Provider\Token\CourseTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

final class NotificationCourseLinkTest extends TestCase
{
    public function testHostOwnsTheCourseLinkEvenWhenTheIssuerUsesAnotherDomain(): void
    {
        $fixture = ManagementFixture::create();
        $links = new class () implements CourseLinkPort {
            /** @var list<array{int,string}> */
            public array $calls = [];
            public function publicUrl(int $courseId, string $slug): string
            {
                $this->calls[] = [$courseId, $slug];
                return 'https://host.example.test/subdir/index.php?option=com_vidyalms&view=course&slug=' . rawurlencode($slug);
            }
        };
        $resolver = new CourseTokenResolver($fixture->env->database, 'https://issuer.example.test', links: $links);
        $url = $resolver->resolve('course.url', TokenContext::create(courseId: $fixture->courseId));
        self::assertNotNull($url);
        self::assertStringStartsWith('https://host.example.test/subdir/index.php?', $url);
        self::assertStringNotContainsString('issuer.example.test', $url);
        self::assertSame($fixture->courseId, $links->calls[0][0]);
        self::assertNotSame('', $links->calls[0][1]);
    }

    public function testUnresolvableHostLinkDoesNotInventAProbablyBrokenPrettyRoute(): void
    {
        $fixture = ManagementFixture::create();
        $links = new class () implements CourseLinkPort {
            public function publicUrl(int $courseId, string $slug): ?string
            {
                return null;
            }
        };
        $resolver = new CourseTokenResolver($fixture->env->database, 'https://issuer.example.test', links: $links);
        self::assertNull($resolver->resolve('course.url', TokenContext::create(courseId: $fixture->courseId)));
    }
}
