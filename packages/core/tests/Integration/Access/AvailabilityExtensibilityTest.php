<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\UpgradeAvailabilityPayloads;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresPresets;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The seams a third party reaches, and the ones that were declared and never
 * consumed.
 *
 * A registry with a provider nothing drives is the failure the edition rule
 * names one step in from an empty seam, and a declared upgrader chain nobody
 * calls is the same shape: the first provider to bump its shape would have
 * had to support version one for the life of the product.
 */
final class AvailabilityExtensibilityTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 00:00:00');
    }

    /**
     * A leaf stored at version one, evaluated by a provider now at shape
     * two, is upgraded on read rather than routed to the miss path and
     * failed closed forever.
     */
    public function testAStoredLeafBelowTheProvidersShapeIsUpgradedOnRead(): void
    {
        $condition = new class () implements AvailabilityConditionInterface, SupportsPayloadVersions {
            public function key(): string
            {
                return 'acme.two_shapes';
            }

            public function label(): string
            {
                return 'acme.two_shapes';
            }

            public function applicability(): array
            {
                return [AvailabilitySurface::ContentGating];
            }

            public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
            {
                // Version two spells the field "open"; version one spelled it
                // "allow". A payload that reached here unupgraded cannot read
                // its own fields, which is exactly the miss the composer used
                // to route it to.
                return ($payload['open'] ?? false) === true
                    ? AvailabilityOutcome::available()
                    : AvailabilityOutcome::blocked('acme.two_shapes.closed');
            }

            public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
            {
                return new AvailabilityReason('acme.two_shapes.closed');
            }

            public function currentShape(): int
            {
                return 2;
            }

            public function upgrade(array $payload, int $from, UpgradeContext $context): array
            {
                if ($from !== 1) {
                    return $payload;
                }

                return ['open' => ($payload['allow'] ?? false) === true];
            }

            public function supportedPayloadVersions(): array
            {
                return [2];
            }
        };

        $this->fixture->kernel->registries->availabilityConditions->register($condition);

        $this->storeRule($this->fixture->lessonId, [
            'op' => 'and',
            'c' => [['type' => $condition->key(), 'payload' => StoredShape::wrap(['allow' => true], 1)]],
        ]);

        $response = $this->fixture->call(
            'GET',
            '/courses/' . $this->fixture->courseId . '/curriculum',
            $this->fixture->learner(),
        );

        self::assertSame(200, $response->status);

        $access = $this->accessOf($response->data, $this->fixture->lessonId);
        self::assertSame('available', $access['state'] ?? null, (string) json_encode($access));
    }

    /**
     * The batch half of the chain rewrites the stored document, so the read
     * path stops upgrading in memory on every render and the reference table
     * follows.
     */
    public function testTheBatchJobRewritesAStoredPayloadToItsProvidersShape(): void
    {
        $condition = new class () implements AvailabilityConditionInterface {
            public function key(): string
            {
                return 'acme.bumped';
            }

            public function label(): string
            {
                return 'acme.bumped';
            }

            public function applicability(): array
            {
                return [AvailabilitySurface::ContentGating];
            }

            public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
            {
                return AvailabilityOutcome::available();
            }

            public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
            {
                return new AvailabilityReason('acme.bumped');
            }

            public function currentShape(): int
            {
                return 3;
            }

            public function upgrade(array $payload, int $from, UpgradeContext $context): array
            {
                return $payload + ['stepped_' . $from => true];
            }
        };

        $this->fixture->kernel->registries->availabilityConditions->register($condition);

        $this->storeRule($this->fixture->lessonId, [
            'op' => 'and',
            'c' => [['type' => $condition->key(), 'payload' => StoredShape::wrap(['seed' => 1], 1)]],
        ]);

        $job = new UpgradeAvailabilityPayloads(
            $this->fixture->kernel->availabilityRules,
            $this->fixture->kernel->registries->availabilityConditions,
            $this->fixture->env->database,
        );

        self::assertSame(1, $job->execute());

        $row = $this->fixture->env->database->selectOne(
            Query::from('availability_rules')
                ->where('subject_type', '=', 'lesson')
                ->where('subject_id', '=', $this->fixture->lessonId),
        );

        self::assertNotNull($row);
        self::assertStringContainsString('stepped_1', (string) $row['rule']);
        self::assertStringContainsString('stepped_2', (string) $row['rule']);

        // The mirrored version follows the document, or the next batch reads
        // the old one and the job never finishes.
        $ref = $this->fixture->env->database->selectOne(
            Query::from('availability_rule_refs')->where('type', '=', $condition->key()),
        );

        self::assertNotNull($ref);
        self::assertSame(3, (int) $ref['payload_version']);
    }

    /**
     * A preset is a contribution, so a sixth preset is a registration rather
     * than an edit to a shipped core file.
     */
    public function testAConditionContributesItsOwnPreset(): void
    {
        $translator = $this->fixture->env->translator;
        $translator->define('vidyalms.availability.preset.field.from', 'Release time');
        $translator->define('vidyalms.availability.preset.custom_hint', 'Choose a release time.');
        $translator->define('vidyalms.availability.preset.custom_option', 'After approval');
        $translator->define('Release after QA approval', 'This literal must not be translated');

        $condition = new class () implements AvailabilityConditionInterface, DeclaresPresets {
            public function key(): string
            {
                return 'acme.presetful';
            }

            public function label(): string
            {
                return 'acme.presetful';
            }

            public function applicability(): array
            {
                return [AvailabilitySurface::ContentGating];
            }

            public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
            {
                return AvailabilityOutcome::available();
            }

            public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
            {
                return new AvailabilityReason('acme.presetful');
            }

            public function currentShape(): int
            {
                return 1;
            }

            public function upgrade(array $payload, int $from, UpgradeContext $context): array
            {
                return $payload;
            }

            public function declaredPresets(): array
            {
                return [[
                    'key' => 'acme_named_thing',
                    'label' => 'Release after QA approval',
                    'anchor_key' => null,
                    'fields' => [
                        [
                            'key' => 'thing',
                            'type' => 'enum',
                            'label' => 'vidyalms.availability.preset.field.from',
                            'description' => 'vidyalms.availability.preset.custom_hint',
                            'hint' => 'vidyalms.availability.preset.custom_hint',
                            'required' => true,
                            'default' => 'vidyalms.stored_value',
                            'options' => [
                                ['key' => 'vidyalms.stored_value', 'label' => 'vidyalms.availability.preset.custom_option'],
                                ['key' => 'other', 'label' => 'Keep this QA label'],
                            ],
                        ],
                    ],
                ]];
            }
        };

        $this->fixture->kernel->registries->availabilityConditions->register($condition);

        $response = $this->fixture->call(
            'GET',
            '/manage/availability/conditions',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);

        $keys = array_map(
            static fn (array $preset): string => (string) $preset['key'],
            $response->data['presets'] ?? [],
        );

        self::assertContains('acme_named_thing', $keys, 'a contributed preset never reached the list');
        // The five free presets are still there, so nothing regressed on the
        // way to making them contributions.
        self::assertContains('date_from', $keys);

        $presets = array_column($response->data['presets'] ?? [], null, 'key');
        $preset = $presets['acme_named_thing'];
        self::assertSame('Release after QA approval', $preset['label']);
        self::assertSame('acme.presetful', $preset['condition_key']);
        self::assertNull($preset['anchor_key']);
        self::assertTrue($preset['available']);
        self::assertSame([
            'key' => 'thing',
            'type' => 'enum',
            'label' => 'Release time',
            'description' => 'Choose a release time.',
            'hint' => 'Choose a release time.',
            'required' => true,
            'default' => 'vidyalms.stored_value',
            'options' => [
                ['key' => 'vidyalms.stored_value', 'label' => 'After approval'],
                ['key' => 'other', 'label' => 'Keep this QA label'],
            ],
        ], $preset['fields'][0]);
    }

    public function testTheCatalogueTranslatesShippedPresetAndFieldLabels(): void
    {
        $translator = $this->fixture->env->translator;
        $translator->define('vidyalms.availability.preset.date_from', 'Available on a date');
        $translator->define('vidyalms.availability.preset.field.from', 'Available from');
        $translator->define('vidyalms.availability.preset.field.tz', 'Time zone');

        $response = $this->fixture->call(
            'GET',
            '/manage/availability/conditions',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        $presets = array_column($response->data['presets'] ?? [], null, 'key');
        self::assertSame('Available on a date', $presets['date_from']['label']);
        self::assertSame('date', $presets['date_from']['condition_key']);
        self::assertTrue($presets['date_from']['available']);
        self::assertSame([
            ['key' => 'from', 'type' => 'datetime', 'label' => 'Available from', 'required' => true],
            ['key' => 'tz', 'type' => 'timezone', 'label' => 'Time zone', 'required' => false],
        ], $presets['date_from']['fields']);
    }

    /**
     * An instructor of one course read another course's whole stored rule
     * through the preview, because the authorization was checked against a
     * course the caller chose and the tree was loaded from a subject they
     * chose separately.
     */
    public function testThePreviewRefusesASubjectOutsideTheNamedCourse(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/availability/preview',
            $this->fixture->teacher(),
            [
                'user_id' => ManagementFixture::LEARNER_ID,
                'course_id' => $this->fixture->courseId,
                'subject_type' => 'course',
                'subject_id' => $this->fixture->otherCourseId,
            ],
        );

        self::assertContains($response->status, [403, 404, 422], (string) json_encode($response->data));
    }

    /**
     * The same save-time pass, stamped with the notification owner type, so
     * a condition naming lesson 88 is an indexed row across all three owners
     * and is remapped by a course copy exactly as a content gate is.
     */
    public function testANotificationRulesConditionsTreeWritesReferenceRows(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/notifications/rules',
            $this->fixture->manager(),
            [
                'rule_key' => 'acme.conditioned',
                'trigger_key' => 'vidyalms.mail.enrolled',
                'channels' => ['email'],
                'conditions' => [
                    'op' => 'and',
                    'c' => [[
                        'type' => 'completion',
                        'payload' => StoredShape::wrap([
                            'target' => ['kind' => 'lesson', 'id' => $this->fixture->lessonId],
                            'state' => 'complete',
                        ], 1),
                    ]],
                ],
            ],
        );

        self::assertContains($response->status, [200, 201], (string) json_encode($response->data));

        $refs = $this->fixture->env->database->selectAll(
            Query::from('availability_rule_refs')
                ->where('owner_type', '=', SqlAvailabilityRuleRepository::OWNER_NOTIFICATION_RULE),
        );

        self::assertNotSame([], $refs, 'a notification rule stored a tree and no reference rows');
        self::assertSame('lesson', (string) $refs[0]['ref_type']);
        self::assertSame($this->fixture->lessonId, (int) $refs[0]['ref_id']);
    }

    /**
     * A leaf whose surface the provider does not declare is refused at save
     * on the notification surface too, and not only on the content one.
     */
    public function testANotificationRuleRefusesALeafOutsideItsDeclaredSurface(): void
    {
        $condition = new class () implements AvailabilityConditionInterface {
            public function key(): string
            {
                return 'acme.content_only';
            }

            public function label(): string
            {
                return 'acme.content_only';
            }

            public function applicability(): array
            {
                return [AvailabilitySurface::ContentGating];
            }

            public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
            {
                return AvailabilityOutcome::available();
            }

            public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
            {
                return new AvailabilityReason('acme.content_only');
            }

            public function currentShape(): int
            {
                return 1;
            }

            public function upgrade(array $payload, int $from, UpgradeContext $context): array
            {
                return $payload;
            }
        };

        $this->fixture->kernel->registries->availabilityConditions->register($condition);

        $response = $this->fixture->call(
            'POST',
            '/manage/notifications/rules',
            $this->fixture->manager(),
            [
                'rule_key' => 'acme.wrong_surface',
                'trigger_key' => 'vidyalms.mail.enrolled',
                'channels' => ['email'],
                'conditions' => [
                    'op' => 'and',
                    'c' => [['type' => $condition->key(), 'payload' => StoredShape::wrap([], 1)]],
                ],
            ],
        );

        self::assertSame(422, $response->status, (string) json_encode($response->data));
    }

    /**
     * @param array<string, mixed> $tree
     */
    private function storeRule(int $lessonId, array $tree): void
    {
        $now = '2026-04-01 00:00:00';

        $this->fixture->env->database->insert('availability_rules', [
            'subject_type' => 'lesson',
            'subject_id' => $lessonId,
            'surface' => AvailabilitySurface::ContentGating->value,
            'rule' => json_encode(['_v' => 1] + $tree, \JSON_THROW_ON_ERROR),
            'rule_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The reference rows the save pass would have written, so the batch
        // job has an index to select on, which is the whole reason the table
        // is polymorphic in its owner.
        $ruleId = (int) ($this->fixture->env->database->selectOne(
            Query::from('availability_rules')
                ->select(['id'])
                ->where('subject_type', '=', 'lesson')
                ->where('subject_id', '=', $lessonId),
        )['id'] ?? 0);

        foreach ($tree['c'] as $index => $leaf) {
            $this->fixture->env->database->insert('availability_rule_refs', [
                'owner_type' => SqlAvailabilityRuleRepository::OWNER_AVAILABILITY_RULE,
                'owner_id' => $ruleId,
                'surface' => AvailabilitySurface::ContentGating->value,
                'leaf_path' => 'c.' . $index,
                'type' => $leaf['type'],
                'payload_version' => (int) ($leaf['payload']['_v'] ?? 1),
                'ref_type' => null,
                'ref_id' => null,
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>
     */
    private function accessOf(?array $data, int $lessonId): array
    {
        foreach ($data['modules'] ?? [] as $module) {
            foreach ($module['lessons'] ?? [] as $lesson) {
                if ((int) ($lesson['id'] ?? 0) === $lessonId) {
                    return $lesson['access'] ?? [];
                }
            }
        }

        return [];
    }
}
