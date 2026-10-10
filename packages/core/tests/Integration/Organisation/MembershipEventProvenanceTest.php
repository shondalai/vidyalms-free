<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\MembershipEventContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;

/**
 * Who caused a membership event, pinned where it can regress.
 *
 * The pair that must never disagree is `source` and `actor_type`: a row saying
 * `source = roster` and `actor_type = principal` is a row claiming a person
 * typed a CSV, and a row saying `source = manual` from a nightly sweep is a row
 * claiming a human closed four hundred memberships at three in the morning.
 * R5 found most writers inheriting the console default and fixed the seven that
 * were plainly wrong; this is the guard that keeps them fixed.
 *
 * The second case is a source scan rather than a behavioural assertion, and
 * deliberately so. The behaviour of each sweep is already asserted where the
 * sweep is exercised; what is not asserted anywhere is the property that makes
 * those assertions keep holding, which is that none of these writers falls back
 * to the default. A scan is the only check that covers a file whose sweep is
 * exercised in one test and whose other writes are not.
 */
final class MembershipEventProvenanceTest extends TestCase
{
    /**
     * Every writer that is not a person at a console, with the number of
     * event-stream writes it makes.
     *
     * The count is the point: a file that grows an eighth write and forgets
     * the context fails here rather than in an audit two years later.
     */
    private const PROVENANCE_WRITERS = [
        'src/Provider/ScheduledTask/InvitationExpiryTask.php',
        'src/Provider/ScheduledTask/ReservationExpiryTask.php',
        'src/Provider/ScheduledTask/MembershipTermSweepTask.php',
        'src/Application/Organisation/OrganisationAccessTermination.php',
        'src/Application/Organisation/ApplyProvisioningAssertion.php',
        'src/Application/Organisation/ManageOrganisationInvitations.php',
        'src/Provider/Importer/Roster/RosterImporter.php',
    ];

    /**
     * The factory is what fixes the pair, so the pair is asserted on the
     * factory rather than on each of the thirty-odd call sites.
     */
    public function testEachFactoryFixesTheSourceAndTheActorTogether(): void
    {
        $pairs = [
            'manual' => MembershipEventContext::manual(),
            'invitation' => MembershipEventContext::invitation('42'),
            'roster' => MembershipEventContext::roster('sourced-1'),
            'directory' => MembershipEventContext::directory('sub-1'),
            'system' => MembershipEventContext::system('7'),
        ];

        $expected = [
            'manual' => [MembershipEvent::SOURCE_MANUAL, MembershipEvent::ACTOR_PRINCIPAL],
            'invitation' => [MembershipEvent::SOURCE_INVITATION, MembershipEvent::ACTOR_PRINCIPAL],
            'roster' => [MembershipEvent::SOURCE_ROSTER, MembershipEvent::ACTOR_IMPORTER],
            'directory' => [MembershipEvent::SOURCE_DIRECTORY, MembershipEvent::ACTOR_IMPORTER],
            'system' => [MembershipEvent::SOURCE_SYSTEM, MembershipEvent::ACTOR_SYSTEM],
        ];

        foreach ($pairs as $name => $context) {
            self::assertSame($expected[$name], [$context->source, $context->actorType], $name);
            self::assertContains($context->source, MembershipEvent::SOURCES);
            self::assertContains($context->actorType, MembershipEvent::ACTOR_TYPES);
        }

        // The three actor kinds a sweep, an importer and a person are, all
        // distinct. Two of them collapsing into one is the regression that
        // makes the column useless.
        self::assertCount(3, array_unique(array_map(
            static fn (MembershipEventContext $context): string => $context->actorType,
            $pairs,
        )));
    }

    /** Narrowing a context keeps the pair, because only the unit or the role moves. */
    public function testNarrowingAContextNeverChangesWhoCausedIt(): void
    {
        $sweep = MembershipEventContext::system('7')->inUnit(4)->forRole('org_member');

        self::assertSame(MembershipEvent::SOURCE_SYSTEM, $sweep->source);
        self::assertSame(MembershipEvent::ACTOR_SYSTEM, $sweep->actorType);
        self::assertSame(4, $sweep->orgUnitId);
        self::assertSame('org_member', $sweep->roleKey);
    }

    /**
     * No sweep, importer or acceptance path may fall back to the console
     * default.
     */
    public function testNoSweepImporterOrAcceptanceInheritsTheConsoleDefault(): void
    {
        $root = \dirname(__DIR__, 3);

        foreach (self::PROVENANCE_WRITERS as $relative) {
            $source = file_get_contents($root . '/' . $relative);
            self::assertIsString($source, $relative);

            $contexts = substr_count($source, 'MembershipEventContext::');
            self::assertGreaterThan(
                0,
                $contexts,
                $relative . ' writes to the membership event stream without naming its provenance',
            );
            self::assertSame(
                0,
                substr_count($source, 'MembershipEventContext::manual('),
                $relative . ' is not a person at a console, so it may not record one',
            );

            // Every event this file writes carries a context of its own. The
            // count is the guard: a file that grows one more write and forgets
            // the argument fails here.
            self::assertGreaterThanOrEqual(
                substr_count($source, '->event('),
                $contexts,
                $relative . ' has more event writes than provenances',
            );
        }
    }

    /**
     * The default is still the console, and it is still honest.
     *
     * The store's fallback records a principal because every caller that omits
     * a context is one. It is asserted here so that changing the fallback to
     * something quieter, such as an unknown, is a decision somebody has to make
     * against a failing test rather than a default that drifts.
     */
    public function testTheStoreFallbackIsTheConsoleAndNotAnUnknown(): void
    {
        $columns = MembershipEventContext::manual()->columns('2026-09-09 10:00:00');

        self::assertSame(MembershipEvent::SOURCE_MANUAL, $columns['source']);
        self::assertSame(MembershipEvent::ACTOR_PRINCIPAL, $columns['actor_type']);
        self::assertSame('2026-09-09 10:00:00', $columns['occurred_at']);
        self::assertSame('2026-09-09 10:00:00', $columns['recorded_at']);
    }
}
