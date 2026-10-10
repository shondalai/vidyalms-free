<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\CoreNotificationBlueprints;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * A blueprint's copy and its version move together, or the correction is lost.
 *
 * The failure this holds shut already happened. R0 removed an em-dash from the
 * vidyalms.availability.unlocked paragraph and left the version at 1, so the
 * source scan reported [OK] while both development sites kept sending the
 * dashed sentence: NotificationTemplateStudio offers an update only where the
 * published blueprint version is below the registration's, and a seeded row
 * keeps the copy it was seeded with for ever otherwise. A source-only fix
 * looks complete and reaches nobody.
 *
 * So the digest is recorded here beside the version. Editing a word fails this
 * test, and the way to make it pass is to raise that blueprint's version,
 * which is the thing that actually reaches a site.
 */
#[CoversClass(CoreNotificationBlueprints::class)]
final class BlueprintVersionDriftTest extends TestCase
{
    /**
     * templateKey => [declared version, sha256 of the blueprint source].
     *
     * @var array<string, array{int, string}>
     */
    private const RECORDED = [
        'vidyalms.mail.enrolled' => [1, 'ce080cd829019b686c7da809773d991b2633f3275109d418af0f795171ce4b7e'],
        'vidyalms.mail.course_completed' => [1, '6e6fedc05123ef97d73b80f9a77f9f111b5bdde32debf33d469313116ff94358'],
        'vidyalms.mail.certificate_issued' => [1, '0bfccb9950ad7019570132e0702dcc16d2fbcec8f9238932c47d66e0628facb6'],
        'vidyalms.certificate.reissued' => [1, '40f3f603f9e64aac5b80578f119b6c2a13d34733785f1540b5a7a8069b464aa1'],
        'vidyalms.mail.submission_received' => [1, '08313c9427f42adc772bbd8305f3edde4aad50786617ca7ce0372ddf98f84cbd'],
        'vidyalms.mail.attempt_graded' => [1, '31b6b5e115a804e6a7e4e2ea28bed910e2c3c173ac89c963ce5487e8e11c6aa6'],
        'vidyalms.availability.unlocked' => [2, 'd1b4c082f7380390cdd8aa98b0d6813197a0d7bbe1e9f46ab1d1a0e3d65f9c24'],
        'vidyalms.sweep.entitlement_expiring' => [1, 'f381b2cc461994725fda2f756d912fa542c0fa5db0c5bb315fbec42c50ad22e4'],
        // The fallback every unnamed key resolves to.
        'vidyalms.generic.example' => [1, '26552717ac66e7216e4042d1d921ad21f80749bd62e61f35de7c85e0217457a8'],
    ];

    public function testEveryRecordedBlueprintStillCarriesItsRecordedCopyAndVersion(): void
    {
        foreach (self::RECORDED as $templateKey => [$version, $digest]) {
            $blueprint = CoreNotificationBlueprints::for($templateKey, NotificationClass::Transactional);

            self::assertSame(
                $digest,
                hash('sha256', json_encode($blueprint->source, JSON_THROW_ON_ERROR)),
                $templateKey . ': the copy changed. Raise its version in CoreNotificationBlueprints and'
                . ' record the new digest here, or a seeded site never receives the correction.',
            );
            self::assertSame($version, $blueprint->version, $templateKey . ': the declared version changed.');
        }
    }

    /**
     * The corrected paragraph carries a version a seeded site is below, which
     * is what turns update_available true on the studio row.
     */
    public function testTheDashCorrectionIsOfferedToSitesSeededBeforeIt(): void
    {
        $blueprint = CoreNotificationBlueprints::for('vidyalms.availability.unlocked', NotificationClass::Transactional);

        self::assertGreaterThan(1, $blueprint->version);
        self::assertContains(
            'Continue when the moment feels right, your progress is waiting for you.',
            $blueprint->source['paragraphs'],
        );
    }
}
