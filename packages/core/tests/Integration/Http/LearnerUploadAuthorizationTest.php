<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

final class LearnerUploadAuthorizationTest extends TestCase
{
    public function testRevokedEntitlementCannotUploadMoreLearnerWork(): void
    {
        $fixture = ManagementFixture::create();
        $upload = $fixture->kernel->authoriseUpload;
        $upload->execute($fixture->learner(), 'assignment');
        $fixture->env->database->update('entitlements', ['status' => 'revoked'], ['id' => $fixture->entitlementId]);

        $this->expectException(NotAuthorizedException::class);
        $upload->execute($fixture->learner(), 'attempt_artifact');
    }

    public function testLoginWithoutEnrolmentDoesNotGrantUploadStorage(): void
    {
        $fixture = ManagementFixture::create();
        $this->expectException(NotAuthorizedException::class);
        $fixture->kernel->authoriseUpload->execute(Principal::user(900), 'assignment');
    }
}
