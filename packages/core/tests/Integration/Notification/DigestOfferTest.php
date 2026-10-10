<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * A digest frequency is offered where something folds it, and nowhere else.
 *
 * The free build offered daily and weekly from a hardcoded channel list while
 * every collapse handler shipped in Pro. A learner who chose daily received
 * nothing at all, for ever, and their pending items accumulated with no
 * message to collapse into and nothing anywhere reporting a suppression.
 */
final class DigestOfferTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testAFreeBuildOffersImmediateAndNeverAndNoDigest(): void
    {
        foreach ($this->rows() as $row) {
            self::assertSame(
                [Prefs::FREQUENCY_IMMEDIATE, Prefs::FREQUENCY_NEVER],
                $row['available_frequencies'],
                $row['category_key'] . '|' . $row['channel_key'],
            );
        }
    }

    public function testAFreeBuildRefusesADigestChoiceRatherThanSwallowingIt(): void
    {
        $this->expectException(ValidationException::class);

        $this->fixture->kernel->notificationPreferenceCentre->put($this->fixture->learner(), [[
            'category_key' => 'course_progress',
            'channel_key' => 'email',
            'frequency' => Prefs::FREQUENCY_DAILY,
        ]]);
    }

    public function testARegisteredFoldMakesItsOwnFrequencyOfferable(): void
    {
        $this->fixture->kernel->registries->scheduledTasks->register(new DailyEmailFold());

        foreach ($this->rows() as $row) {
            self::assertSame(
                $row['channel_key'] === 'email'
                    ? [Prefs::FREQUENCY_IMMEDIATE, Prefs::FREQUENCY_DAILY, Prefs::FREQUENCY_NEVER]
                    : [Prefs::FREQUENCY_IMMEDIATE, Prefs::FREQUENCY_NEVER],
                $row['available_frequencies'],
                $row['category_key'] . '|' . $row['channel_key'],
            );
        }

        $saved = $this->fixture->kernel->notificationPreferenceCentre->put($this->fixture->learner(), [[
            'category_key' => 'course_progress',
            'channel_key' => 'email',
            'frequency' => Prefs::FREQUENCY_DAILY,
        ]]);

        self::assertIsArray($saved['rows']);
        self::assertSame(
            Prefs::FREQUENCY_DAILY,
            $this->fixture->kernel->notificationPreferences->frequencyFor(
                ManagementFixture::LEARNER_ID,
                'course_progress',
                'email',
            ),
        );
    }

    /**
     * The fold that exists is a daily one, so weekly stays refused: the offer
     * is per frequency and not per channel.
     */
    public function testAFrequencyNothingFoldsStaysRefusedBesideOneThatIsFolded(): void
    {
        $this->fixture->kernel->registries->scheduledTasks->register(new DailyEmailFold());

        $this->expectException(ValidationException::class);

        $this->fixture->kernel->notificationPreferenceCentre->put($this->fixture->learner(), [[
            'category_key' => 'course_progress',
            'channel_key' => 'email',
            'frequency' => Prefs::FREQUENCY_WEEKLY,
        ]]);
    }

    /**
     * The row a site upgrades into, and the defect the offer rule created.
     *
     * A learner who chose daily while a fold was installed keeps that row when
     * the fold goes away. Returning it unchanged put a control on the screen
     * whose value was none of its own options, and because the client
     * resubmits every editable row, pressing Save sent "daily" back and the
     * 422 refused the whole replacement: one stale cell made the entire
     * preference centre unsaveable. So a frequency this build cannot offer is
     * presented as immediate, with the stored value reported beside it.
     */
    public function testAStoredFrequencyNothingFoldsIsPresentedAsImmediateAndSaidSo(): void
    {
        $this->fixture->kernel->notificationPreferences->setPreference(
            ManagementFixture::LEARNER_ID,
            'course_progress',
            'email',
            Prefs::FREQUENCY_DAILY,
        );

        $row = $this->rowFor('course_progress', 'email');

        self::assertSame(Prefs::FREQUENCY_IMMEDIATE, $row['frequency']);
        self::assertSame(Prefs::FREQUENCY_DAILY, $row['stored_frequency']);
        self::assertContains($row['frequency'], $row['available_frequencies']);

        // And the whole centre saves, which it could not before: the client
        // resubmits every editable row with the value it was given.
        $submitted = [];

        foreach ($this->rows() as $editable) {
            if ($editable['editable'] === true) {
                $submitted[] = [
                    'category_key' => $editable['category_key'],
                    'channel_key' => $editable['channel_key'],
                    'frequency' => $editable['frequency'],
                ];
            }
        }

        $saved = $this->fixture->kernel->notificationPreferenceCentre->put($this->fixture->learner(), $submitted);

        self::assertIsArray($saved['rows']);
    }

    /** An offerable stored value is reported as itself, with nothing substituted. */
    public function testAnOfferableStoredFrequencyIsNotSubstituted(): void
    {
        $this->fixture->kernel->notificationPreferences->setPreference(
            ManagementFixture::LEARNER_ID,
            'course_progress',
            'email',
            Prefs::FREQUENCY_NEVER,
        );

        $row = $this->rowFor('course_progress', 'email');

        self::assertSame(Prefs::FREQUENCY_NEVER, $row['frequency']);
        self::assertNull($row['stored_frequency']);
    }

    /** A fold that comes back makes the stored choice offerable again. */
    public function testTheStoredChoiceIsHonouredAgainOnceAFoldIsRegistered(): void
    {
        $this->fixture->kernel->notificationPreferences->setPreference(
            ManagementFixture::LEARNER_ID,
            'course_progress',
            'email',
            Prefs::FREQUENCY_DAILY,
        );
        $this->fixture->kernel->registries->scheduledTasks->register(new DailyEmailFold());

        $row = $this->rowFor('course_progress', 'email');

        self::assertSame(Prefs::FREQUENCY_DAILY, $row['frequency']);
        self::assertNull($row['stored_frequency']);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(string $category, string $channel): array
    {
        foreach ($this->rows() as $row) {
            if ($row['category_key'] === $category && $row['channel_key'] === $channel) {
                return $row;
            }
        }

        self::fail($category . '|' . $channel . ' is not on the preference centre.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $centre = $this->fixture->kernel->notificationPreferenceCentre->get($this->fixture->learner());
        $rows = $centre['rows'];
        self::assertIsArray($rows);
        self::assertNotSame([], $rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }
}
