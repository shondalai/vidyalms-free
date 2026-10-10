<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\Token\UserTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class UserTokenResolverTest extends TestCase
{
    public function testStaffRecipientIsNotAddressedByTheLearnersName(): void
    {
        $env = TestPorts::create();
        $env->users->add(10, 'Tara Teacher', 'tara@example.test');
        $resolver = new UserTokenResolver($env->users);
        $context = TokenContext::create(['user_id' => 50, 'learner_name' => 'Lena Learner'], 'en-GB', 10, null, 'UTC');
        self::assertSame('Tara Teacher', $resolver->resolve('user.name', $context));
        self::assertSame('Tara', $resolver->resolve('user.first_name', $context));
        self::assertSame('tara@example.test', $resolver->resolve('user.email', $context));
    }

    public function testCredentialStillUsesTheLearnersFrozenName(): void
    {
        $env = TestPorts::create();
        $env->users->add(50, 'New Name', 'lena@example.test');
        $resolver = new UserTokenResolver($env->users);
        $context = TokenContext::create(['user_id' => 50, 'learner_name' => 'Lena Learner'], 'en-GB', 50, null, 'UTC');
        self::assertSame('Lena Learner', $resolver->resolve('user.name', $context));
    }
}
