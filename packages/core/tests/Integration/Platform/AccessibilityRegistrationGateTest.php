<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Assessment\QuestionTypes;
use Shondalai\VidyaLMS\Core\Application\Platform\FeatureProviderLedger;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProvider;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseFeatureProviderRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\AccessibilityDeclaringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\AccessibilityRefusedException;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\DragOnlyType;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Accessibility is a property of the extension point, enforced server side.
 *
 * ADR 0011 states the three-mode rule as something the registry does. It was
 * implemented only in the client renderer registry, so it ran in the learner's
 * browser, only for types whose renderer happened to be in that bundle, and
 * never at all for a server-registered type with no client module. And the
 * conformance flag lived in memory per page, so no site owner could see which
 * installed types were unverified.
 */
final class AccessibilityRegistrationGateTest extends TestCase
{
    public function testATypeDeclaringDraggingWithNoAlternativeIsRefused(): void
    {
        $registry = new QuestionTypeRegistry();

        $this->expectException(AccessibilityRefusedException::class);
        $registry->register(new DragOnlyType());
    }

    public function testDraggingBesideTheOtherTwoModesIsAccepted(): void
    {
        $registry = new QuestionTypeRegistry();
        $registry->register(new DragOnlyType([
            AccessibilityDeclaringInterface::POINTER_DRAG,
            AccessibilityDeclaringInterface::POINTER_NO_DRAG,
            AccessibilityDeclaringInterface::KEYBOARD,
        ]));

        self::assertTrue($registry->has(DragOnlyType::KEY));
    }

    public function testDraggingWithAnEssentialJustificationIsAccepted(): void
    {
        $registry = new QuestionTypeRegistry();
        $registry->register(new DragOnlyType(
            [AccessibilityDeclaringInterface::POINTER_DRAG],
            'The construct being measured is the drag itself.',
        ));

        self::assertTrue($registry->has(DragOnlyType::KEY));
    }

    /**
     * A decoration goes through the same gate: replace() is how a paid
     * package takes over a key, and it must not be a way past the rule.
     */
    public function testTheGateAppliesToADecorationToo(): void
    {
        $registry = new QuestionTypeRegistry();
        $registry->register(new DragOnlyType([
            AccessibilityDeclaringInterface::POINTER_NO_DRAG,
            AccessibilityDeclaringInterface::KEYBOARD,
        ]));

        $this->expectException(AccessibilityRefusedException::class);
        $registry->replace(new DragOnlyType());
    }

    public function testTheLedgerRecordsWhichTypesAreUnverified(): void
    {
        $env = TestPorts::create();
        $kernel = new CoreKernel(new Ports(
            database: $env->database,
            users: $env->users,
            authorization: $env->authorization,
            storage: $env->storage,
            mailer: $env->mailer,
            scheduler: $env->scheduler,
            eventBridge: $env->eventBridge,
            commerce: $env->commerce,
            translator: $env->translator,
            cache: $env->cache,
            clock: $env->clock,
            random: $env->random,
            logger: new NullLogger(),
        ));

        $rows = [];

        foreach ($kernel->featureProviders->listActive() as $row) {
            if ($row->extensionPoint === QuestionTypes::EXTENSION_POINT) {
                $rows[$row->providerKey] = $row;
            }
        }

        self::assertCount(6, $rows, 'the free six are all recorded');

        foreach ($rows as $key => $row) {
            self::assertTrue($row->flagged, sprintf('%s declares no kit result, so it is flagged', (string) $key));
            self::assertNull($row->conformanceResult);
        }

        // A type that does declare one is recorded unflagged, which is what
        // the admin screen reads to tell the two apart.
        $ledger = new FeatureProviderLedger(
            new DatabaseFeatureProviderRepository($env->database, $env->clock),
            new DatabaseSettingsRepository($env->database, $env->clock),
        );
        $ledger->record(QuestionTypes::EXTENSION_POINT, DragOnlyType::KEY, 'acme', 'kit-2026-11', false);

        $verified = null;

        foreach ($ledger->listActive() as $row) {
            if ($row->providerKey === DragOnlyType::KEY) {
                $verified = $row;
            }
        }

        self::assertInstanceOf(FeatureProvider::class, $verified);
        self::assertFalse($verified->flagged);
        self::assertSame('kit-2026-11', $verified->conformanceResult);
    }
}
