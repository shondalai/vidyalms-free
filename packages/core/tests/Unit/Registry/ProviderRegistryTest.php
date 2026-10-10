<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\OpenEnrolmentRule;
use Shondalai\VidyaLMS\Core\Registry\DuplicateProviderException;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleInterface;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderAlreadyInstalledException;
use Shondalai\VidyaLMS\Core\Registry\ProviderNotRegisteredException;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class ProviderRegistryTest extends TestCase
{
    private function lessonType(string $key, ?string $label = null): LessonTypeInterface
    {
        return new class ($key, $label) implements LessonTypeInterface {
            use DefaultContentVersion;

            public function __construct(
                private readonly string $key,
                private readonly ?string $label,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return $this->label ?? ucfirst($this->key);
            }

            public function completionMode(): string
            {
                return self::COMPLETION_ON_VIEW;
            }

            public function validateContent(array $content): void
            {
            }

            public function presentForLearner(array $content, bool $hasAccess): array
            {
                return $content;
            }
        };
    }

    public function testRegistersAndResolvesProviders(): void
    {
        $registry = new LessonTypeRegistry();
        $registry->register($this->lessonType('reading'));
        $registry->register($this->lessonType('video'));

        self::assertTrue($registry->has('reading'));
        self::assertSame(['reading', 'video'], $registry->keys());
        self::assertSame('Video', $registry->get('video')->label());
    }

    public function testRejectsDuplicateKeys(): void
    {
        $registry = new LessonTypeRegistry();
        $registry->register($this->lessonType('reading'));

        $this->expectException(DuplicateProviderException::class);
        $registry->register($this->lessonType('reading'));
    }

    public function testUnknownKeysRaiseTheProPlaceholderException(): void
    {
        $registry = new LessonTypeRegistry();

        $this->expectException(UnknownProviderException::class);
        $registry->get('scorm');
    }

    public function testRejectsProvidersOfTheWrongKind(): void
    {
        $registry = new QuestionTypeRegistry();

        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type */
        $registry->register($this->lessonType('reading'));
    }

    public function testReplaceSwapsTheProviderAndKeepsTheKeyInPlace(): void
    {
        $registry = new LessonTypeRegistry();
        $registry->register($this->lessonType('reading'));
        $registry->register($this->lessonType('video'));
        $registry->register($this->lessonType('quiz'));

        $registry->replace($this->lessonType('video', 'Protected video'));

        self::assertSame('Protected video', $registry->get('video')->label());
        self::assertSame(['reading', 'video', 'quiz'], $registry->keys());
        self::assertCount(3, $registry->all());
        self::assertSame('Reading', $registry->get('reading')->label());
    }

    public function testReplaceReturnsTheDisplacedProviderSoTheOverrideCanDelegateToIt(): void
    {
        $registry = new LessonTypeRegistry();
        $free = $this->lessonType('video', 'Video embed');
        $registry->register($free);

        $override = new class implements LessonTypeInterface {
            use DefaultContentVersion;

            private LessonTypeInterface $inner;

            public function installInto(LessonTypeRegistry $registry): LessonTypeInterface
            {
                $this->inner = $registry->replace($this);

                return $this->inner;
            }

            public function key(): string
            {
                return 'video';
            }

            public function label(): string
            {
                return $this->inner->label() . ' (protected)';
            }

            public function completionMode(): string
            {
                return $this->inner->completionMode();
            }

            public function validateContent(array $content): void
            {
                $this->inner->validateContent($content);
            }

            public function presentForLearner(array $content, bool $hasAccess): array
            {
                return $this->inner->presentForLearner($content, $hasAccess) + ['signed' => $hasAccess];
            }
        };

        $displaced = $override->installInto($registry);

        self::assertSame($free, $displaced);

        $resolved = $registry->get('video');
        self::assertSame($override, $resolved);
        self::assertSame('Video embed (protected)', $resolved->label());
        self::assertSame(LessonTypeInterface::COMPLETION_ON_VIEW, $resolved->completionMode());
        self::assertSame(['url' => 'x', 'signed' => true], $resolved->presentForLearner(['url' => 'x'], true));
    }

    public function testReplaceRejectsAKeyNobodyRegistered(): void
    {
        $registry = new LessonTypeRegistry();
        $registry->register($this->lessonType('reading'));

        $this->expectException(ProviderNotRegisteredException::class);
        $registry->replace($this->lessonType('scorm'));
    }

    public function testReplaceRejectsProvidersOfTheWrongKind(): void
    {
        $registry = new QuestionTypeRegistry();

        // The kind check runs before the existence check, so this is an
        // argument error and never ProviderNotRegisteredException.
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type */
        $registry->replace($this->lessonType('reading'));
    }

    public function testRegisterAfterReplaceStillRejectsTheDuplicate(): void
    {
        $registry = new LessonTypeRegistry();
        $registry->register($this->lessonType('reading'));
        $registry->replace($this->lessonType('reading', 'Reading, extended'));

        $this->expectException(DuplicateProviderException::class);
        $registry->register($this->lessonType('reading'));
    }

    public function testAConsumerSeesAReplacementInstalledAfterItWasBuilt(): void
    {
        $rules = new EnrolmentRuleRegistry();
        $rules->register(new OpenEnrolmentRule(new StubTranslator()));

        // Shaped like the SelfEnrol use case: the registry is injected once at
        // boot and the provider is resolved on every call.
        $consumer = new class ($rules) {
            public function __construct(private readonly EnrolmentRuleRegistry $rules)
            {
            }

            /**
             * @param array<string, mixed> $course
             */
            public function maySelfEnrol(Principal $principal, array $course): bool
            {
                return $this->rules->get('open')->allowsSelfEnrolment($principal, $course);
            }
        };

        $learner = Principal::user(42);
        $course = ['state' => 'published', 'price_mode' => 'free'];

        self::assertTrue($consumer->maySelfEnrol($learner, $course));

        // The site owner's self enrolment switch, installed after the consumer
        // exists, gating whatever the free build registered.
        $switch = new class implements EnrolmentRuleInterface {
            private EnrolmentRuleInterface $inner;

            public bool $enabled = false;

            public function installInto(EnrolmentRuleRegistry $registry): void
            {
                $this->inner = $registry->replace($this);
            }

            public function key(): string
            {
                return 'open';
            }

            public function label(): string
            {
                return $this->inner->label();
            }

            public function allowsSelfEnrolment(Principal $principal, array $course): bool
            {
                return $this->enabled && $this->inner->allowsSelfEnrolment($principal, $course);
            }
        };

        $switch->installInto($rules);

        self::assertFalse($consumer->maySelfEnrol($learner, $course));

        $switch->enabled = true;

        self::assertTrue($consumer->maySelfEnrol($learner, $course));
        self::assertSame('vidyalms.enrolment_rule.open', $rules->get('open')->label());

        // Delegation still decides: the switch never overrides the free rule's
        // own answer, it only narrows it.
        self::assertFalse($consumer->maySelfEnrol(Principal::guest(), $course));
    }
    public function testReplaceRefusesToInstallTheSameInstanceTwice(): void
    {
        $registry = new LessonTypeRegistry();
        $rule = $this->lessonType('reading');
        $registry->register($rule);

        // A wrapper that delegates to what replace() hands back would delegate
        // to itself, so this must fail rather than build a recursive chain.
        $this->expectException(ProviderAlreadyInstalledException::class);
        $registry->replace($rule);
    }
}
