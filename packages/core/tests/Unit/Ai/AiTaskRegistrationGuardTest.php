<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\Ai\Fact\BankSiblingVectorsFact;
use Shondalai\VidyaLMS\Core\Provider\Ai\Placement\ReservedFields;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraft;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Ai\TargetsExtensionPoint;

/**
 * The three refusals that happen at registration rather than at run time.
 *
 * Each of them describes a task that was misdeclared when somebody wrote it,
 * and each would otherwise fail on a person's screen months later, on a site
 * that trusted the picker to only offer things that work. A save-time refusal
 * is the same shape a condition leaf used outside its declared applicability
 * already gets, so an author who has met one has met all of them.
 */
final class AiTaskRegistrationGuardTest extends TestCase
{
    public function testATaskDeclaringAFactNothingSuppliesIsRefusedAtRegistration(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no AiFactProvider supplies/');

        $registry->register(new class extends StubTask implements RequiresFacts {
            public function requiredFacts(): array
            {
                return ['acme.reading_speed'];
            }
        });
    }

    public function testATaskDeclaringAFactSomethingSuppliesIsAdmitted(): void
    {
        $registry = $this->registry();

        $registry->register(new class extends StubTask implements RequiresFacts {
            public function requiredFacts(): array
            {
                return [BankSiblingVectorsFact::KEY];
            }
        });

        self::assertSame(['stub.task'], $registry->keys());
    }

    public function testAFindingsOnlyTaskThatAlsoWritesIsRefused(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/wired to approve/');

        $registry->register(new class extends StubTask implements ProducesFindingsOnly, TargetsExtensionPoint {
            public function targetExtensionPoint(): string
            {
                return 'question_type';
            }

            public function targetProviderKey(): string
            {
                return 'single_choice';
            }
        });
    }

    public function testAFindingsOnlyTaskClaimingAnArtefactKindIsRefused(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/findings-only/');

        $registry->register(new class extends StubTask implements ProducesFindingsOnly, DeclaresTaskOutput {
            public function outputSchema(): array
            {
                return ['type' => 'object'];
            }

            public function draftKind(): string
            {
                return 'question';
            }
        });
    }

    public function testATaskWhoseOutputSchemaBindsAMarkIsRefused(): void
    {
        $registry = $this->registry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved field/');

        // The exact pair the design names: a vendor task declaring a
        // suggested mark plus a vendor field-fill placement passed every
        // structural test the earlier design had, because a field-filling
        // helper declares no extension point and the tests looked only at
        // extension points.
        $registry->register(new class extends StubTask implements DeclaresTaskOutput {
            public function outputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'feedback' => ['type' => 'string'],
                        'suggested_mark' => ['type' => 'number'],
                    ],
                ];
            }

            public function draftKind(): string
            {
                return 'feedback_text';
            }
        });
    }

    public function testAReservedFieldNestedInsideAnArrayIsStillFound(): void
    {
        $bound = ReservedFields::boundBy([
            'type' => 'object',
            'properties' => [
                'criteria' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => ['comment' => ['type' => 'string'], 'points' => ['type' => 'number']],
                    ],
                ],
            ],
        ]);

        self::assertSame(['criteria.items.points'], $bound);
    }

    public function testAnItemsOwnAnswerIsNotAReservedField(): void
    {
        // `correct`, `key` and `answer` are what a question definition is made
        // of. Reserving any of them would refuse every question-drafting task
        // in the product, including the free deterministic one: the redline is
        // about a mark, which is a judgement about a person, and not about an
        // item's own answer, which is a property of the item.
        foreach (ReservedFields::DELIBERATELY_ALLOWED as $field) {
            self::assertFalse(ReservedFields::isReserved($field));
        }

        self::assertFalse(ReservedFields::isReserved('remarks'), 'a substring scan would refuse this');
        self::assertTrue(ReservedFields::isReserved('suggestedMark'), 'a substring scan would miss this');
        self::assertTrue(ReservedFields::isReserved('feedback_score'));
        self::assertFalse(ReservedFields::isReserved('feedback_to_learner'));
    }

    public function testADraftNamingNoTargetIsNotAcceptable(): void
    {
        $noPoint = AiDraft::of(1, 1, 'question', [], null, 'single_choice');
        $noProvider = AiDraft::of(1, 1, 'question', [], 'question_type', null);
        $both = AiDraft::of(1, 1, 'question', [], 'question_type', 'single_choice');

        self::assertFalse($noPoint->isAcceptable());
        self::assertFalse($noProvider->isAcceptable());
        self::assertTrue($both->isAcceptable());
    }

    private function registry(): AiTaskRegistry
    {
        $facts = new AiFactProviderRegistry();
        $facts->register(new BankSiblingVectorsFact());

        $registry = new AiTaskRegistry();
        $registry->validateFactsAgainst($facts);
        $registry->refuseReservedFieldsWith(
            static fn (array $schema): array => ReservedFields::boundBy($schema),
        );

        return $registry;
    }
}
