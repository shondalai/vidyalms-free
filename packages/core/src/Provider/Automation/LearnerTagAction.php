<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Domain\Automation\SuppressionReason;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;
use Shondalai\VidyaLMS\Core\Registry\Automation\ActionOutcome;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresEffectClass;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresSentence;
use Shondalai\VidyaLMS\Core\Registry\Automation\DeclaresReversal;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsDryRun;
use Shondalai\VidyaLMS\Core\Registry\Automation\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Automation\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;

/**
 * Set a label on the subject, or clear it.
 *
 * One class and two keys, because clear_tag is set_tag's inverse and a second
 * class would be a second copy of the same guard and the same provenance
 * write.
 *
 * The store is learner_tags, which this wave declares because the free schema
 * carried no per-learner label anywhere: cohorts and question tags are both
 * Pro tables and neither is about a learner. Every row carries the rule and
 * run that wrote it, because loop protection mechanism one needs provenance on
 * every write an automation makes.
 */
final class LearnerTagAction implements
    AutomationActionInterface,
    DeclaresEffectClass,
    DeclaresSentence,
    SupportsDryRun,
    SupportsPayloadVersions,
    ValidatesPayload,
    DeclaresReversal
{
    public const KEY_SET = 'set_tag';
    public const KEY_CLEAR = 'clear_tag';
    public const TABLE = 'learner_tags';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly bool $sets = true,
    ) {
    }

    public static function set(DatabasePort $database, ClockPort $clock): self
    {
        return new self($database, $clock, true);
    }

    public static function clear(DatabasePort $database, ClockPort $clock): self
    {
        return new self($database, $clock, false);
    }

    public function key(): string
    {
        return $this->sets ? self::KEY_SET : self::KEY_CLEAR;
    }

    public function label(): string
    {
        return $this->sets ? 'vidyalms.automation.action.set_tag' : 'vidyalms.automation.action.clear_tag';
    }

    /** What this action reverses, or null for the forward half of the pair. */
    public function reverses(): ?string
    {
        return $this->sets ? null : self::KEY_SET;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['tag'],
            'additionalProperties' => false,
            'properties' => ['tag' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]],
        ];
    }

    /**
     * The two keys the seam declares, and not the two it was answering.
     *
     * Every shipped action answered `surfaces` and `subjects`, neither of
     * which the interface documents and neither of which anything read, so
     * the save-time refusal the seam promises had nothing to refuse
     * against.
     */
    public function applicability(): array
    {
        return ['subject_types' => ['user'], 'trigger_keys' => []];
    }

    public function effectClass(): AutomationEffectClass
    {
        return AutomationEffectClass::Records;
    }

    public function sentence(): string
    {
        return 'vidyalms.automation.sentence.tag';
    }

    public function supportedParamsVersions(): array
    {
        return [1];
    }

    public function validateParams(array $params, PayloadValidationContext $context): array
    {
        $tag = $params['tag'] ?? null;

        if (!\is_string($tag) || trim($tag) === '') {
            return ['automation.tag.tag_required'];
        }

        return \strlen($tag) > 100 ? ['automation.tag.tag_too_long'] : [];
    }

    public function previewFor(AutomationActionContext $context): array
    {
        return [
            'subject_id' => $context->subjectId,
            'tag' => $this->tagOf($context),
            'sets' => $this->sets,
        ];
    }

    public function execute(AutomationActionContext $context): ActionOutcome
    {
        $userId = $context->actsForUserId() ?? 0;
        $tag = $this->tagOf($context);

        if ($userId < 1 || $tag === '') {
            return ActionOutcome::failed('tag.params_missing', 'The step named no learner or no tag.', false);
        }

        $existing = $this->database->selectOne(
            Query::from(self::TABLE)->select(['id'])->where('user_id', '=', $userId)->where('tag', '=', $tag),
        );

        if ($this->sets && $existing !== null) {
            return ActionOutcome::suppressed(SuppressionReason::ALREADY_IN_STATE, ['tag' => $tag]);
        }

        if (!$this->sets && $existing === null) {
            return ActionOutcome::suppressed(SuppressionReason::ALREADY_IN_STATE, ['tag' => $tag]);
        }

        if ($context->dryRun) {
            return ActionOutcome::executed([], $this->previewFor($context));
        }

        if (!$this->sets) {
            $this->database->delete(self::TABLE, ['id' => (int) $existing['id']]);

            return ActionOutcome::executed(['tag' => $tag], ['tag' => $tag, 'cleared' => true]);
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        try {
            $id = $this->database->insert(self::TABLE, [
                'user_id' => $userId,
                'tag' => $tag,
                'source' => 'automation',
                'source_ref' => sprintf('automation:%d:%d', $context->ruleId, $context->runId),
                'set_by' => $context->principal->userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolation) {
            // Two runners raced. The unique index is the authority, and the
            // loser reports the state it wanted rather than a failure.
            return ActionOutcome::suppressed(SuppressionReason::ALREADY_IN_STATE, ['tag' => $tag]);
        }

        return ActionOutcome::executed([], ['tag' => $tag, 'learner_tag_id' => $id]);
    }

    private function tagOf(AutomationActionContext $context): string
    {
        $value = $context->param('tag', '');

        return \is_string($value) ? trim($value) : '';
    }
}
